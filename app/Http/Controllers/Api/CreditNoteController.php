<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Models\CreditNote;
use App\Http\Requests\IndexCreditNoteRequest;
use App\Http\Requests\StoreCreditNoteRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CreditNoteController extends Controller
{
    use HandlesPdfGeneration;
    
    protected $documentService;
    protected $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    public function index(IndexCreditNoteRequest $request): JsonResponse
    {
        try {
            $query = CreditNote::with(['company', 'branch', 'client']);

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

            if ($request->has('tipo_doc_afectado')) {
                $query->where('tipo_doc_afectado', $request->tipo_doc_afectado);
            }

            if ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
                $query->whereBetween('fecha_emision', [
                    $request->fecha_desde,
                    $request->fecha_hasta
                ]);
            }

            // Paginación
            $perPage = $request->get('per_page', 15);
            $creditNotes = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $creditNotes,
                'message' => 'Notas de crédito obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las notas de crédito',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreCreditNoteRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Crear la nota de crédito
            $creditNote = $this->documentService->createCreditNote($validated);

            return response()->json([
                'success' => true,
                'data' => $creditNote->load(['company', 'branch', 'client']),
                'message' => 'Nota de crédito creada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la nota de crédito',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $creditNote,
                'message' => 'Nota de crédito obtenida correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Nota de crédito no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function sendToSunat($id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);

            if ($creditNote->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'La nota de crédito ya fue enviada y aceptada por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendToSunat($creditNote, 'credit_note');

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document'],
                    'message' => 'Nota de crédito enviada correctamente a SUNAT'
                ]);
            } else {
                // Manejar diferentes tipos de error
                $errorCode = 'UNKNOWN';
                $errorMessage = 'Error desconocido';
                
                if (is_object($result['error'])) {
                    if (method_exists($result['error'], 'getCode')) {
                        $errorCode = $result['error']->getCode();
                    } elseif (property_exists($result['error'], 'code')) {
                        $errorCode = $result['error']->code;
                    }
                    
                    if (method_exists($result['error'], 'getMessage')) {
                        $errorMessage = $result['error']->getMessage();
                    } elseif (property_exists($result['error'], 'message')) {
                        $errorMessage = $result['error']->message;
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

    public function downloadXml($id)
    {
        try {
            $creditNote = CreditNote::findOrFail($id);
            
            $download = $this->fileService->downloadXml($creditNote);
            
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
            $creditNote = CreditNote::findOrFail($id);
            
            $download = $this->fileService->downloadCdr($creditNote);
            
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

    public function downloadPdf(Request $request, $id)
    {
        try {
            $creditNote = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);

            return $this->downloadDocumentPdf($creditNote, $request);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    public function generatePdf(Request $request, $id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);

            return $this->generateDocumentPdf($creditNote, 'credit-note', $request);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getMotivos(): JsonResponse
    {
        $motivos = [
            ['code' => '01', 'name' => 'Anulación de la operación'],
            ['code' => '02', 'name' => 'Anulación por error en el RUC'],
            ['code' => '03', 'name' => 'Corrección por error en la descripción'],
            ['code' => '04', 'name' => 'Descuento global'],
            ['code' => '05', 'name' => 'Descuento por ítem'],
            ['code' => '06', 'name' => 'Devolución total'],
            ['code' => '07', 'name' => 'Devolución por ítem'],
            ['code' => '08', 'name' => 'Bonificación'],
            ['code' => '09', 'name' => 'Disminución en el valor'],
            ['code' => '10', 'name' => 'Otros conceptos'],
            ['code' => '11', 'name' => 'Ajustes de operaciones de exportación'],
            ['code' => '12', 'name' => 'Ajustes afectos al IVAP'],
            ['code' => '13', 'name' => 'Ajustes - montos y/o fechas de pago'],
        ];

        return response()->json([
            'success' => true,
            'data' => $motivos,
            'message' => 'Motivos de nota de crédito obtenidos correctamente'
        ]);
    }
}