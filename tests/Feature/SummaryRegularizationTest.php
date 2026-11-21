<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailySummary;
use App\Services\GreenterService;
use App\Services\SummaryRegularizationService;
use Greenter\Model\Response\CdrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Boletas que nunca llegaron a SUNAT (caso PUNTO FIJO) se informan por resumen
// diario: un resumen por fecha de emisión, todos generados hoy.

function signedBoleta(Branch $branch, int $n, string $fecha, array $extra = []): Boleta
{
    $client = Client::factory()->create(['company_id' => $branch->company_id]);

    return Boleta::create(array_merge([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'serie' => 'B001',
        'correlativo' => str_pad((string) $n, 8, '0', STR_PAD_LEFT),
        'numero_completo' => 'B001-' . str_pad((string) $n, 8, '0', STR_PAD_LEFT),
        'fecha_emision' => $fecha,
        'mto_oper_gravadas' => 8.47,
        'mto_igv' => 1.53,
        'mto_imp_venta' => 10.00,
        'detalles' => [],
        'xml_path' => "boletas/xml/x/{$n}.xml",
        'codigo_hash' => 'hash' . $n,
        'estado_sunat' => 'PENDIENTE',
    ], $extra));
}

function regularization(?GreenterService $greenter = null): SummaryRegularizationService
{
    if (!$greenter) {
        return app(SummaryRegularizationService::class);
    }

    return new class($greenter) extends SummaryRegularizationService {
        public function __construct(private GreenterService $fake)
        {
            parent::__construct(
                app(\App\Services\DocumentService::class),
                app(\App\Services\StorageService::class),
                app(\App\Services\FileService::class),
            );
        }

        protected function greenter(Company $company): GreenterService
        {
            return $this->fake;
        }
    };
}

function sunatStatus(array $result): GreenterService
{
    $mock = Mockery::mock(GreenterService::class);
    $mock->shouldReceive('checkSummaryStatus')->andReturn($result + ['cdr_zip' => null, 'error' => null]);

    return $mock;
}

function cdr(string $code, array $notes = []): CdrResponse
{
    return (new CdrResponse())->setCode($code)->setDescription("Código {$code}")->setNotes($notes);
}

beforeEach(function () {
    $this->company = Company::factory()->create(['modo_produccion' => true, 'cpe_provider' => 'greenter']);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
});

test('varios resúmenes generados hoy no repiten nombre aunque sean de fechas distintas', function () {
    $a = signedBoleta($this->branch, 16, '2026-09-01');
    $b = signedBoleta($this->branch, 17, '2026-09-02');

    $first = regularization()->create($this->company, [$a->id]);
    $second = regularization()->create($this->company, [$b->id]);

    $hoy = now()->format('Ymd');
    expect($first->numero_completo)->toBe("RC-{$hoy}-001")
        ->and($second->numero_completo)->toBe("RC-{$hoy}-002")
        ->and($first->fecha_resumen->toDateString())->toBe('2026-09-01')
        ->and($second->fecha_resumen->toDateString())->toBe('2026-09-02');
    expect($a->fresh()->daily_summary_id)->toBe($first->id);
});

test('el resumen lleva los importes con IGV de cada boleta como adición', function () {
    $boleta = signedBoleta($this->branch, 16, '2026-09-01');

    $line = regularization()->create($this->company, [$boleta->id])->detalles[0];

    expect($line['serie_numero'])->toBe('B001-00000016')
        ->and($line['estado'])->toBe('1')
        ->and((float) $line['total'])->toBe(10.0)
        ->and((float) $line['mto_igv'])->toBe(1.53);
});

test('rechaza boletas de fechas distintas, sin firma propia, de otra empresa o ya en un resumen vigente', function () {
    $a = signedBoleta($this->branch, 16, '2026-09-01');
    $b = signedBoleta($this->branch, 17, '2026-09-02');
    expect(fn () => regularization()->create($this->company, [$a->id, $b->id]))
        ->toThrow(Exception::class, 'misma fecha');

    $unsigned = signedBoleta($this->branch, 18, '2026-09-01', ['xml_path' => null, 'codigo_hash' => null]);
    expect(fn () => regularization()->create($this->company, [$unsigned->id]))
        ->toThrow(Exception::class, 'no está firmada');

    $other = Company::factory()->create(['modo_produccion' => true]);
    expect(fn () => regularization()->create($other, [$a->id]))
        ->toThrow(Exception::class, 'no es de esta empresa');

    regularization()->create($this->company, [$a->id]);
    expect(fn () => regularization()->create($this->company, [$a->id]))
        ->toThrow(Exception::class, 'ya está en el resumen');
});

test('una empresa en beta no informa nada', function () {
    $this->company->update(['modo_produccion' => false]);
    $boleta = signedBoleta($this->branch, 16, '2026-09-01');

    expect(fn () => regularization()->create($this->company, [$boleta->id]))
        ->toThrow(Exception::class, 'no está en producción');
});

test('en proceso (98) no es rechazo: nada cambia', function () {
    $boleta = signedBoleta($this->branch, 16, '2026-09-01');
    $summary = regularization()->create($this->company, [$boleta->id]);
    $summary->update(['ticket' => 'T-1', 'estado_sunat' => 'PROCESANDO']);

    $result = regularization(sunatStatus(['success' => false, 'code' => '98', 'cdr_response' => null]))
        ->status($summary->fresh());

    expect($result['state'])->toBe('PROCESSING')
        ->and($summary->fresh()->estado_sunat)->toBe('PROCESANDO')
        ->and($boleta->fresh()->daily_summary_id)->toBe($summary->id);
});

test('aceptado (con observación 4443) deja las boletas aceptadas', function () {
    $boleta = signedBoleta($this->branch, 16, '2026-09-01');
    $summary = regularization()->create($this->company, [$boleta->id]);
    $summary->update(['ticket' => 'T-1', 'estado_sunat' => 'PROCESANDO']);
    $notes = ['4443 - El comprobante fue informado fuera del plazo establecido'];

    $result = regularization(sunatStatus(['success' => true, 'code' => '0', 'cdr_response' => cdr('0', $notes)]))
        ->status($summary->fresh());

    expect($result['state'])->toBe('ACCEPTED')
        ->and($result['notes'])->toBe($notes)
        ->and($summary->fresh()->estado_sunat)->toBe('ACEPTADO')
        ->and($boleta->fresh()->estado_sunat)->toBe('ACEPTADO');
});

test('procesado con errores (99) es rechazo y libera las boletas', function () {
    $boleta = signedBoleta($this->branch, 16, '2026-09-01');
    $summary = regularization()->create($this->company, [$boleta->id]);
    $summary->update(['ticket' => 'T-1', 'estado_sunat' => 'PROCESANDO']);

    $result = regularization(sunatStatus(['success' => true, 'code' => '99', 'cdr_response' => cdr('2223')]))
        ->status($summary->fresh());

    expect($result['state'])->toBe('REJECTED')
        ->and($summary->fresh()->estado_sunat)->toBe('RECHAZADO')
        ->and($boleta->fresh()->daily_summary_id)->toBeNull()
        ->and($boleta->fresh()->estado_sunat)->toBe('PENDIENTE');
});
