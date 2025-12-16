<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VoidedDocument;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Http\Requests\StoreVoidedDocumentRequest;
use App\Http\Requests\IndexVoidedDocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VoidedDocumentController extends Controller
{
    protected $documentService;
    protected $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    public function index(IndexVoidedDocumentRequest $request): JsonResponse
    {
        try {
            $query = VoidedDocument::with(['company', 'branch']);

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

            if ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
                $query->whereBetween('fecha_emision', [
                    $request->fecha_desde,
                    $request->fecha_hasta
                ]);
            }

            if ($request->has('fecha_referencia')) {
                $query->where('fecha_referencia', $request->fecha_referencia);
            }

            $perPage = $request->get('per_page', 15);
            $voidedDocuments = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $voidedDocuments->items(),
                'pagination' => [
                    'current_page' => $voidedDocuments->currentPage(),
                    'last_page' => $voidedDocuments->lastPage(),
                    'per_page' => $voidedDocuments->perPage(),
                    'total' => $voidedDocuments->total(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener comunicaciones de baja',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreVoidedDocumentRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $voidedDocument = $this->documentService->createVoidedDocument($validated);

            return response()->json([
                'success' => true,
                'data' => $voidedDocument->load(['company', 'branch']),
                'message' => 'Comunicación de baja creada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear comunicación de baja: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $voidedDocument = VoidedDocument::with(['company', 'branch'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $voidedDocument
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Comunicación de baja no encontrada'
            ], 404);
        }
    }

    public function sendToSunat(string $id): JsonResponse
    {
        try {
            $voidedDocument = VoidedDocument::with(['company', 'branch'])->findOrFail($id);

            if ($voidedDocument->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'La comunicación de baja ya fue aceptada por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendVoidedDocumentToSunat($voidedDocument);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch']),
                    'ticket' => $result['ticket'],
                    'message' => 'Comunicación de baja enviada correctamente a SUNAT'
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
                    'data' => $result['document']->load(['company', 'branch']),
                    'message' => 'Error al enviar a SUNAT: ' . $errorMessage,
                    'error_code' => $errorCode
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            ], 500);
        }
    }

    public function checkStatus(string $id): JsonResponse
    {
        try {
            $voidedDocument = VoidedDocument::with(['company', 'branch'])->findOrFail($id);

            if (empty($voidedDocument->ticket)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay ticket para consultar el estado'
                ], 400);
            }

            $result = $this->documentService->checkVoidedDocumentStatus($voidedDocument);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch']),
                    'message' => 'Estado consultado correctamente'
                ]);
            } else {
                $errorMessage = 'Error desconocido';
                if (isset($result['error'])) {
                    if (is_object($result['error']) && method_exists($result['error'], 'getMessage')) {
                        $errorMessage = $result['error']->getMessage();
                    } elseif (is_string($result['error'])) {
                        $errorMessage = $result['error'];
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

    public function downloadXml(string $id): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $voidedDocument = VoidedDocument::findOrFail($id);

            if (empty($voidedDocument->xml_path) || !file_exists(storage_path('app/' . $voidedDocument->xml_path))) {
                return response()->json([
                    'success' => false,
                    'message' => 'XML no encontrado'
                ], 404);
            }

            return response()->download(
                storage_path('app/' . $voidedDocument->xml_path),
                $voidedDocument->identificador . '.xml',
                ['Content-Type' => 'application/xml']
            );

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar XML: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadCdr(string $id): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $voidedDocument = VoidedDocument::findOrFail($id);

            if (empty($voidedDocument->cdr_path) || !file_exists(storage_path('app/' . $voidedDocument->cdr_path))) {
                return response()->json([
                    'success' => false,
                    'message' => 'CDR no encontrado'
                ], 404);
            }

            return response()->download(
                storage_path('app/' . $voidedDocument->cdr_path),
                'R-' . $voidedDocument->identificador . '.zip',
                ['Content-Type' => 'application/zip']
            );

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar CDR: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getDocumentsForVoiding(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'company_id' => 'required|exists:companies,id',
                'branch_id' => 'required|exists:branches,id',
                'fecha_referencia' => 'required|date',
                'tipo_documento' => 'nullable|in:01,03,07,08,09'
            ]);

            $documents = $this->documentService->getDocumentsForVoiding(
                $request->company_id,
                $request->branch_id,
                $request->fecha_referencia,
                $request->tipo_documento
            );

            return response()->json([
                'success' => true,
                'data' => $documents,
                'total' => count($documents),
                'message' => 'Documentos disponibles para anulación obtenidos correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener documentos: ' . $e->getMessage()
            ], 500);
        }
    }
}