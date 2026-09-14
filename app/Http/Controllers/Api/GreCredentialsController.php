<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGreCredentialsRequest;
use App\Http\Requests\GreEnvironmentRequest;
use App\Http\Requests\CopyGreCredentialsRequest;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

class GreCredentialsController extends Controller
{
    /**
     * Obtener credenciales GRE de una empresa
     */
    public function show(Company $company): JsonResponse
    {
        try {
            $currentCredentials = $company->getGreCredentials();
            
            $credentials = [
                'beta' => [
                    'client_id' => $company->gre_client_id_beta ? '***' . substr($company->gre_client_id_beta, -4) : null,
                    'client_secret' => $company->gre_client_secret_beta ? '***' . substr($company->gre_client_secret_beta, -4) : null,
                    'ruc_proveedor' => $company->gre_ruc_proveedor,
                    'usuario_sol' => $company->gre_usuario_sol,
                    'clave_sol' => $company->gre_clave_sol ? '***' . substr($company->gre_clave_sol, -2) : null,
                ],
                'produccion' => [
                    'client_id' => $company->gre_client_id_produccion ? '***' . substr($company->gre_client_id_produccion, -4) : null,
                    'client_secret' => $company->gre_client_secret_produccion ? '***' . substr($company->gre_client_secret_produccion, -4) : null,
                    'ruc_proveedor' => $company->gre_ruc_proveedor,
                    'usuario_sol' => $company->gre_usuario_sol,
                    'clave_sol' => $company->gre_clave_sol ? '***' . substr($company->gre_clave_sol, -2) : null,
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'company_id' => $company->id,
                    'company_name' => $company->razon_social,
                    'modo_actual' => $company->modo_produccion ? 'produccion' : 'beta',
                    'credenciales_configuradas' => $company->hasGreCredentials(),
                    'credenciales' => $credentials,
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al obtener credenciales GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener credenciales GRE: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar credenciales GRE para un ambiente específico
     */
    public function update(Request $request, Company $company): JsonResponse
    {
        try {
            $esProduccion = $request->input('environment') === 'produccion';

            // En producción los cinco datos son obligatorios: sin RUC,
            // usuario y clave SOL no se puede obtener el token OAuth2 y la
            // guía fallaría recién al emitirse. En beta se permiten parciales
            // para poder ir cargando la configuración.
            $reglaSol = $esProduccion ? 'required' : 'nullable';

            $validated = $request->validate([
                'environment' => 'required|in:beta,produccion',
                'client_id' => 'required|string|max:255',
                'client_secret' => 'required|string|max:255',
                'ruc_proveedor' => $reglaSol . '|string|size:11|regex:/^\d{11}$/',
                'usuario_sol' => $reglaSol . '|string|max:100',
                'clave_sol' => $reglaSol . '|string|max:100',
            ]);

            // Las credenciales de demo de SUNAT llevan el prefijo "test-".
            // Guardarlas como producción deja al cliente creyendo que puede
            // emitir cuando en realidad apuntaría al ambiente de pruebas.
            if ($esProduccion && str_starts_with($validated['client_id'], 'test-')) {
                throw ValidationException::withMessages([
                    'environment' => 'El client_id corresponde al ambiente de pruebas '
                        . '(prefijo "test-"). Genere credenciales de producción en su Clave SOL.',
                ]);
            }

            $environment = $validated['environment'];
            
            // Preparar credenciales sin el campo environment
            $credentials = [
                'client_id' => $validated['client_id'],
                'client_secret' => $validated['client_secret'],
                'ruc_proveedor' => $validated['ruc_proveedor'] ?? null,
                'usuario_sol' => $validated['usuario_sol'] ?? null,
                'clave_sol' => $validated['clave_sol'] ?? null,
            ];

            // Configurar credenciales usando el nuevo método
            $company->setGreCredentials($credentials, $environment);

            Log::info("Credenciales GRE actualizadas", [
                'company_id' => $company->id,
                'environment' => $environment,
                'client_id' => '***' . substr($credentials['client_id'], -4),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Credenciales GRE para {$environment} actualizadas correctamente",
                'data' => [
                    'company_id' => $company->id,
                    'environment' => $environment,
                    'credenciales_configuradas' => $company->fresh()->hasGreCredentials(),
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error("Error al actualizar credenciales GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar credenciales GRE: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar conexión con SUNAT usando las credenciales configuradas
     */
    public function testConnection(Company $company): JsonResponse
    {
        try {
            if (!$company->hasGreCredentials()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Las credenciales GRE no están configuradas para esta empresa'
                ], 400);
            }

            $credentials = $company->getGreCredentials();
            $environment = $company->modo_produccion ? 'produccion' : 'beta';

            // Completitud: los cinco datos son necesarios para el OAuth2.
            $faltantes = [];
            foreach ([
                'client_id' => 'Client ID',
                'client_secret' => 'Client Secret',
                'ruc_proveedor' => 'RUC',
                'usuario_sol' => 'Usuario SOL',
                'clave_sol' => 'Clave SOL',
            ] as $campo => $etiqueta) {
                if (empty($credentials[$campo])) {
                    $faltantes[] = $etiqueta;
                }
            }

            if (!empty($faltantes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Faltan datos para conectar con SUNAT: ' . implode(', ', $faltantes),
                ], 400);
            }

            // Prueba REAL contra SUNAT: se pide un token OAuth2. Antes este
            // endpoint solo comprobaba que los campos no estuvieran vacíos y
            // respondía "validada correctamente", de modo que credenciales
            // equivocadas pasaban el test y solo fallaban al emitir.
            // SUNAT expone un solo host de seguridad; lo que cambia entre
            // ambientes es el endpoint de envío (api-cpe), no el de token.
            $authHost = $company->getGreAuthEndpoint();

            $authApi = new \Greenter\Sunat\GRE\Api\AuthApi(
                new \GuzzleHttp\Client(['timeout' => 20]),
                (new \Greenter\Sunat\GRE\Configuration())->setHost($authHost)
            );

            try {
                $token = $authApi->getToken(
                    'password',
                    'https://api-cpe.sunat.gob.pe',
                    $credentials['client_id'],
                    $credentials['client_secret'],
                    $credentials['ruc_proveedor'] . $credentials['usuario_sol'],
                    $credentials['clave_sol']
                );
            } catch (\Greenter\Sunat\GRE\ApiException $e) {
                Log::warning('Test de conexión GRE rechazado por SUNAT', [
                    'company_id' => $company->id,
                    'environment' => $environment,
                    'http_code' => $e->getCode(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $e->getCode() === 401
                        ? 'SUNAT rechazó las credenciales. Revise client_id, client_secret, '
                          . 'usuario y clave SOL, y que la aplicación tenga habilitado el '
                          . 'alcance "GRE Envío de Comprobantes".'
                        : 'SUNAT respondió con error ' . $e->getCode() . ' al validar las credenciales.',
                    'data' => ['environment' => $environment],
                ], 422);
            }

            $accessToken = $token->getAccessToken();
            if (empty($accessToken)) {
                return response()->json([
                    'success' => false,
                    'message' => 'SUNAT no devolvió un token de acceso.',
                ], 422);
            }

            Log::info("Test de conexión GRE", [
                'company_id' => $company->id,
                'environment' => $environment,
                'result' => 'success',
                'expires_in' => $token->getExpiresIn(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Conexión con SUNAT ({$environment}) validada correctamente",
                'data' => [
                    'company_id' => $company->id,
                    'environment' => $environment,
                    'client_id' => '***' . substr($credentials['client_id'], -4),
                    'ruc_proveedor' => $credentials['ruc_proveedor'],
                    'token_expira_en' => $token->getExpiresIn(),
                    'timestamp' => now()->toISOString()
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error en test de conexión GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al probar conexión: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener valores por defecto para un ambiente
     */
    public function getDefaults(string $mode): JsonResponse
    {
        try {
            if (!in_array($mode, ['beta', 'produccion'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Modo inválido. Debe ser beta o produccion'
                ], 400);
            }

            // Solo endpoints. Antes se devolvían las credenciales de demo de
            // SUNAT (RUC 20161515648 / MODDATOS) como "valores por defecto"
            // de beta: cargarlas hacía que las guías se firmaran con la
            // identidad del contribuyente de pruebas. Cada empresa genera las
            // suyas en su Clave SOL, no hay defaults posibles.
            $defaults = [
                'beta' => [
                    'client_id' => '',
                    'client_secret' => '',
                    'ruc_proveedor' => '',
                    'usuario_sol' => '',
                    'clave_sol' => '',
                    'endpoints' => [
                        'auth' => \App\Models\Company::GRE_AUTH_ENDPOINT,
                        'api' => \App\Models\Company::GRE_API_ENDPOINT_BETA,
                    ],
                ],
                'produccion' => [
                    'client_id' => '',
                    'client_secret' => '',
                    'ruc_proveedor' => '',
                    'usuario_sol' => '',
                    'clave_sol' => '',
                    'endpoints' => [
                        'auth' => \App\Models\Company::GRE_AUTH_ENDPOINT,
                        'api' => \App\Models\Company::GRE_API_ENDPOINT_PRODUCCION,
                    ],
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'environment' => $mode,
                    'credentials_default' => $defaults[$mode],
                    'description' => $mode === 'beta'
                        ? 'Endpoints del ambiente de pruebas (beta) de SUNAT'
                        : 'Endpoints del ambiente de producción de SUNAT',
                    'note' => 'Las credenciales las genera cada empresa en su Clave SOL: '
                        . 'Credenciales de API SUNAT, con el alcance "GRE Envío de Comprobantes".'
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener valores por defecto: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Limpiar credenciales para un ambiente específico
     */
    public function clear(Request $request, Company $company): JsonResponse
    {
        try {
            $validated = $request->validate([
                'environment' => 'required|in:beta,produccion'
            ]);
            
            $environment = $validated['environment'];

            // Limpiar credenciales usando el nuevo método
            $company->clearGreCredentials($environment);

            Log::info("Credenciales GRE limpiadas", [
                'company_id' => $company->id,
                'environment' => $environment,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Credenciales GRE para {$environment} han sido limpiadas",
                'data' => [
                    'company_id' => $company->id,
                    'environment' => $environment,
                    'credenciales_configuradas' => $company->fresh()->hasGreCredentials(),
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al limpiar credenciales GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al limpiar credenciales: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Copiar credenciales de un ambiente a otro
     */
    public function copy(Request $request, Company $company): JsonResponse
    {
        try {
            $validated = $request->validate([
                'from_environment' => 'required|in:beta,produccion',
                'to_environment' => 'required|in:beta,produccion|different:from_environment'
            ]);
            
            $fromEnvironment = $validated['from_environment'];
            $toEnvironment = $validated['to_environment'];

            $copied = $company->copyGreCredentials($fromEnvironment, $toEnvironment);

            if (!$copied) {
                return response()->json([
                    'success' => false,
                    'message' => "No hay credenciales configuradas en el ambiente {$fromEnvironment}"
                ], 400);
            }

            Log::info("Credenciales GRE copiadas", [
                'company_id' => $company->id,
                'from_environment' => $fromEnvironment,
                'to_environment' => $toEnvironment,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Credenciales copiadas de {$fromEnvironment} a {$toEnvironment}",
                'data' => [
                    'company_id' => $company->id,
                    'from_environment' => $fromEnvironment,
                    'to_environment' => $toEnvironment,
                    'credenciales_configuradas' => $company->fresh()->hasGreCredentials(),
                ]
            ]);

        } catch (ValidationException $e) {
            // Sin este catch la validación caía en el genérico y salía como
            // 500, ocultando qué campo estaba mal.
            throw $e;
        } catch (Exception $e) {
            Log::error("Error al copiar credenciales GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al copiar credenciales: ' . $e->getMessage()
            ], 500);
        }
    }
}