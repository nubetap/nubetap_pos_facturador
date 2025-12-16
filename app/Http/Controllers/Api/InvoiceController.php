<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Models\Invoice;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Requests\IndexInvoiceRequest;
use App\Exceptions\SunatException;
use App\Exceptions\DocumentAlreadySentException;
use App\Jobs\SendDocumentToSunat;
use App\Http\Resources\InvoiceResource;
use App\Repositories\InvoiceRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    use HandlesPdfGeneration;
    protected $documentService;
    protected $fileService;
    protected $invoiceRepository;

    public function __construct(
        DocumentService $documentService,
        FileService $fileService,
        InvoiceRepository $invoiceRepository
    ) {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
        $this->invoiceRepository = $invoiceRepository;
    }

    public function index(IndexInvoiceRequest $request): JsonResponse
    {
        try {
            // Preparar filtros para el repositorio
            $filters = array_filter([
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'estado_sunat' => $request->estado_sunat,
                'fecha_inicio' => $request->fecha_desde,
                'fecha_fin' => $request->fecha_hasta,
                'moneda' => $request->moneda,
                'numero' => $request->numero,
                'search' => $request->search,
                'per_page' => $request->get('per_page', 15)
            ]);

            // Usar el repositorio para obtener facturas con filtros
            $invoices = $this->invoiceRepository->getByDateRange($filters);

            return response()->json(
                InvoiceResource::collection($invoices)->additional([
                    'success' => true,
                    'message' => 'Facturas obtenidas correctamente'
                ])->response()->getData(true)
            );

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las facturas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Crear la factura
            $invoice = $this->documentService->createInvoice($validated);

            return response()->json(
                (new InvoiceResource($invoice->load(['company', 'branch', 'client'])))->additional([
                    'success' => true,
                    'message' => 'Factura creada correctamente'
                ])->response()->getData(true),
                201
            );

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la factura',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(UpdateInvoiceRequest $request, $id): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Actualizar la factura
            $invoice = $this->documentService->updateInvoice($id, $validated);

            return response()->json(
                (new InvoiceResource($invoice->load(['company', 'branch', 'client'])))->additional([
                    'success' => true,
                    'message' => 'Factura actualizada correctamente. Estado restablecido a PENDIENTE para reenvío.'
                ])->response()->getData(true)
            );

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la factura',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            // Usar el repositorio para obtener la factura con todas las relaciones
            $invoice = $this->invoiceRepository->findWithRelations($id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Factura no encontrada'
                ], 404);
            }

            return response()->json(
                (new InvoiceResource($invoice))->additional([
                    'success' => true,
                    'message' => 'Factura obtenida correctamente'
                ])->response()->getData(true)
            );

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Factura no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function sendToSunat($id): JsonResponse
    {
        try {
            $invoice = $this->invoiceRepository->findWithRelations($id);

            if (!$invoice) {
                throw new SunatException(
                    userMessage: 'Factura no encontrada',
                    sunatCode: 'NOT_FOUND',
                    context: ['invoice_id' => $id],
                    httpCode: 404
                );
            }

            // Validar que no haya sido ACEPTADO (permitir reenvío de RECHAZADOS y PENDIENTES)
            if ($invoice->estado_sunat === 'ACEPTADO') {
                throw new DocumentAlreadySentException(
                    'FACTURA',
                    $invoice->numero_completo
                );
            }

            // Log del reenvío si es RECHAZADO
            if ($invoice->estado_sunat === 'RECHAZADO') {
                Log::info('Reenviando factura rechazada a SUNAT', [
                    'invoice_id' => $invoice->id,
                    'numero' => $invoice->numero_completo,
                    'rechazo_anterior' => $invoice->respuesta_sunat
                ]);
            }

            // Intentar enviar a SUNAT
            $result = $this->documentService->sendToSunat($invoice, 'invoice');

            if ($result['success']) {
                Log::info('Factura enviada exitosamente a SUNAT', [
                    'invoice_id' => $invoice->id,
                    'numero' => $invoice->numero_completo,
                    'company_id' => $invoice->company_id
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $result['document'],
                    'message' => 'Factura enviada y aceptada por SUNAT'
                ]);
            } else {
                // Extraer información del error
                $errorCode = 'UNKNOWN';
                $errorMessage = 'Error desconocido al comunicarse con SUNAT';

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

                throw new SunatException(
                    userMessage: "SUNAT rechazó el documento: {$errorMessage}",
                    sunatCode: (string)$errorCode,
                    context: [
                        'invoice_id' => $invoice->id,
                        'numero' => $invoice->numero_completo,
                        'company_id' => $invoice->company_id
                    ]
                );
            }

        } catch (ModelNotFoundException $e) {
            throw new SunatException(
                userMessage: 'Factura no encontrada',
                sunatCode: 'NOT_FOUND',
                context: ['invoice_id' => $id],
                httpCode: 404
            );
        } catch (SunatException | DocumentAlreadySentException $e) {
            throw $e; // Re-lanzar excepciones SUNAT para que el handler las procese
        } catch (\Throwable $e) {
            Log::critical('Error inesperado al enviar factura a SUNAT', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new SunatException(
                userMessage: 'Error interno al procesar el envío. Por favor contacte con soporte técnico.',
                sunatCode: 'INTERNAL_ERROR',
                context: [
                    'invoice_id' => $id,
                    'error_class' => get_class($e)
                ],
                httpCode: 500
            );
        }
    }

    public function downloadXml($id)
    {
        try {
            $invoice = $this->invoiceRepository->findOrFail($id);
            
            $download = $this->fileService->downloadXml($invoice);
            
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
            $invoice = $this->invoiceRepository->findOrFail($id);
            
            $download = $this->fileService->downloadCdr($invoice);
            
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
        $invoice = $this->invoiceRepository->findOrFail($id);
        return $this->downloadDocumentPdf($invoice, $request);
    }

    public function generatePdf($id, Request $request)
    {
        $invoice = $this->invoiceRepository->findWithRelations($id);
        return $this->generateDocumentPdf($invoice, 'invoice', $request);
    }

    /**
     * Enviar factura a SUNAT de forma asíncrona (usando colas)
     *
     * @param int $id ID de la factura
     * @return JsonResponse
     */
    public function sendToSunatAsync($id): JsonResponse
    {
        try {
            $invoice = $this->invoiceRepository->findWithRelations($id);

            if (!$invoice) {
                throw new SunatException(
                    userMessage: 'Factura no encontrada',
                    sunatCode: 'NOT_FOUND',
                    context: ['invoice_id' => $id],
                    httpCode: 404
                );
            }

            // Validar que no haya sido enviado previamente
            if ($invoice->estado_sunat === 'ACEPTADO') {
                throw new DocumentAlreadySentException(
                    'FACTURA',
                    $invoice->numero_completo
                );
            }

            // Marcar como en proceso
            $invoice->update(['estado_sunat' => 'EN_COLA']);

            // Despachar job a la cola
            SendDocumentToSunat::dispatch($invoice, 'invoice');

            Log::info('Factura agregada a cola para envío a SUNAT', [
                'invoice_id' => $invoice->id,
                'numero' => $invoice->numero_completo
            ]);

            return response()->json([
                'success' => true,
                'data' => $invoice->fresh(),
                'message' => 'Factura agregada a la cola de envío. Recibirá una notificación cuando se complete el proceso.'
            ], 202); // 202 Accepted

        } catch (ModelNotFoundException $e) {
            throw new SunatException(
                userMessage: 'Factura no encontrada',
                sunatCode: 'NOT_FOUND',
                context: ['invoice_id' => $id],
                httpCode: 404
            );
        } catch (SunatException | DocumentAlreadySentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::critical('Error al agregar factura a cola', [
                'invoice_id' => $id,
                'error' => $e->getMessage()
            ]);

            throw new SunatException(
                userMessage: 'Error al procesar la solicitud de envío.',
                sunatCode: 'QUEUE_ERROR',
                context: ['invoice_id' => $id],
                httpCode: 500
            );
        }
    }

    protected function processInvoiceDetails(array $detalles, string $tipoOperacion = '0101'): array
    {
        // Para exportaciones (0200), no se debe calcular IGV
        $isExportacion = $tipoOperacion === '0200';

        foreach ($detalles as &$detalle) {
            $cantidad = $detalle['cantidad'];
            $valorUnitario = $detalle['mto_valor_unitario'];
            $porcentajeIgv = $isExportacion ? 0 : ($detalle['porcentaje_igv'] ?? 0);
            $tipAfeIgv = $isExportacion ? '40' : ($detalle['tip_afe_igv'] ?? '10'); // 40 = Exportación

            // Actualizar tipo de afectación para exportaciones
            $detalle['tip_afe_igv'] = $tipAfeIgv;
            $detalle['porcentaje_igv'] = $porcentajeIgv;

            // Calcular valor de venta
            $valorVenta = $cantidad * $valorUnitario;
            $detalle['mto_valor_venta'] = $valorVenta;

            // Para exportaciones - según ejemplo de Greenter
            if ($isExportacion) {
                $detalle['mto_base_igv'] = $valorVenta; // Base IGV = valor venta en exportaciones
                $detalle['igv'] = 0;
                $detalle['total_impuestos'] = 0;
                $detalle['mto_precio_unitario'] = $valorUnitario;
            } else {
                // Calcular base imponible IGV
                $baseIgv = in_array($tipAfeIgv, ['10', '17']) ? $valorVenta : 0;
                $detalle['mto_base_igv'] = $baseIgv;

                // Calcular IGV
                $igv = ($baseIgv * $porcentajeIgv) / 100;
                $detalle['igv'] = $igv;

                // Calcular impuestos totales del item
                $detalle['total_impuestos'] = $igv;

                // Calcular precio unitario (incluye impuestos)
                $detalle['mto_precio_unitario'] = ($valorVenta + $igv) / $cantidad;
            }
        }

        return $detalles;
    }
}