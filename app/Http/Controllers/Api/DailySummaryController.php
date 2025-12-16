<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Models\DailySummary;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class DailySummaryController extends Controller
{
    protected $documentService;
    protected $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $query = DailySummary::with(['company', 'branch', 'boletas']);

            // Filtros
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('estado_sunat')) {
                $query->where('estado_sunat', $request->estado_sunat);
            }

            if ($request->has('estado_proceso')) {
                $query->where('estado_proceso', $request->estado_proceso);
            }

            if ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
                $query->whereBetween('fecha_resumen', [
                    $request->fecha_desde,
                    $request->fecha_hasta
                ]);
            }

            // Paginación
            $perPage = $request->get('per_page', 15);
            $summaries = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $summaries,
                'message' => 'Resúmenes diarios obtenidos correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los resúmenes diarios',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            // Validación
            $validated = $request->validate([
                'company_id' => 'required|exists:companies,id',
                'branch_id' => 'required|exists:branches,id',
                'fecha_generacion' => 'required|date',
                'fecha_resumen' => 'required|date',
                'ubl_version' => 'nullable|string|max:5',
                'moneda' => 'nullable|string|in:PEN,USD',
                
                // Detalles del resumen
                'detalles' => 'required|array|min:1',
                'detalles.*.tipo_documento' => 'required|string|in:03,07,08',
                'detalles.*.serie_numero' => 'required|string|max:20',
                'detalles.*.estado' => 'required|string|in:1,2,3',
                'detalles.*.cliente_tipo' => 'required|string|in:0,1,4,6',
                'detalles.*.cliente_numero' => 'required|string|max:15',
                'detalles.*.total' => 'required|numeric|min:0',
                'detalles.*.mto_oper_gravadas' => 'nullable|numeric|min:0',
                'detalles.*.mto_oper_exoneradas' => 'nullable|numeric|min:0',
                'detalles.*.mto_oper_inafectas' => 'nullable|numeric|min:0',
                'detalles.*.mto_oper_exportacion' => 'nullable|numeric|min:0',
                'detalles.*.mto_oper_gratuitas' => 'nullable|numeric|min:0',
                'detalles.*.mto_igv' => 'nullable|numeric|min:0',
                'detalles.*.mto_isc' => 'nullable|numeric|min:0',
                'detalles.*.mto_icbper' => 'nullable|numeric|min:0',
                'detalles.*.mto_otros_cargos' => 'nullable|numeric|min:0',
                
                // Documento de referencia (para notas)
                'detalles.*.documento_referencia' => 'nullable|array',
                'detalles.*.documento_referencia.tipo_documento' => 'required_with:detalles.*.documento_referencia|string',
                'detalles.*.documento_referencia.numero_documento' => 'required_with:detalles.*.documento_referencia|string',
                
                // Percepción (opcional)
                'detalles.*.percepcion' => 'nullable|array',
                'detalles.*.percepcion.cod_regimen' => 'required_with:detalles.*.percepcion|string|max:2',
                'detalles.*.percepcion.tasa' => 'required_with:detalles.*.percepcion|numeric|min:0',
                'detalles.*.percepcion.monto_base' => 'required_with:detalles.*.percepcion|numeric|min:0',
                'detalles.*.percepcion.monto' => 'required_with:detalles.*.percepcion|numeric|min:0',
                'detalles.*.percepcion.monto_total' => 'required_with:detalles.*.percepcion|numeric|min:0',
                
                'usuario_creacion' => 'nullable|string|max:100',
            ]);

            // Crear el resumen diario
            $summary = $this->documentService->createDailySummary($validated);

            return response()->json([
                'success' => true,
                'data' => $summary->load(['company', 'branch']),
                'message' => 'Resumen diario creado correctamente'
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el resumen diario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $summary = DailySummary::with(['company', 'branch', 'boletas.client'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Resumen diario obtenido correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Resumen diario no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function sendToSunat($id): JsonResponse
    {
        try {
            $summary = DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($id);

            if ($summary->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'El resumen ya fue enviado y aceptado por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendDailySummaryToSunat($summary);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document'],
                    'ticket' => $result['ticket'],
                    'message' => 'Resumen enviado correctamente a SUNAT'
                ]);
            } else {
                $errorMessage = 'Error desconocido';
                $errorCode = 'UNKNOWN';
                
                if (is_object($result['error'])) {
                    if (method_exists($result['error'], 'getMessage')) {
                        $errorMessage = $result['error']->getMessage();
                    } elseif (property_exists($result['error'], 'message')) {
                        $errorMessage = $result['error']->message;
                    }
                    
                    if (method_exists($result['error'], 'getCode')) {
                        $errorCode = $result['error']->getCode();
                    } elseif (property_exists($result['error'], 'code')) {
                        $errorCode = $result['error']->code;
                    }
                }
                
                return response()->json([
                    'success' => false,
                    'data' => $result['document'],
                    'message' => 'Error al enviar a SUNAT: ' . $errorMessage,
                    'error_code' => $errorCode
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el envío a SUNAT',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function checkStatus($id): JsonResponse
    {
        try {
            $summary = DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($id);

            if (empty($summary->ticket)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El resumen no tiene ticket para consultar estado'
                ], 400);
            }

            $result = $this->documentService->checkSummaryStatus($summary);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document'],
                    'message' => 'Estado consultado correctamente'
                ]);
            } else {
                // Manejar el error de Greenter correctamente
                $error = $result['error'] ?? null;
                $errorMessage = 'Error desconocido';

                if (is_object($error)) {
                    $errorMessage = method_exists($error, 'getMessage')
                        ? $error->getMessage()
                        : ($error->message ?? $errorMessage);
                } elseif (is_string($error)) {
                    $errorMessage = $error;
                }

                // Determinar código de estado HTTP según el tipo de error
                $httpCode = 400;
                $errorType = $result['error_type'] ?? 'UNKNOWN';
                $canRetry = $result['can_retry'] ?? false;

                if ($errorType === 'CONNECTION_ERROR') {
                    $httpCode = 503; // Service Unavailable
                    $errorMessage = 'No se pudo conectar con SUNAT: ' . $errorMessage . '. Por favor, inténtelo nuevamente más tarde.';
                } else {
                    $errorMessage = 'Error al consultar estado: ' . $errorMessage;
                }

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'error_type' => $errorType,
                    'can_retry' => $canRetry,
                    'data' => $result['document']
                ], $httpCode);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadXml($id)
    {
        try {
            $summary = DailySummary::findOrFail($id);
            
            $download = $this->fileService->downloadXml($summary);
            
            if (!$download) {
                return response()->json([
                    'success' => false,
                    'message' => 'XML no encontrado'
                ], 404);
            }
            
            return $download;

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar XML',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadCdr($id)
    {
        try {
            $summary = DailySummary::findOrFail($id);
            
            $download = $this->fileService->downloadCdr($summary);
            
            if (!$download) {
                return response()->json([
                    'success' => false,
                    'message' => 'CDR no encontrado'
                ], 404);
            }
            
            return $download;

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar CDR',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadPdf($id)
    {
        try {
            $summary = DailySummary::findOrFail($id);
            
            $download = $this->fileService->downloadPdf($summary);
            
            if (!$download) {
                return response()->json([
                    'success' => false,
                    'message' => 'PDF no encontrado'
                ], 404);
            }
            
            return $download;

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPendingSummaries(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'company_id' => 'required|exists:companies,id',
                'branch_id' => 'nullable|exists:branches,id',
            ]);

            $query = DailySummary::with(['company', 'branch', 'boletas'])
                                 ->where('company_id', $request->company_id)
                                 ->where('estado_proceso', 'ENVIADO')
                                 ->where('estado_sunat', 'PROCESANDO')
                                 ->whereNotNull('ticket');

            if ($request->branch_id) {
                $query->where('branch_id', $request->branch_id);
            }

            $pendingSummaries = $query->get();

            return response()->json([
                'success' => true,
                'data' => $pendingSummaries,
                'total' => $pendingSummaries->count(),
                'message' => 'Resúmenes pendientes obtenidos correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener resúmenes pendientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function checkAllPendingStatus(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'company_id' => 'required|exists:companies,id',
                'branch_id' => 'nullable|exists:branches,id',
            ]);

            $query = DailySummary::with(['company', 'branch'])
                                 ->where('company_id', $request->company_id)
                                 ->where('estado_proceso', 'ENVIADO')
                                 ->where('estado_sunat', 'PROCESANDO')
                                 ->whereNotNull('ticket');

            if ($request->branch_id) {
                $query->where('branch_id', $request->branch_id);
            }

            $pendingSummaries = $query->get();
            $results = [];

            foreach ($pendingSummaries as $summary) {
                $result = $this->documentService->checkSummaryStatus($summary);

                // Extraer mensaje de error si existe
                $errorMessage = null;
                if (!$result['success'] && isset($result['error'])) {
                    $error = $result['error'];
                    if (is_object($error)) {
                        $errorMessage = method_exists($error, 'getMessage')
                            ? $error->getMessage()
                            : ($error->message ?? 'Error desconocido');
                    } elseif (is_string($error)) {
                        $errorMessage = $error;
                    }
                }

                $results[] = [
                    'summary_id' => $summary->id,
                    'ticket' => $summary->ticket,
                    'success' => $result['success'],
                    'message' => $result['success'] ? 'Estado actualizado' : 'Error en consulta',
                    'error' => $errorMessage
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $results,
                'total_processed' => count($results),
                'message' => 'Consulta masiva completada'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en consulta masiva',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}