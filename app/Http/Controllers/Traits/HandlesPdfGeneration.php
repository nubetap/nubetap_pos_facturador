<?php

namespace App\Http\Controllers\Traits;

use App\Services\PdfService;
use App\Services\FileService;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

trait HandlesPdfGeneration
{
    /**
     * Generar PDF para cualquier tipo de documento
     */
    protected function generateDocumentPdf($document, string $documentType, Request $request): JsonResponse
    {
        try {
            // Soportar tanto 'format' como 'formato'
            $format = $request->get('format') ?? $request->get('formato', 'A4');
            
            // Validar formato
            $pdfService = app(PdfService::class);
            if (!$pdfService->isValidFormat($format)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato no válido. Formatos disponibles: ' . implode(', ', $pdfService->getAvailableFormats())
                ], 400);
            }
            
            // Generar PDF según el tipo de documento
            $pdfContent = match($documentType) {
                'invoice' => $pdfService->generateInvoicePdf($document, $format),
                'boleta' => $pdfService->generateBoletaPdf($document, $format),
                'credit-note' => $pdfService->generateCreditNotePdf($document, $format),
                'debit-note' => $pdfService->generateDebitNotePdf($document, $format),
                'dispatch-guide' => $pdfService->generateDispatchGuidePdf($document, $format),
                'daily-summary' => $pdfService->generateDailySummaryPdf($document, $format),
                'retention' => $pdfService->generateRetentionPdf($document, $format),
                default => throw new \InvalidArgumentException("Tipo de documento no soportado: {$documentType}")
            };
            
            // Guardar PDF
            $fileService = app(FileService::class);
            $storageService = app(StorageService::class);
            $pdfPath = $fileService->savePdf($document, $pdfContent, $format);

            // Obtener URL del PDF
            $pdfUrl = $storageService->getDocumentUrl($pdfPath);

            // Actualizar ruta y URL en la base de datos
            $document->update([
                'pdf_path' => $pdfPath,
                'pdf_url' => $pdfUrl
            ]);

            return response()->json([
                'success' => true,
                'message' => "PDF generado correctamente en formato {$format}",
                'data' => [
                    'pdf_path' => $pdfPath,
                    'pdf_url' => $pdfUrl,
                    'format' => $format,
                    'document_type' => $documentType,
                    'document_id' => $document->id
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Descargar PDF con validación de formato
     */
    protected function downloadDocumentPdf($document, Request $request)
    {
        try {
            // Soportar tanto 'format' como 'formato'
            $format = $request->get('format') ?? $request->get('formato', 'A4');

            // Validar formato
            $pdfService = app(PdfService::class);
            if (!$pdfService->isValidFormat($format)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato no válido. Formatos disponibles: ' . implode(', ', $pdfService->getAvailableFormats())
                ], 400);
            }

            $fileService = app(FileService::class);

            // Verificar si el PDF en el formato solicitado existe
            $pdfPath = $fileService->getPdfPathWithFormat($document, $format);

            // Si no existe el PDF en el formato solicitado, generarlo
            if (!$pdfPath || !\Illuminate\Support\Facades\Storage::disk('public')->exists($pdfPath)) {
                // Determinar tipo de documento
                $documentType = $this->getDocumentTypeFromClass($document);

                // Generar PDF en el formato solicitado
                $pdfContent = match($documentType) {
                    'invoice' => $pdfService->generateInvoicePdf($document, $format),
                    'boleta' => $pdfService->generateBoletaPdf($document, $format),
                    'credit-note' => $pdfService->generateCreditNotePdf($document, $format),
                    'debit-note' => $pdfService->generateDebitNotePdf($document, $format),
                    'dispatch-guide' => $pdfService->generateDispatchGuidePdf($document, $format),
                    'daily-summary' => $pdfService->generateDailySummaryPdf($document, $format),
                    'retention' => $pdfService->generateRetentionPdf($document, $format),
                    default => throw new \InvalidArgumentException("Tipo de documento no soportado")
                };

                // Guardar PDF
                $pdfPath = $fileService->savePdf($document, $pdfContent, $format);

                // Actualizar solo si es el formato por defecto (A4)
                if ($format === 'A4') {
                    $storageService = app(StorageService::class);
                    $pdfUrl = $storageService->getDocumentUrl($pdfPath);
                    $document->update([
                        'pdf_path' => $pdfPath,
                        'pdf_url' => $pdfUrl
                    ]);
                }
            }

            // Descargar el PDF
            $download = $fileService->downloadPdfByPath($pdfPath, $document->numero_completo);

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

    /**
     * Obtener tipo de documento desde la clase del modelo
     */
    private function getDocumentTypeFromClass($document): string
    {
        $className = class_basename($document);

        return match($className) {
            'Invoice' => 'invoice',
            'Boleta' => 'boleta',
            'CreditNote' => 'credit-note',
            'DebitNote' => 'debit-note',
            'DispatchGuide' => 'dispatch-guide',
            'DailySummary' => 'daily-summary',
            'Retention' => 'retention',
            default => throw new \InvalidArgumentException("Clase de documento no soportada: {$className}")
        };
    }
}