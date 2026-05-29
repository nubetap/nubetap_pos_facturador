<?php

use App\Models\Boleta;
use App\Models\Company;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regresión: boleta con descuento global (cod_tipo "02") debe persistir el
 * descuento en datos_adicionales._descuentos_globales para que prepareDocumentData
 * lo recupere al firmar el XML.
 *
 * Sin esto, el XML se firma sin cac:AllowanceCharge y las líneas declaran el
 * precio sin descuento (observaciones SUNAT 4299 y 4309).
 */
test('createBoleta persiste descuentos globales en datos_adicionales', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $data = [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'serie' => 'B001',
        'correlativo' => 1,
        'fecha_emision' => '2026-05-29',
        'moneda' => 'PEN',
        'client' => [
            'tipo_documento' => '0',
            'numero_documento' => '00000000',
            'razon_social' => 'CLIENTES VARIOS',
        ],
        'detalles' => [
            [
                'codigo' => 'ITEM001',
                'descripcion' => 'PIQUEO - CHORIPAPA',
                'unidad' => 'NIU',
                'cantidad' => 1,
                'mto_valor_unitario' => 18.0995475113,
                'porcentaje_igv' => 10.5,
                'tip_afe_igv' => '10',
            ],
        ],
        'descuentos' => [
            [
                'cod_tipo' => '02',
                'monto_base' => 18.10,
                'factor' => 0.24972,
                'monto' => 4.52,
            ],
        ],
        'total_esperado' => 15.00,
    ];

    $response = $this->postJson('/api/v1/boletas', $data);

    $response->assertStatus(201);

    $boleta = Boleta::first();
    expect($boleta)->not->toBeNull();
    expect($boleta->datos_adicionales)->not->toBeNull();
    expect($boleta->datos_adicionales['_descuentos_globales'])
        ->toEqual($data['descuentos']);
});

test('createBoleta sin descuento global deja _descuentos_globales en null', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $data = [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'serie' => 'B001',
        'correlativo' => 1,
        'fecha_emision' => '2026-05-29',
        'moneda' => 'PEN',
        'client' => [
            'tipo_documento' => '0',
            'numero_documento' => '00000000',
            'razon_social' => 'CLIENTES VARIOS',
        ],
        'detalles' => [
            [
                'codigo' => 'ITEM001',
                'descripcion' => 'Producto',
                'unidad' => 'NIU',
                'cantidad' => 1,
                'mto_valor_unitario' => 18.0995475113,
                'porcentaje_igv' => 10.5,
                'tip_afe_igv' => '10',
            ],
        ],
    ];

    $response = $this->postJson('/api/v1/boletas', $data);

    $response->assertStatus(201);

    $boleta = Boleta::first();
    expect($boleta->datos_adicionales)->not->toBeNull();
    expect($boleta->datos_adicionales['_descuentos_globales'])->toBeNull();
    expect($boleta->datos_adicionales['_anticipos'])->toBeNull();
});

/**
 * Regresión SUNAT 4287: boleta con descuento por línea (cod_tipo "00") debe
 * declarar mto_precio_unitario CON descuento aplicado, no el precio original.
 * Antes del fix: mto_precio_unitario = mto_valor_unitario × (1 + IGV) → precio
 * inflado en AlternativeConditionPrice → observación SUNAT.
 */
