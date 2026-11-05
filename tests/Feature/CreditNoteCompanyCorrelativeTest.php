<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// SUNAT exige que RUC + tipo + serie + número sea único; la serie no se ata a un
// local. Dos sucursales con la misma serie de nota deben compartir la numeración.

function creditNoteData(Branch $branch, array $overrides = []): array
{
    return array_merge([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'serie' => 'BC01',
        'fecha_emision' => '2026-11-05',
        'moneda' => 'PEN',
        'tipo_doc_afectado' => '03',
        'num_doc_afectado' => 'B003-00001452',
        'cod_motivo' => '01',
        'des_motivo' => 'ANULACION DE LA OPERACION',
        'client' => [
            'tipo_documento' => '1',
            'numero_documento' => '00000000',
            'razon_social' => 'CLIENTE VARIOS',
        ],
        'detalles' => [[
            'codigo' => 'P001',
            'descripcion' => 'Producto',
            'unidad' => 'NIU',
            'cantidad' => 1,
            'mto_valor_unitario' => 5.93,
            'porcentaje_igv' => 18.00,
            'tip_afe_igv' => '10',
        ]],
    ], $overrides);
}

function emitCreditNote(Branch $branch, array $overrides = []): CreditNote
{
    return app(DocumentService::class)->createCreditNote(creditNoteData($branch, $overrides));
}

test('la primera nota de una segunda sucursal continúa la serie de la empresa', function () {
    $company = Company::factory()->create();
    $eucaliptos = Branch::factory()->create(['company_id' => $company->id]);
    $pescador = Branch::factory()->create(['company_id' => $company->id, 'codigo' => '0001']);

    foreach (range(1, 3) as $_) {
        emitCreditNote($eucaliptos);
    }

    // Antes: el contador de Pescador arrancaba en 1 → BC01-000001 duplicado → 500.
    $note = emitCreditNote($pescador);

    expect($note->numero_completo)->toBe('BC01-000004');
    expect($note->branch_id)->toBe($pescador->id);
});

test('una sola sucursal numera igual que antes', function () {
    $branch = Branch::factory()->create();

    $numbers = collect(range(1, 3))->map(fn () => emitCreditNote($branch)->numero_completo);

    expect($numbers->all())->toBe(['BC01-000001', 'BC01-000002', 'BC01-000003']);
});

test('sucursales alternadas no repiten ni saltan números', function () {
    $company = Company::factory()->create();
    $a = Branch::factory()->create(['company_id' => $company->id]);
    $b = Branch::factory()->create(['company_id' => $company->id, 'codigo' => '0001']);

    $numbers = [
        emitCreditNote($a)->correlativo,
        emitCreditNote($b)->correlativo,
        emitCreditNote($a)->correlativo,
        emitCreditNote($b)->correlativo,
    ];

    expect($numbers)->toBe(['000001', '000002', '000003', '000004']);
    expect(CreditNote::where('company_id', $company->id)->count())->toBe(4);
});

test('compara por número y no por texto aunque el relleno sea distinto', function () {
    $company = Company::factory()->create();
    $a = Branch::factory()->create(['company_id' => $company->id]);
    $b = Branch::factory()->create(['company_id' => $company->id, 'codigo' => '0001']);

    // Correlativo enviado desde fuera: se guarda con 8 dígitos.
    emitCreditNote($a, ['correlativo' => 10]);
    emitCreditNote($a, ['correlativo' => 9]);

    expect(emitCreditNote($b)->correlativo)->toBe('000011');
});

test('cada serie, tipo y empresa lleva su propia numeración', function () {
    $company = Company::factory()->create();
    $a = Branch::factory()->create(['company_id' => $company->id]);
    $b = Branch::factory()->create(['company_id' => $company->id, 'codigo' => '0001']);
    $otraEmpresa = Branch::factory()->create();

    emitCreditNote($a);
    emitCreditNote($a);
    emitCreditNote($otraEmpresa);
    emitCreditNote($otraEmpresa);
    emitCreditNote($otraEmpresa);

    $factura = emitCreditNote($b, [
        'serie' => 'FC01',
        'tipo_doc_afectado' => '01',
        'num_doc_afectado' => 'F001-00000001',
        'client' => [
            'tipo_documento' => '6',
            'numero_documento' => '20123456789',
            'razon_social' => 'EMPRESA TEST SAC',
        ],
    ]);

    expect($factura->numero_completo)->toBe('FC01-000001');
    expect(emitCreditNote($b)->numero_completo)->toBe('BC01-000003');
    expect(emitCreditNote($otraEmpresa)->numero_completo)->toBe('BC01-000004');
});

test('un correlativo enviado desde fuera que ya existe devuelve la misma nota', function () {
    $branch = Branch::factory()->create();

    $first = emitCreditNote($branch, ['correlativo' => 5]);
    $again = emitCreditNote($branch, ['correlativo' => 5]);

    expect($again->id)->toBe($first->id);
    expect(CreditNote::count())->toBe(1);
});
