<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConsultaCpeServiceMejorado;
use App\Models\Invoice;
use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Controlador mejorado de consultas CPE con soporte para ambos métodos:
 * - API OAuth2 moderna de SUNAT
 * - SOAP tradicional con credenciales SOL
 */
class ConsultaCpeControllerMejorado extends Controller
{
    /**
     * Consultar estado de una factura (Método mejorado)
     */
    public function consultarFacturaMejorada(Request $request, $id): JsonResponse
    {
        try {
            $invoice = Invoice::with('company')->findOrFail($id);
            
            $consultaService = new ConsultaCpeServiceMejorado($invoice->company);
            $resultado = $consultaService->consultarComprobante($invoice);

            return response()->json([
                'success' => $resultado['success'],
                'message' => $resultado['message'],
                'data' => [
                    'comprobante' => [
                        'id' => $invoice->id,
                        'tipo_documento' => $invoice->tipo_documento,
                        'serie' => $invoice->serie,
                        'correlativo' => $invoice->correlativo,
                        'fecha_emision' => $invoice->fecha_emision,
                        'monto' => $invoice->mto_imp_venta
                    ],
                    'consulta' => $resultado['data'],
                    'metodo_usado' => $resultado['metodo'] ?? 'unknown'
                ]
            ], $resultado['success'] ? 200 : 422);

        } catch (\Exception $e) {
            Log::error('Error en consulta CPE factura mejorada: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar factura',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Consultar estado de una factura con descarga de CDR
     */
    public function consultarFacturaConCdr(Request $request, $id): JsonResponse
    {
        try {
            $invoice = Invoice::with('company')->findOrFail($id);
            
            $consultaService = new ConsultaCpeServiceMejorado($invoice->company);
            $resultado = $consultaService->consultarConCdr($invoice);

            return response()->json([
                'success' => $resultado['success'],
                'message' => $resultado['message'],
                'data' => [
                    'comprobante' => [
                        'id' => $invoice->id,
                        'tipo_documento' => $invoice->tipo_documento,
                        'serie' => $invoice->serie,
                        'correlativo' => $invoice->correlativo,
                        'fecha_emision' => $invoice->fecha_emision,
                        'monto' => $invoice->mto_imp_venta
                    ],
                    'consulta' => $resultado['data'],
                    'metodo_usado' => $resultado['metodo'] ?? 'unknown',
                    'cdr_guardado' => $resultado['cdr_guardado'] ?? null
                ]
            ], $resultado['success'] ? 200 : 422);

        } catch (\Exception $e) {
            Log::error('Error en consulta CPE factura con CDR: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar factura',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Consultar estado de una boleta (Método mejorado)
     */
    public function consultarBoletaMejorada(Request $request, $id): JsonResponse
    {
        try {
            $boleta = Boleta::with('company')->findOrFail($id);
            
            $consultaService = new ConsultaCpeServiceMejorado($boleta->company);
            $resultado = $consultaService->consultarComprobante($boleta);

            return response()->json([
                'success' => $resultado['success'],
                'message' => $resultado['message'],
                'data' => [
                    'comprobante' => [
                        'id' => $boleta->id,
                        'tipo_documento' => $boleta->tipo_documento,
                        'serie' => $boleta->serie,
                        'correlativo' => $boleta->correlativo,
                        'fecha_emision' => $boleta->fecha_emision,
                        'monto' => $boleta->mto_imp_venta
                    ],
                    'consulta' => $resultado['data'],
                    'metodo_usado' => $resultado['metodo'] ?? 'unknown'
                ]
            ], $resultado['success'] ? 200 : 422);

        } catch (\Exception $e) {
            Log::error('Error en consulta CPE boleta mejorada: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar boleta',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Consultar por datos de documento (sin necesidad de tener el documento en BD)
     */
    public function consultarPorDatos(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'company_id' => 'required|exists:companies,id',
                'ruc_emisor' => 'required|string|size:11',
                'tipo_documento' => 'required|string|size:2',
                'serie' => 'required|string|max:4',
                'correlativo' => 'required|integer|min:1',
                'fecha_emision' => 'required|date',
                'monto_total' => 'required|numeric|min:0',
                'incluir_cdr' => 'sometimes|boolean'
            ]);

            $company = Company::findOrFail($request->company_id);
            
            // Crear objeto mock del documento
            $documento = (object) [
                'id' => null,
                'serie' => $request->serie,
                'correlativo' => $request->correlativo,
                'tipo_documento' => $request->tipo_documento,
                'fecha_emision' => $request->fecha_emision,
                'mto_imp_venta' => $request->monto_total
            ];

            $consultaService = new ConsultaCpeServiceMejorado($company);
            
            if ($request->boolean('incluir_cdr', false)) {
                $resultado = $consultaService->consultarConCdr($documento);
            } else {
                $resultado = $consultaService->consultarComprobante($documento);
            }

            return response()->json([
                'success' => $resultado['success'],
                'message' => $resultado['message'],
                'data' => [
                    'comprobante' => [
                        'ruc_emisor' => $request->ruc_emisor,
                        'tipo_documento' => $request->tipo_documento,
                        'serie' => $request->serie,
                        'correlativo' => $request->correlativo,
                        'fecha_emision' => $request->fecha_emision,
                        'monto' => $request->monto_total
                    ],
                    'consulta' => $resultado['data'],
                    'metodo_usado' => $resultado['metodo'] ?? 'unknown',
                    'cdr_guardado' => $resultado['cdr_guardado'] ?? null
                ]
            ], $resultado['success'] ? 200 : 422);

        } catch (\Exception $e) {
            Log::error('Error en consulta CPE por datos: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar por datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Consulta masiva de documentos pendientes
     */
    public function consultarMasiva(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'company_id' => 'required|exists:companies,id',
                'tipo_documento' => 'sometimes|array',
                'tipo_documento.*' => 'string',
                'fecha_desde' => 'sometimes|date',
                'fecha_hasta' => 'sometimes|date|after_or_equal:fecha_desde',
                'limite' => 'sometimes|integer|min:1|max:100'
            ]);

            $company = Company::findOrFail($request->company_id);
            $limite = $request->input('limite', 50);

            // Obtener documentos para consultar
            $documentos = collect();
            
            // Facturas
            $facturas = Invoice::where('company_id', $company->id)
                ->whereNull('estado_sunat')
                ->when($request->has('tipo_documento'), function ($query) use ($request) {
                    $query->whereIn('tipo_documento', $request->tipo_documento);
                })
                ->when($request->has('fecha_desde'), function ($query) use ($request) {
                    $query->whereDate('fecha_emision', '>=', $request->fecha_desde);
                })
                ->when($request->has('fecha_hasta'), function ($query) use ($request) {
                    $query->whereDate('fecha_emision', '<=', $request->fecha_hasta);
                })
                ->limit($limite)
                ->get();

            $documentos = $documentos->concat($facturas);

            // Boletas
            if ($limite > $documentos->count()) {
                $boletas = Boleta::where('company_id', $company->id)
                    ->whereNull('estado_sunat')
                    ->limit($limite - $documentos->count())
                    ->get();
                
                $documentos = $documentos->concat($boletas);
            }

            if ($documentos->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No hay documentos pendientes de consultar',
                    'data' => [
                        'total_procesados' => 0,
                        'exitosos' => 0,
                        'fallidos' => 0,
                        'resultados' => []
                    ]
                ]);
            }

            $consultaService = new ConsultaCpeServiceMejorado($company);
            $resultado = $consultaService->consultarDocumentosMasivo($documentos->toArray());

            return response()->json([
                'success' => true,
                'message' => 'Consulta masiva completada',
                'data' => $resultado
            ]);

        } catch (\Exception $e) {
            Log::error('Error en consulta masiva: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error en consulta masiva',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar datos de documento antes de consultar
     */
    public function validarDocumento(Request $request, $tipo, $id): JsonResponse
    {
        try {
            $modelo = $this->getModeloDocumento($tipo);
            $documento = $modelo::with('company')->findOrFail($id);
            
            $consultaService = new ConsultaCpeServiceMejorado($documento->company);
            $errores = $consultaService->validarDatosDocumento($documento);

            return response()->json([
                'success' => empty($errores),
                'message' => empty($errores) ? 'Documento válido para consulta' : 'Documento con errores',
                'data' => [
                    'errores' => $errores,
                    'documento' => [
                        'id' => $documento->id,
                        'tipo_documento' => $documento->tipo_documento,
                        'serie' => $documento->serie,
                        'correlativo' => $documento->correlativo,
                        'fecha_emision' => $documento->fecha_emision,
                        'monto' => $documento->mto_imp_venta
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al validar documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Descargar archivo CDR guardado
     */
    public function descargarCdr(Request $request, $companyId, $filename): JsonResponse
    {
        try {
            $company = Company::findOrFail($companyId);
            $path = storage_path("app/cdr/{$company->ruc}/{$filename}");
            
            if (!file_exists($path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Archivo CDR no encontrado'
                ], 404);
            }

            return response()->download($path);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar CDR',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar archivos CDR guardados
     */
    public function listarCdrs(Request $request, $companyId): JsonResponse
    {
        try {
            $company = Company::findOrFail($companyId);
            $path = storage_path("app/cdr/{$company->ruc}");
            
            if (!file_exists($path)) {
                return response()->json([
                    'success' => true,
                    'message' => 'No hay archivos CDR guardados',
                    'data' => []
                ]);
            }

            $archivos = [];
            $files = scandir($path);
            
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                    $fullPath = $path . DIRECTORY_SEPARATOR . $file;
                    $archivos[] = [
                        'filename' => $file,
                        'size' => filesize($fullPath),
                        'fecha_creacion' => date('Y-m-d H:i:s', filemtime($fullPath)),
                        'download_url' => route('cpe.descargar-cdr', [
                            'companyId' => $companyId,
                            'filename' => $file
                        ])
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Lista de CDRs obtenida correctamente',
                'data' => $archivos
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al listar CDRs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener modelo según tipo de documento
     */
    private function getModeloDocumento(string $tipo): string
    {
        return match ($tipo) {
            'invoice', 'factura' => Invoice::class,
            'boleta' => Boleta::class,
            'credit_note', 'nota_credito' => CreditNote::class,
            'debit_note', 'nota_debito' => DebitNote::class,
            default => throw new \InvalidArgumentException("Tipo de documento no válido: {$tipo}")
        };
    }
}