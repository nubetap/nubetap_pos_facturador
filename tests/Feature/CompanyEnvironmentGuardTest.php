<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyEnvironmentChange;
use App\Models\CreditNote;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Caso PUNTO FIJO (sept-oct 2026): la empresa pasó a beta sin rastro y 51
// boletas reales se emitieron en el ambiente de pruebas.

beforeEach(function () {
    $this->user = User::factory()->create();
});

function pendingCreditNote(Company $company): CreditNote
{
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    return app(DocumentService::class)->createCreditNote([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'serie' => 'BC01',
        'fecha_emision' => '2026-11-20',
        'moneda' => 'PEN',
        'tipo_doc_afectado' => '03',
        'num_doc_afectado' => 'B001-00000001',
        'cod_motivo' => '01',
        'des_motivo' => 'ANULACION DE LA OPERACION',
        'client' => ['tipo_documento' => '1', 'numero_documento' => '00000000', 'razon_social' => 'CLIENTE VARIOS'],
        'detalles' => [[
            'codigo' => 'P001', 'descripcion' => 'Producto', 'unidad' => 'NIU', 'cantidad' => 1,
            'mto_valor_unitario' => 10, 'porcentaje_igv' => 18.00, 'tip_afe_igv' => '10',
        ]],
    ]);
}

function expectNoSend(): void
{
    test()->mock(DocumentService::class)->shouldNotReceive('sendToSunat');
}

function expectSend(): void
{
    test()->mock(DocumentService::class)
        ->shouldReceive('sendToSunat')->once()
        ->andReturnUsing(fn ($doc) => ['success' => true, 'document' => $doc]);
}

test('una empresa en beta no envía si Nubetap espera producción', function () {
    $company = Company::factory()->create(['modo_produccion' => false]);
    $note = pendingCreditNote($company);
    expectNoSend();

    $this->actingAs($this->user)
        ->withHeaders(['X-Expected-Environment' => 'production'])
        ->postJson("/api/v1/credit-notes/{$note->id}/send-sunat")
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'ENVIRONMENT_MISMATCH')
        ->assertJsonPath('data.mismatches.environment.actual', 'beta');

    expect($note->fresh()->estado_sunat)->toBe('PENDIENTE');
});

test('el proveedor distinto al esperado también se rechaza', function () {
    $company = Company::factory()->create(['modo_produccion' => true]);
    $note = pendingCreditNote($company);
    expectNoSend();

    $this->actingAs($this->user)
        ->withHeaders(['X-Expected-Environment' => 'production', 'X-Expected-Cpe-Provider' => 'validapse'])
        ->postJson("/api/v1/credit-notes/{$note->id}/send-sunat")
        ->assertStatus(409)
        ->assertJsonPath('data.mismatches.cpe_provider.actual', 'greenter');
});

test('si el ambiente coincide, el envío sigue', function () {
    $company = Company::factory()->create(['modo_produccion' => true]);
    $note = pendingCreditNote($company);
    expectSend();

    $this->actingAs($this->user)
        ->withHeaders(['X-Expected-Environment' => 'production', 'X-Expected-Cpe-Provider' => 'greenter'])
        ->postJson("/api/v1/credit-notes/{$note->id}/send-sunat")
        ->assertOk();
});

test('sin cabecera se envía como siempre', function () {
    $company = Company::factory()->create(['modo_produccion' => false]);
    $note = pendingCreditNote($company);
    expectSend();

    $this->actingAs($this->user)
        ->postJson("/api/v1/credit-notes/{$note->id}/send-sunat")
        ->assertOk();
});

test('cada cambio de ambiente queda registrado con quién y desde dónde', function () {
    $company = Company::factory()->create(['modo_produccion' => true]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/companies/{$company->id}/toggle-production", ['modo_produccion' => false])
        ->assertOk();

    $change = CompanyEnvironmentChange::sole();
    expect($change->company_id)->toBe($company->id)
        ->and($change->field)->toBe('modo_produccion')
        ->and($change->old_value)->toBe('true')
        ->and($change->new_value)->toBe('false')
        ->and($change->user_id)->toBe($this->user->id)
        ->and($change->source)->toBe("POST /api/v1/companies/{$company->id}/toggle-production");

    $this->actingAs($this->user)
        ->getJson("/api/v1/environment-changes?company_id={$company->id}")
        ->assertOk()
        ->assertJsonPath('data.0.ruc', $company->ruc)
        ->assertJsonPath('data.0.new_value', 'false');
});

test('cambiar otros datos de la empresa no deja registro de ambiente', function () {
    $company = Company::factory()->create();

    $company->update(['razon_social' => 'OTRA RAZON']);

    expect(CompanyEnvironmentChange::count())->toBe(0);
});
