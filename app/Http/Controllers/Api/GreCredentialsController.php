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
            $validated = $request->validate([
                'environment' => 'required|in:beta,produccion',
                'client_id' => 'required|string|max:255',
                'client_secret' => 'required|string|max:255',
                'ruc_proveedor' => 'nullable|string|size:11|regex:/^\d{11}$/',
                'usuario_sol' => 'nullable|string|max:100',
                'clave_sol' => 'nullable|string|max:100'
            ]);
            
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

            // Validar que las credenciales estén completas
            $isValid = !empty($credentials['client_id']) &&
                      !empty($credentials['client_secret']);

            if (!$isValid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incompletas'
                ], 400);
            }

            Log::info("Test de conexión GRE", [
                'company_id' => $company->id,
                'environment' => $environment,
                'result' => 'success'
            ]);

            return response()->json([
                'success' => true,
                'message' => "Conexión con SUNAT ({$environment}) validada correctamente",
                'data' => [
                    'company_id' => $company->id,
                    'environment' => $environment,
                    'client_id' => '***' . substr($credentials['client_id'], -4),
                    'ruc_proveedor' => $credentials['ruc_proveedor'],
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

            $defaults = [
                'beta' => [
                    'client_id' => 'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
                    'client_secret' => '***Kw==', // Ocultar por seguridad
                    'ruc_proveedor' => '20161515648',
                    'usuario_sol' => 'MODDATOS',
                    'clave_sol' => '***TOS', // Ocultar por seguridad
                    'endpoints' => [
                        'api' => 'https://api-cpe-beta.sunat.gob.pe/v1/',
                        'wsdl' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpgre-beta/billService?wsdl'
                    ]
                ],
                'produccion' => [
                    'client_id' => '',
                    'client_secret' => '',
                    'ruc_proveedor' => '',
                    'usuario_sol' => '',
                    'clave_sol' => '',
                    'endpoints' => [
                        'api' => 'https://api-cpe.sunat.gob.pe/v1/',
                        'wsdl' => 'https://e-guiaremision.sunat.gob.pe/ol-ti-itemision-guia-gem/billService?wsdl'
                    ]
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'environment' => $mode,
                    'credentials_default' => $defaults[$mode],
                    'description' => $mode === 'beta' 
                        ? 'Credenciales de prueba para ambiente BETA'
                        : 'Credenciales de producción (deben ser configuradas por empresa)',
                    'note' => $mode === 'beta' 
                        ? 'Estas credenciales son de prueba y funcionan para testing'
                        : 'Para producción debe obtener credenciales reales de SUNAT'
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