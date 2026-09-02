<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// /api/v1 lleva auth:sanctum; mismo patrón que GreCredentialsTest.
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * Regresión: el total cobrado en caja (total_esperado) debe sobrevivir hasta
 * la firma del XML.
 *
 * calculateTotals ya corregía mto_imp_venta con total_esperado en el POST, pero
 * prepareDocumentData recalcula los totales antes de firmar leyendo
 * total_esperado del modelo, y esa columna no existe. Resultado en producción:
 * XML con PayableRoundingAmount=0.01 y PayableAmount=44.99 para una venta de
 * 45.00 (la corrección se calculaba y luego se perdía).
 *
 * El fix persiste total_esperado en datos_adicionales._total_esperado y lo
 * recupera al firmar, igual que ya se hace con _descuentos_globales.
 *
 * Importante: no se toca ninguna fórmula de IGV. porcentaje_igv sigue viniendo
 * por línea (18, 10.5, IVAP...) y el desglose por línea no cambia.
 */

function boletaPayload(Company $company, Branch $branch, array $overrides = []): array
{
    return array_merge([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'serie' => 'B001',
        'correlativo' => 1,
        'fecha_emision' => '2026-08-15',
        'moneda' => 'PEN',
        'metodo_envio' => 'individual',
        'client' => [
            'tipo_documento' => '0',
            'numero_documento' => '00000000',
            'razon_social' => 'CLIENTES VARIOS',
        ],
        'detalles' => [
            [
                'codigo' => 'ITEM001',
                'descripcion' => 'ALITAS - A LA BBQ',
                'unidad' => 'NIU',
                'cantidad' => 1,
                // 45.00 con IGV incluido → base 38.1355932203 (10 decimales,
                // como manda Django). round(38.1355932203,2)=38.14 y
                // round(38.14*0.18,2)=6.87 → 45.01: un centavo de deriva.
                'mto_valor_unitario' => 38.1355932203,
                'porcentaje_igv' => 18,
                'tip_afe_igv' => '10',
            ],
        ],
    ], $overrides);
}

test('createBoleta persiste total_esperado en datos_adicionales', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $data = boletaPayload($company, $branch, ['total_esperado' => 45.00]);

    $this->postJson('/api/v1/boletas', $data)->assertStatus(201);

    $boleta = Boleta::first();
    expect($boleta->datos_adicionales['_total_esperado'])->toEqual(45.0);
    // La corrección del POST ya funcionaba: se conserva tal cual.
    expect((float) $boleta->mto_imp_venta)->toBe(45.00);
    expect((float) $boleta->redondeo)->toBe(-0.01);
});

test('createBoleta sin total_esperado deja _total_esperado en null y no inventa redondeo', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $this->postJson('/api/v1/boletas', boletaPayload($company, $branch))->assertStatus(201);

    $boleta = Boleta::first();
    expect($boleta->datos_adicionales['_total_esperado'])->toBeNull();
    expect((float) $boleta->redondeo)->toBe(0.0);
    expect((float) $boleta->mto_imp_venta)->toBe(45.01);
});

test('prepareDocumentData recupera total_esperado y mantiene mto_imp_venta corregido al firmar', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $this->postJson('/api/v1/boletas', boletaPayload($company, $branch, ['total_esperado' => 45.00]))
        ->assertStatus(201);

    $boleta = Boleta::first();

    // Mismo camino que sendToSunat justo antes de crear el documento Greenter.
    $service = app(DocumentService::class);
    $prepare = new ReflectionMethod($service, 'prepareDocumentData');
    $prepare->setAccessible(true);
    $documentData = $prepare->invoke($service, $boleta, 'boleta');

    // Antes del fix: aquí total_esperado era null, calculateTotals recalculaba
    // sin él y mto_imp_venta volvía a 45.01 mientras redondeo quedaba en -0.01.
    expect($documentData['total_esperado'])->toEqual(45.0);
    expect((float) $documentData['mto_imp_venta'])->toBe(45.00);
    expect((float) $documentData['redondeo'])->toBe(-0.01);
    // TaxInclusiveAmount (subTotal) sigue siendo el cálculo sin corregir:
    // subTotal + redondeo == PayableAmount, que es la semántica SUNAT del campo.
    expect((float) $documentData['sub_total'])->toBe(45.01);
});

test('updateBoleta acepta total_esperado y lo persiste para la firma', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $this->postJson('/api/v1/boletas', boletaPayload($company, $branch))->assertStatus(201);
    $boleta = Boleta::first();
    expect((float) $boleta->mto_imp_venta)->toBe(45.01);

    // Refirma desde Django (force_update) mandando el total cobrado.
    $payload = boletaPayload($company, $branch, [
        'force_update' => true,
        'total_esperado' => 45.00,
    ]);
    unset($payload['company_id'], $payload['branch_id'], $payload['serie'], $payload['correlativo']);

    $this->putJson("/api/v1/boletas/{$boleta->id}", $payload)->assertStatus(200);

    $boleta->refresh();
    expect((float) $boleta->mto_imp_venta)->toBe(45.00);
    expect($boleta->datos_adicionales['_total_esperado'])->toEqual(45.0);
});

test('updateBoleta con datos_adicionales null en BD no falla y conserva descuentos previos', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $this->postJson('/api/v1/boletas', boletaPayload($company, $branch))->assertStatus(201);
    $boleta = Boleta::first();

    // Documentos anteriores al fix pueden tener la columna en null.
    Boleta::whereKey($boleta->id)->update(['datos_adicionales' => null]);

    $payload = boletaPayload($company, $branch, ['force_update' => true, 'total_esperado' => 45.00]);
    unset($payload['company_id'], $payload['branch_id'], $payload['serie'], $payload['correlativo']);

    $this->putJson("/api/v1/boletas/{$boleta->id}", $payload)->assertStatus(200);

    $boleta->refresh();
    expect($boleta->datos_adicionales['_total_esperado'])->toEqual(45.0);
    expect($boleta->datos_adicionales['_descuentos_globales'])->toBeNull();
});

test('el IGV por linea no cambia con total_esperado: 10.5% sigue siendo 10.5%', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    // 20.00 con IGV 10.5% incluido → base 18.0995475113
    $data = boletaPayload($company, $branch, [
        'total_esperado' => 20.00,
        'detalles' => [[
            'codigo' => 'ITEM001',
            'descripcion' => 'PIQUEO - CHORIPAPA',
            'unidad' => 'NIU',
            'cantidad' => 1,
            'mto_valor_unitario' => 18.0995475113,
            'porcentaje_igv' => 10.5,
            'tip_afe_igv' => '10',
        ]],
    ]);

    $this->postJson('/api/v1/boletas', $data)->assertStatus(201);

    $detalle = Boleta::first()->detalles[0];
    // round(18.10 * 0.105, 2) = 1.90: la tasa del negocio se respeta tal cual.
    expect((float) $detalle['mto_valor_venta'])->toBe(18.10);
    expect((float) $detalle['igv'])->toBe(1.90);
    expect((float) Boleta::first()->mto_imp_venta)->toBe(20.00);
});
