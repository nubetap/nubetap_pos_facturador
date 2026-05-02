<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Http\Requests\Boleta\CreateDailySummaryRequest;
use App\Http\Requests\Boleta\GetBoletasPendingRequest;
use App\Http\Requests\Boleta\IndexBoletaRequest;
use App\Http\Requests\Boleta\StoreBoletaRequest;
use App\Http\Requests\Boleta\UpdateBoletaRequest;
use App\Models\Boleta;
use App\Models\DailySummary;
use App\Services\DocumentService;
use App\Services\FileService;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BoletaController extends Controller
{
    use HandlesPdfGeneration;

    protected DocumentService $documentService;
    protected FileService $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    /**
     * Listar boletas con filtros
     */
    public function index(IndexBoletaRequest $request): JsonResponse
    {
        try {
            $query = Boleta::with(['company', 'branch', 'client']);
            $this->applyFilters($query, $request);
            
            $perPage = $request->get('per_page', 15);
            $boletas = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $boletas->items(),
                'pagination' => $this->getPaginationData($boletas)
            ]);

        } catch (Exception $e) {
            return $this->errorResponse('Error al listar boletas', $e);
        }
    }

    /**
     * Crear nueva boleta
     */
    public function store(StoreBoletaRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $boleta = $this->documentService->createBoleta($validated);

            return response()->json([
                'success' => true,
                'data' => $boleta->load(['company', 'branch', 'client']),
                'message' => 'Boleta creada correctamente'
            ], 201);

        } catch (Exception $e) {
            return $this->errorResponse('Error al crear la boleta', $e);
        }
    }

    /**
     * Obtener boleta específica
     */
    public function show(string $id): JsonResponse
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $boleta
            ]);
        } catch (Exception $e) {
            return $this->notFoundResponse('Boleta no encontrada');
        }
    }

    /**
     * Actualizar boleta
     */
    public function update(UpdateBoletaRequest $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Actualizar la boleta
            $boleta = $this->documentService->updateBoleta($id, $validated);

            return response()->json([
                'success' => true,
                'data' => $boleta->load(['company', 'branch', 'client']),
                'message' => 'Boleta actualizada correctamente. Estado restablecido a PENDIENTE para reenvío.'
            ]);

        } catch (Exception $e) {
            return $this->errorResponse('Error al actualizar la boleta', $e);
        }
    }

    /**
     * Firmar XML de boleta sin enviar a SUNAT.
     * Genera el XML firmado, lo guarda en S3 y extrae el codigo_hash.
     */
    public function signXml(string $id): JsonResponse
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);

            $result = $this->documentService->signXml($boleta, 'boleta');

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch', 'client']),
                    'message' => 'XML firmado correctamente'
                ]);
            }

            return response()->json([
                'success' => false,
                'data' => $result['document'],
                'message' => 'Error al firmar XML: ' . ($result['error']->message ?? 'Error desconocido'),
                'error_code' => $result['error']->code ?? 'UNKNOWN'
            ], 400);

        } catch (Exception $e) {
            return $this->errorResponse('Error al firmar XML de boleta', $e);
        }
    }

    /**
     * Enviar boleta a SUNAT
     */
    public function sendToSunat(string $id, Request $request): JsonResponse
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);

            $forceResend = $request->boolean('force_resend', false);

            // Validar que no haya sido ACEPTADO (permitir reenvío de RECHAZADOS y PENDIENTES)
            // TEMPORAL: force_resend=true permite reenviar boletas ACEPTADAS en STAGE a SUNAT PROD
            if ($boleta->estado_sunat === 'ACEPTADO' && !$forceResend) {
                return response()->json([
                    'success' => false,
                    'message' => 'La boleta ya fue aceptada por SUNAT'
                ], 400);
            }

            // Log del reenvío
            if ($boleta->estado_sunat === 'RECHAZADO' || $forceResend) {
                Log::info('Reenviando boleta a SUNAT', [
                    'boleta_id' => $boleta->id,
                    'numero' => $boleta->numero_completo,
                    'estado_anterior' => $boleta->estado_sunat,
                    'force_resend' => $forceResend,
                    'respuesta_anterior' => $boleta->respuesta_sunat
                ]);
            }

            if ($forceResend) {
                Log::info('[FORCE_RESEND] Inicio envío a SUNAT PROD', [
                    'boleta_id' => $boleta->id,
                    'numero' => $boleta->numero_completo,
                    'modo_produccion' => $boleta->company->modo_produccion,
                    'endpoint' => $boleta->company->getInvoiceEndpoint(),
                    'xml_url_anterior' => $boleta->xml_url,
                    'cdr_url_anterior' => $boleta->cdr_url,
                    'pdf_url_anterior' => $boleta->pdf_url,
                    'hash_anterior' => $boleta->codigo_hash,
                ]);
            }

            $result = $this->documentService->sendToSunat($boleta, 'boleta');

            if ($result['success']) {
                $doc = $result['document'];

                if ($forceResend) {
                    Log::info('[FORCE_RESEND] SUNAT PROD aceptó boleta', [
                        'boleta_id' => $doc->id,
                        'numero' => $doc->numero_completo,
                        'estado_sunat' => $doc->estado_sunat,
                        'hash_nuevo' => $doc->codigo_hash,
                        'xml_url_nuevo' => $doc->xml_url,
                        'cdr_url_nuevo' => $doc->cdr_url,
                        'respuesta_sunat' => $doc->respuesta_sunat,
                    ]);
                }

                // Regenerar PDF con el nuevo codigo_hash de producción
                $this->documentService->generateBoletaPdf($doc);
                $doc->refresh();

                if ($forceResend) {
                    Log::info('[FORCE_RESEND] PDF regenerado', [
                        'boleta_id' => $doc->id,
                        'numero' => $doc->numero_completo,
                        'pdf_url_nuevo' => $doc->pdf_url,
                        'pdf_path' => $doc->pdf_path,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => $doc->load(['company', 'branch', 'client']),
                    'message' => 'Boleta enviada exitosamente a SUNAT'
                ]);
            }

            if ($forceResend) {
                Log::error('[FORCE_RESEND] Error al enviar a SUNAT PROD', [
                    'boleta_id' => $boleta->id,
                    'numero' => $boleta->numero_completo,
                    'estado_sunat' => $result['document']->estado_sunat,
                    'error' => $result['error'],
                    'respuesta_sunat' => $result['document']->respuesta_sunat,
                ]);
            }

            return $this->handleSunatError($result);

        } catch (Exception $e) {
            Log::error('[FORCE_RESEND] Excepción no controlada', [
                'boleta_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse('Error interno al enviar a SUNAT', $e);
        }
    }

    /**
     * Descargar XML de boleta
     */
    public function downloadXml(string $id): Response
    {
        try {
            $boleta = Boleta::findOrFail($id);
            
            if (!$this->fileService->fileExists($boleta->xml_path)) {
                return $this->notFoundResponse('XML no encontrado');
            }

            return $this->fileService->downloadFile(
                $boleta->xml_path,
                $boleta->numero_completo . '.xml',
                ['Content-Type' => 'application/xml']
            );

        } catch (Exception $e) {
            return $this->errorResponse('Error al descargar XML', $e);
        }
    }

    /**
     * Descargar CDR de boleta
     */
    public function downloadCdr(string $id): Response
    {
        try {
            $boleta = Boleta::findOrFail($id);
            
            if (!$this->fileService->fileExists($boleta->cdr_path)) {
                return $this->notFoundResponse('CDR no encontrado');
            }

            return $this->fileService->downloadFile(
                $boleta->cdr_path,
                'R-' . $boleta->numero_completo . '.zip',
                ['Content-Type' => 'application/zip']
            );

        } catch (Exception $e) {
            return $this->errorResponse('Error al descargar CDR', $e);
        }
    }

    /**
     * Descargar PDF de boleta
     */
    public function downloadPdf(string $id, Request $request): Response
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
            return $this->downloadDocumentPdf($boleta, $request);
        } catch (Exception $e) {
            return $this->errorResponse('Error al descargar PDF', $e);
        }
    }

    /**
     * Generar PDF de boleta
     */
    public function generatePdf(string $id, Request $request): Response
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
            return $this->generateDocumentPdf($boleta, 'boleta', $request);
        } catch (Exception $e) {
            return $this->errorResponse('Error al generar PDF', $e);
        }
    }

    /**
     * Crear resumen diario desde fecha
     */
    public function createDailySummaryFromDate(CreateDailySummaryRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $summary = $this->documentService->createSummaryFromBoletas($validated);

            return response()->json([
                'success' => true,
                'data' => $summary->load(['company', 'branch', 'boletas']),
                'message' => 'Resumen diario creado correctamente'
            ], 201);

        } catch (Exception $e) {
            return $this->errorResponse('Error al crear resumen diario', $e);
        }
    }

    /**
     * Enviar resumen a SUNAT
     */
    public function sendSummaryToSunat(string $summaryId): JsonResponse
    {
        try {
            $summary = DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($summaryId);

            if ($summary->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'El resumen ya fue aceptado por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendDailySummaryToSunat($summary);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch', 'boletas']),
                    'ticket' => $result['ticket'],
                    'message' => 'Resumen enviado correctamente a SUNAT'
                ]);
            }

            return response()->json([
                'success' => false,
                'data' => $result['document']->load(['company', 'branch', 'boletas']),
                'message' => 'Error al enviar resumen a SUNAT',
                'error' => $result['error']
            ], 400);

        } catch (Exception $e) {
            return $this->errorResponse('Error interno al enviar resumen', $e);
        }
    }

    /**
     * Consultar estado de resumen
     */
    public function checkSummaryStatus(string $summaryId): JsonResponse
    {
        try {
            $summary = DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($summaryId);
            $result = $this->documentService->checkSummaryStatus($summary);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch', 'boletas']),
                    'message' => 'Estado del resumen consultado correctamente'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar estado: ' . ($result['error'] ?? 'Error desconocido')
            ], 400);

        } catch (Exception $e) {
            return $this->errorResponse('Error al consultar estado del resumen', $e);
        }
    }

    /**
     * Obtener boletas pendientes para resumen
     */
    public function getBoletsasPendingForSummary(GetBoletasPendingRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $boletas = $this->getPendingBoletas($validated);

            return response()->json([
                'success' => true,
                'data' => $boletas,
                'total' => $boletas->count(),
                'message' => 'Boletas pendientes obtenidas correctamente'
            ]);

        } catch (Exception $e) {
            return $this->errorResponse('Error al obtener boletas pendientes', $e);
        }
    }

    /**
     * TEMPORAL: Endpoint batch para migración stage→prod.
     * Recibe un lote de boletas con detalles recalculados, las actualiza,
     * crea resúmenes diarios por fecha y los envía a SUNAT PROD.
     */
    public function forceResendBatch(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'company_id' => 'required|exists:companies,id',
                'branch_id' => 'required|exists:branches,id',
                'boletas' => 'required|array|min:1',
                'boletas.*.id' => 'required|integer|exists:boletas,id',
                'boletas.*.detalles' => 'required|array|min:1',
                'boletas.*.detalles.*.codigo' => 'required|string',
                'boletas.*.detalles.*.descripcion' => 'required|string',
                'boletas.*.detalles.*.cantidad' => 'required|numeric|min:0.01',
                'boletas.*.detalles.*.unidad' => 'required|string',
                'boletas.*.detalles.*.mto_valor_unitario' => 'required|numeric|min:0',
                'boletas.*.detalles.*.porcentaje_igv' => 'required|numeric',
                'boletas.*.detalles.*.tip_afe_igv' => 'required|string',
            ]);

            $companyId = $validated['company_id'];
            $branchId = $validated['branch_id'];
            $results = [
                'updated' => [],
                'update_errors' => [],
                'summaries' => [],
                'summary_errors' => [],
            ];

            Log::info('[BATCH_RESEND] Inicio de migración stage→prod', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'total_boletas' => count($validated['boletas']),
            ]);

            // PASO 1: Actualizar cada boleta con los nuevos detalles
            foreach ($validated['boletas'] as $boletaData) {
                try {
                    $boleta = $this->documentService->updateBoleta($boletaData['id'], [
                        'force_update' => true,
                        'detalles' => $boletaData['detalles'],
                    ]);
                    $results['updated'][] = [
                        'id' => $boleta->id,
                        'numero' => $boleta->numero_completo,
                        'mto_imp_venta' => $boleta->mto_imp_venta,
                        'mto_igv' => $boleta->mto_igv,
                    ];
                } catch (Exception $e) {
                    $results['update_errors'][] = [
                        'id' => $boletaData['id'],
                        'error' => $e->getMessage(),
                    ];
                    Log::error('[BATCH_RESEND] Error al actualizar boleta', [
                        'boleta_id' => $boletaData['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('[BATCH_RESEND] Boletas actualizadas', [
                'ok' => count($results['updated']),
                'errores' => count($results['update_errors']),
            ]);

            // Si ninguna boleta se actualizó, no continuar
            if (empty($results['updated'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo actualizar ninguna boleta',
                    'results' => $results,
                ], 400);
            }

            // PASO 2: Agrupar boletas actualizadas por fecha_emision y crear resúmenes
            $updatedIds = array_column($results['updated'], 'id');
            $boletas = Boleta::whereIn('id', $updatedIds)
                ->where('estado_sunat', 'PENDIENTE')
                ->whereNull('daily_summary_id')
                ->get();

            $boletasPorFecha = $boletas->groupBy(function ($b) {
                return $b->fecha_emision->format('Y-m-d');
            });

            Log::info('[BATCH_RESEND] Boletas agrupadas por fecha', [
                'fechas' => $boletasPorFecha->keys()->toArray(),
                'cantidades' => $boletasPorFecha->map->count()->toArray(),
            ]);

            foreach ($boletasPorFecha as $fecha => $boletasDelDia) {
                try {
                    // Crear resumen diario
                    $summary = $this->documentService->createSummaryFromBoletas([
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'fecha_resumen' => $fecha,
                        'usuario_creacion' => 'batch_migration_stage_to_prod',
                    ]);

                    Log::info('[BATCH_RESEND] Resumen diario creado', [
                        'summary_id' => $summary->id,
                        'fecha' => $fecha,
                        'boletas_count' => $boletasDelDia->count(),
                        'correlativo' => $summary->correlativo,
                    ]);

                    // Enviar resumen a SUNAT
                    $sendResult = $this->documentService->sendDailySummaryToSunat($summary);

                    if ($sendResult['success']) {
                        Log::info('[BATCH_RESEND] Resumen enviado a SUNAT', [
                            'summary_id' => $summary->id,
                            'ticket' => $sendResult['ticket'],
                        ]);

                        // Esperar 3 segundos y consultar estado
                        sleep(3);
                        $statusResult = $this->documentService->checkSummaryStatus($sendResult['document']);

                        if ($statusResult['success']) {
                            Log::info('[BATCH_RESEND] Resumen ACEPTADO por SUNAT', [
                                'summary_id' => $summary->id,
                                'fecha' => $fecha,
                            ]);

                            // Regenerar PDFs de las boletas del resumen
                            $pdfResults = [];
                            foreach ($boletasDelDia as $boleta) {
                                try {
                                    $boleta->refresh();
                                    $boleta->update(['estado_sunat' => 'ACEPTADO']);
                                    $this->documentService->generateBoletaPdf($boleta);
                                    $boleta->refresh();
                                    $pdfResults[] = [
                                        'id' => $boleta->id,
                                        'numero' => $boleta->numero_completo,
                                        'pdf_url' => $boleta->pdf_url,
                                        'estado_sunat' => $boleta->estado_sunat,
                                    ];
                                } catch (Exception $e) {
                                    $pdfResults[] = [
                                        'id' => $boleta->id,
                                        'numero' => $boleta->numero_completo,
                                        'pdf_error' => $e->getMessage(),
                                    ];
                                }
                            }

                            $results['summaries'][] = [
                                'summary_id' => $summary->id,
                                'fecha' => $fecha,
                                'estado' => 'ACEPTADO',
                                'ticket' => $sendResult['ticket'],
                                'boletas_count' => $boletasDelDia->count(),
                                'boletas' => $pdfResults,
                            ];
                        } else {
                            // SUNAT aún procesando o error
                            $results['summaries'][] = [
                                'summary_id' => $summary->id,
                                'fecha' => $fecha,
                                'estado' => 'PROCESANDO',
                                'ticket' => $sendResult['ticket'],
                                'boletas_count' => $boletasDelDia->count(),
                                'message' => 'SUNAT aún procesando. Usar POST /boletas/summary/' . $summary->id . '/check-status para reintentar.',
                            ];

                            Log::info('[BATCH_RESEND] Resumen aún procesando', [
                                'summary_id' => $summary->id,
                                'ticket' => $sendResult['ticket'],
                            ]);
                        }
                    } else {
                        $results['summary_errors'][] = [
                            'fecha' => $fecha,
                            'summary_id' => $summary->id,
                            'error' => $sendResult['error'],
                        ];
                        Log::error('[BATCH_RESEND] Error al enviar resumen', [
                            'summary_id' => $summary->id,
                            'fecha' => $fecha,
                            'error' => $sendResult['error'],
                        ]);
                    }
                } catch (Exception $e) {
                    $results['summary_errors'][] = [
                        'fecha' => $fecha,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('[BATCH_RESEND] Error al crear/enviar resumen', [
                        'fecha' => $fecha,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('[BATCH_RESEND] Proceso completado', [
                'boletas_actualizadas' => count($results['updated']),
                'boletas_con_error' => count($results['update_errors']),
                'resumenes_procesados' => count($results['summaries']),
                'resumenes_con_error' => count($results['summary_errors']),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proceso de migración completado',
                'results' => $results,
            ]);

        } catch (Exception $e) {
            Log::error('[BATCH_RESEND] Error general', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse('Error en migración batch', $e);
        }
    }

    /**
     * Aplicar filtros a la consulta
     */
    private function applyFilters($query, Request $request): void
    {
        $filters = [
            'company_id' => 'where',
            'branch_id' => 'where',
            'estado_sunat' => 'where',
            'fecha_desde' => 'whereDate|>=',
            'fecha_hasta' => 'whereDate|<='
        ];

        foreach ($filters as $field => $operation) {
            if ($request->has($field)) {
                $parts = explode('|', $operation);
                $method = $parts[0];
                $operator = $parts[1] ?? null;

                if ($operator) {
                    $query->$method('fecha_emision', $operator, $request->$field);
                } else {
                    $query->$method($field, $request->$field);
                }
            }
        }
    }

    /**
     * Obtener boletas pendientes
     */
    private function getPendingBoletas(array $filters)
    {
        return Boleta::with(['company', 'branch', 'client'])
            ->where('company_id', $filters['company_id'])
            ->where('branch_id', $filters['branch_id'])
            ->whereDate('fecha_emision', $filters['fecha_emision'])
            ->where('estado_sunat', 'PENDIENTE')
            ->whereNull('daily_summary_id')
            ->get();
    }

    /**
     * Manejar error de SUNAT
     */
    private function handleSunatError(array $result): JsonResponse
    {
        $error = $result['error'];
        $errorCode = 'UNKNOWN';
        $errorMessage = 'Error desconocido';

        if (is_object($error)) {
            $errorCode = method_exists($error, 'getCode') ? $error->getCode() : ($error->code ?? $errorCode);
            $errorMessage = method_exists($error, 'getMessage') ? $error->getMessage() : ($error->message ?? $errorMessage);
        }

        return response()->json([
            'success' => false,
            'data' => $result['document'],
            'message' => 'Error al enviar a SUNAT: ' . $errorMessage,
            'error_code' => $errorCode
        ], 400);
    }

    /**
     * Obtener datos de paginación
     */
    private function getPaginationData($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Respuesta de error estandarizada
     */
    private function errorResponse(string $message, Exception $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message . ': ' . $e->getMessage()
        ], 500);
    }

    /**
     * Respuesta de no encontrado
     */
    private function notFoundResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], 404);
    }
}