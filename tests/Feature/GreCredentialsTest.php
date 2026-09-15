<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Crear usuario para autenticación
    $this->user = User::factory()->create();
    
    // Crear empresa de prueba
    $this->company = Company::factory()->create([
        'ruc' => '20123456789',
        'razon_social' => 'EMPRESA DE PRUEBA S.A.C.',
        'modo_produccion' => false,
    ]);
});

test('puede obtener credenciales GRE de una empresa', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/companies/{$this->company->id}/gre-credentials");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'company_id',
                'company_name',
                'modo_actual',
                'credenciales_configuradas',
                'credenciales' => [
                    'beta' => [
                        'client_id',
                        'client_secret',
                    ],
                    'produccion' => [
                        'client_id',
                        'client_secret',
                    ],
                    // El usuario SOL es el de la facturación: se informa,
                    // no se edita por ambiente.
                    'sol' => [
                        'ruc',
                        'usuario_sol',
                        'configurado',
                    ],
                ]
            ]
        ]);

    expect($response->json('data.company_id'))->toBe($this->company->id);
    expect($response->json('data.modo_actual'))->toBe('beta');
});

test('puede actualizar credenciales GRE para ambiente beta', function () {
    $credenciales = [
        'environment' => 'beta',
        'client_id' => 'test-nueva-client-id',
        'client_secret' => 'test-nuevo-secret-123456',
    ];

    $response = $this->actingAs($this->user)
        ->putJson("/api/v1/companies/{$this->company->id}/gre-credentials", $credenciales);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Credenciales GRE para beta actualizadas correctamente',
        ]);

    // Verificar que se guardaron en la base de datos
    $this->company->refresh();
    expect($this->company->getGreClientId())->toBe('test-nueva-client-id');
    // El RUC de la guía es el del contribuyente, no uno guardado aparte.
    expect($this->company->getGreRucProveedor())->toBe($this->company->ruc);
});

test('valida credenciales requeridas para ambiente producción', function () {
    // El usuario y clave SOL ya no se piden aquí (son los de facturación),
    // así que lo obligatorio es el par propio de GRE.
    $credenciales = [
        'environment' => 'produccion',
        'client_id' => 'prod-client-id',
        // Falta client_secret
    ];

    $response = $this->actingAs($this->user)
        ->putJson("/api/v1/companies/{$this->company->id}/gre-credentials", $credenciales);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['client_secret']);
});

test('no permite usar credenciales de beta en producción', function () {
    $credenciales = [
        'environment' => 'produccion',
        'client_id' => 'test-85e5b0ae-255c-4891-a595-0b98c65c9854', // Credencial de beta
        'client_secret' => 'prod-secret-123456',
    ];

    $response = $this->actingAs($this->user)
        ->putJson("/api/v1/companies/{$this->company->id}/gre-credentials", $credenciales);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['environment']);
});

test('el test de conexión rechaza credenciales inválidas contra SUNAT', function () {
    // El endpoint consulta a SUNAT de verdad (OAuth2). Con credenciales de
    // relleno la respuesta debe ser un rechazo, nunca un "válido": antes solo
    // comprobaba que los campos no estuvieran vacíos y daba 200 siempre, así
    // que unas credenciales equivocadas se descubrían recién al emitir.
    $this->company->setGreCredentials([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-secret-123456',
    ], 'beta');

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/companies/{$this->company->id}/gre-credentials/test-connection");

    expect($response->status())->not->toBe(200);
    $response->assertJson(['success' => false]);
});

test('el test de conexión avisa si falta el usuario SOL de facturación', function () {
    // La guía usa el usuario SOL de la facturación. Si la empresa no lo tiene
    // configurado, no se puede pedir el token: debe avisarse antes de salir a
    // la red, señalando que el dato faltante es el de facturación.
    $this->company->setGreCredentials([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-secret-123456',
    ], 'beta');
    $this->company->update(['usuario_sol' => '', 'clave_sol' => '']);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/companies/{$this->company->id}/gre-credentials/test-connection");

    $response->assertStatus(400)->assertJson(['success' => false]);
    expect($response->json('message'))->toContain('SOL');
});

test('falla test de conexión sin credenciales configuradas', function () {
    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/companies/{$this->company->id}/gre-credentials/test-connection");

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'message' => 'Las credenciales GRE no están configuradas para esta empresa'
        ]);
});

test('puede limpiar credenciales para un ambiente', function () {
    // Configurar credenciales primero
    $this->company->setGreCredentials([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-secret',
    ], 'beta');

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/companies/{$this->company->id}/gre-credentials/clear", [
            'environment' => 'beta'
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Credenciales GRE para beta han sido limpiadas'
        ]);

    // Verificar que se limpiaron
    $this->company->refresh();
    expect($this->company->getGreClientId())->toBeNull();
});

test('puede copiar credenciales entre ambientes', function () {
    // Configurar credenciales en beta
    $this->company->setGreCredentials([
        'client_id' => 'beta-client-id',
        'client_secret' => 'beta-secret',
    ], 'beta');

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/companies/{$this->company->id}/gre-credentials/copy", [
            'from_environment' => 'beta',
            'to_environment' => 'produccion'
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Credenciales copiadas de beta a produccion'
        ]);

    // Verificar que se copiaron. Las credenciales viven en columnas del
    // modelo (gre_client_id_*), no en company_configurations.
    $this->company->refresh();
    expect($this->company->gre_client_id_produccion)->toBe('beta-client-id');
});

test('no puede copiar credenciales del mismo ambiente', function () {
    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/companies/{$this->company->id}/gre-credentials/copy", [
            'from_environment' => 'beta',
            'to_environment' => 'beta'
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['to_environment']);
});

test('puede obtener valores por defecto para un ambiente', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/gre-credentials/defaults/beta');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'environment',
                'credentials_default' => [
                    'client_id',
                    'client_secret',
                ],
                'description'
            ]
        ]);

    expect($response->json('data.environment'))->toBe('beta');
});

test('rechaza ambiente inválido en valores por defecto', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/gre-credentials/defaults/invalid');

    $response->assertStatus(404); // No coincide con la constraint de ruta
});

test('requiere autenticación para todas las rutas', function () {
    $endpoints = [
        'GET' => "/api/v1/companies/{$this->company->id}/gre-credentials",
        'PUT' => "/api/v1/companies/{$this->company->id}/gre-credentials",
        'POST' => "/api/v1/companies/{$this->company->id}/gre-credentials/test-connection",
        'DELETE' => "/api/v1/companies/{$this->company->id}/gre-credentials/clear",
        'POST' => "/api/v1/companies/{$this->company->id}/gre-credentials/copy",
        'GET' => '/api/v1/gre-credentials/defaults/beta',
    ];

    foreach ($endpoints as $method => $endpoint) {
        $response = match($method) {
            'GET' => $this->getJson($endpoint),
            'PUT' => $this->putJson($endpoint, []),
            'POST' => $this->postJson($endpoint, []),
            'DELETE' => $this->deleteJson($endpoint, []),
        };

        $response->assertStatus(401);
    }
});