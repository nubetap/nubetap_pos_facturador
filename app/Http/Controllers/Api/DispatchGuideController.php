<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Models\DispatchGuide;
use App\Http\Requests\IndexDispatchGuideRequest;
use App\Http\Requests\StoreDispatchGuideRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DispatchGuideController extends Controller
{
    use HandlesPdfGeneration;
    
    protected $documentService;
    protected $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    public function index(IndexDispatchGuideRequest $request): JsonResponse
    {
        try {
            $query = DispatchGuide::with(['company', 'branch', 'destinatario']);

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

            if ($request->has('cod_traslado')) {
                $query->where('cod_traslado', $request->cod_traslado);
            }

            if ($request->has('mod_traslado')) {
                $query->where('mod_traslado', $request->mod_traslado);
            }

            if ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
                $query->whereBetween('fecha_emision', [
                    $request->fecha_desde,
                    $request->fecha_hasta
                ]);
            }

            // Paginación
            $perPage = $request->get('per_page', 15);
            $dispatchGuides = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $dispatchGuides,
                'message' => 'Guías de remisión obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las guías de remisión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreDispatchGuideRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Crear la guía de remisión
            $dispatchGuide = $this->documentService->createDispatchGuide($validated);

            return response()->json([
                'success' => true,
                'data' => $dispatchGuide->load(['company', 'branch', 'destinatario']),
                'message' => 'Guía de remisión creada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la guía de remisión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $dispatchGuide = DispatchGuide::with(['company', 'branch', 'destinatario'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $dispatchGuide,
                'message' => 'Guía de remisión obtenida correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Guía de remisión no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function sendToSunat($id): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Log::info("=== CONTROLADOR sendToSunat ===", ['dispatch_guide_id' => $id]);
            
            $dispatchGuide = DispatchGuide::with(['company', 'branch', 'destinatario'])->findOrFail($id);
            
            \Illuminate\Support\Facades\Log::info("Guía cargada:", [
                'id' => $dispatchGuide->id,
                'client_id' => $dispatchGuide->client_id,
                'destinatario_loaded' => $dispatchGuide->relationLoaded('destinatario'),
                'destinatario_exists' => $dispatchGuide->destinatario ? 'SI' : 'NO'
            ]);

            if ($dispatchGuide->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'La guía de remisión ya fue enviada y aceptada por SUNAT'
                ], 400);
            }

            \Illuminate\Support\Facades\Log::info("Llamando a sendDispatchGuideToSunat...");
            $result = $this->documentService->sendDispatchGuideToSunat($dispatchGuide);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document'],
                    'ticket' => $result['ticket'] ?? null,
                    'message' => 'Guía de remisión enviada correctamente a SUNAT'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'data' => $result['document'],
                    'message' => 'Error al enviar guía a SUNAT',
                    'error' => $result['error'] ?? 'Error desconocido'
                ], 400);
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("=== ERROR EN CONTROLADOR ===", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            
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
            $dispatchGuide = DispatchGuide::with(['company', 'branch', 'destinatario'])->findOrFail($id);

            if (empty($dispatchGuide->ticket)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La guía no tiene un ticket para consultar'
                ], 400);
            }

            $result = $this->documentService->checkDispatchGuideStatus($dispatchGuide);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document'],
                    'message' => 'Estado de la guía consultado correctamente'
                ]);
            } else {
                $errorMessage = 'Error desconocido';
                if (isset($result['error'])) {
                    if (is_object($result['error']) && method_exists($result['error'], 'getMessage')) {
                        $errorMessage = $result['error']->getMessage();
                    } elseif (is_string($result['error'])) {
                        $errorMessage = $result['error'];
                    } elseif (is_array($result['error'])) {
                        $errorMessage = json_encode($result['error']);
                    }
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'Error al consultar estado: ' . $errorMessage
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadXml($id)
    {
        try {
            $dispatchGuide = DispatchGuide::findOrFail($id);
            
            $download = $this->fileService->downloadXml($dispatchGuide);
            
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
            $dispatchGuide = DispatchGuide::findOrFail($id);
            
            $download = $this->fileService->downloadCdr($dispatchGuide);
            
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

    public function downloadPdf($id, Request $request)
    {
        $dispatchGuide = DispatchGuide::findOrFail($id);
        return $this->downloadDocumentPdf($dispatchGuide, $request);
    }

    public function generatePdf($id, Request $request)
    {
        $dispatchGuide = DispatchGuide::with(['company', 'branch', 'destinatario'])->findOrFail($id);
        return $this->generateDocumentPdf($dispatchGuide, 'dispatch-guide', $request);
    }

    public function getTransferReasons(): JsonResponse
    {
        $reasons = [
            ['code' => '01', 'name' => 'Venta'],
            ['code' => '02', 'name' => 'Compra'],
            ['code' => '03', 'name' => 'Venta con entrega a terceros'],
            ['code' => '04', 'name' => 'Traslado entre establecimientos de la misma empresa'],
            ['code' => '05', 'name' => 'Consignación'],
            ['code' => '06', 'name' => 'Devolución'],
            ['code' => '07', 'name' => 'Recojo de bienes transformados'],
            ['code' => '08', 'name' => 'Importación'],
            ['code' => '09', 'name' => 'Exportación'],
            ['code' => '13', 'name' => 'Otros'],
            ['code' => '14', 'name' => 'Venta sujeta a confirmación del comprador'],
            ['code' => '18', 'name' => 'Traslado de bienes para transformación'],
            ['code' => '19', 'name' => 'Traslado de bienes desde un centro de acopio'],
        ];

        return response()->json([
            'success' => true,
            'data' => $reasons,
            'message' => 'Motivos de traslado obtenidos correctamente'
        ]);
    }

    public function getTransportModes(): JsonResponse
    {
        $modes = [
            ['code' => '01', 'name' => 'Transporte público'],
            ['code' => '02', 'name' => 'Transporte privado'],
        ];

        return response()->json([
            'success' => true,
            'data' => $modes,
            'message' => 'Modalidades de transporte obtenidas correctamente'
        ]);
    }
}