test('createBoleta con descuento por linea calcula mto_precio_unitario con descuento', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    // Rexona S/20 con descuento por línea S/10 → cliente paga S/10 con IGV 18%.
    $data = [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'serie' => 'B001',
        'correlativo' => 1,
        'fecha_emision' => '2026-05-29',
        'moneda' => 'PEN',
        'client' => [
            'tipo_documento' => '1',
            'numero_documento' => '12345678',
            'razon_social' => 'CLIENTE TEST',
        ],
        'detalles' => [
            [
                'codigo' => 'ITEM001',
                'descripcion' => 'Rexona Talco',
                'unidad' => 'NIU',
                'cantidad' => 1,
                'mto_valor_unitario' => 16.9491525424,
                'porcentaje_igv' => 18,
                'tip_afe_igv' => '10',
                'descuentos' => [
                    [
                        'cod_tipo' => '00',
                        'monto_base' => 16.95,
                        'factor' => 0.5,
                        'monto' => 8.47,
                    ],
                ],
            ],
        ],
        'total_esperado' => 10.00,
    ];

    $this->postJson('/api/v1/boletas', $data)->assertStatus(201);

    $boleta = Boleta::first();
    $detalle = $boleta->detalles[0];

    // SUNAT 4287 requiere: AlternativeConditionPrice = (valor_venta + igv) / cantidad.
    // mto_valor_venta después del descuento = 16.95 - 8.47 = 8.48.
    // IGV = 8.48 × 0.18 = 1.5264 → 1.53.
    // mto_precio_unitario esperado = (8.48 + 1.53) / 1 = 10.01 → 10.00 (HALF_DOWN).
    expect((float) $detalle['mto_valor_venta'])->toBe(8.48);
    expect((float) $detalle['igv'])->toBe(1.53);
    expect((float) $detalle['mto_precio_unitario'])->toBe(10.00);
});

test('createBoleta sin descuento mantiene mto_precio_unitario calculado desde valor_unitario', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    // Laive S/10 sin descuento, IGV 18%.
    $data = [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'serie' => 'B001',
        'correlativo' => 2,
        'fecha_emision' => '2026-05-29',
        'moneda' => 'PEN',
        'client' => [
            'tipo_documento' => '1',
            'numero_documento' => '12345678',
            'razon_social' => 'CLIENTE TEST',
        ],
        'detalles' => [
            [
                'codigo' => 'ITEM001',
                'descripcion' => 'Laive Queso',
                'unidad' => 'NIU',
                'cantidad' => 1,
                'mto_valor_unitario' => 8.4745762712,
                'porcentaje_igv' => 18,
                'tip_afe_igv' => '10',
            ],
        ],
        'total_esperado' => 10.00,
    ];

    $this->postJson('/api/v1/boletas', $data)->assertStatus(201);

    $boleta = Boleta::first();
    $detalle = $boleta->detalles[0];

    // Sin descuento, comportamiento idéntico al previo (no introducir regresión).
    expect((float) $detalle['mto_precio_unitario'])->toBe(10.00);
});

/**
 * Boleta con descuento global debe producir cabecera correcta:
 * - mto_oper_gravadas = base con descuento aplicado
 * - mto_igv recalculado sobre base reducida
 */
test('createBoleta con descuento global calcula cabecera correctamente', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $data = [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'serie' => 'B001',
        'correlativo' => 1,
        'fecha_emision' => '2026-05-29',
        'moneda' => 'PEN',
        'client' => [
            'tipo_documento' => '0',
            'numero_documento' => '00000000',
            'razon_social' => 'CLIENTES VARIOS',
        ],
        'detalles' => [
            [
                'codigo' => 'ITEM001',
                'descripcion' => 'PIQUEO',
                'unidad' => 'NIU',
                'cantidad' => 1,
                'mto_valor_unitario' => 18.0995475113,
                'porcentaje_igv' => 10.5,
                'tip_afe_igv' => '10',
            ],
        ],
        'descuentos' => [
            [
                'cod_tipo' => '02',
                'monto_base' => 18.10,
                'factor' => 0.24972,
                'monto' => 4.52,
            ],
        ],
        'total_esperado' => 15.00,
    ];

    $this->postJson('/api/v1/boletas', $data)->assertStatus(201);

    $boleta = Boleta::first();
    expect((float) $boleta->mto_oper_gravadas)->toBe(13.58);
    expect((float) $boleta->mto_igv)->toBe(1.43);
    expect((float) $boleta->mto_imp_venta)->toBe(15.00);
});
