<?php

namespace App\Listeners;

use App\Events\DocumentSentToSunat;
use App\Notifications\DocumentAcceptedBySunat;
use App\Notifications\DocumentRejectedBySunat;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Log;

class SendDocumentNotification
{
    public function __construct(
        protected WebhookService $webhookService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(DocumentSentToSunat $event): void
    {
        $document = $event->document;
        $documentType = $event->documentType;
        $result = $event->result;

        // Solo enviar notificación si la empresa tiene email configurado
        if (!$document->company->email) {
            Log::info('No se envió notificación: empresa sin email configurado', [
                'company_id' => $document->company_id,
                'document_id' => $document->id
            ]);
            return;
        }

        // Enviar notificación según el resultado
        if ($result['success']) {
            // Documento aceptado
            $document->company->notify(
                new DocumentAcceptedBySunat($document, $documentType)
            );

            // Trigger webhook
            $this->triggerWebhook($document, $documentType, 'accepted', $result);

            Log::info('Notificación de aceptación enviada', [
                'document_id' => $document->id,
                'document_type' => $documentType,
                'company_id' => $document->company_id,
                'email' => $document->company->email
            ]);
        } else {
            // Documento rechazado
            $errorMessage = null;
            if (isset($result['error'])) {
                if (is_object($result['error'])) {
                    $errorMessage = method_exists($result['error'], 'getMessage')
                        ? $result['error']->getMessage()
                        : (property_exists($result['error'], 'message') ? $result['error']->message : null);
                } elseif (is_string($result['error'])) {
                    $errorMessage = $result['error'];
                }
            }

            $document->company->notify(
                new DocumentRejectedBySunat($document, $documentType, $errorMessage)
            );

            // Trigger webhook
            $this->triggerWebhook($document, $documentType, 'rejected', $result);

            Log::warning('Notificación de rechazo enviada', [
                'document_id' => $document->id,
                'document_type' => $documentType,
                'company_id' => $document->company_id,
                'error' => $errorMessage
            ]);
        }
    }

    /**
     * Trigger webhook for document event
     */
    protected function triggerWebhook($document, string $documentType, string $action, array $result): void
    {
        try {
            $event = "{$documentType}.{$action}";

            $payload = [
                'document_id' => $document->id,
                'document_type' => $documentType,
                'numero' => $document->numero_completo,
                'company_id' => $document->company_id,
                'client' => [
                    'tipo_documento' => $document->client->tipo_documento ?? null,
                    'numero_documento' => $document->client->numero_documento ?? null,
                    'razon_social' => $document->client->razon_social ?? null,
                ],
                'monto' => $document->mto_imp_venta,
                'moneda' => $document->moneda,
                'fecha_emision' => $document->fecha_emision->toIso8601String(),
                'estado_sunat' => $document->estado_sunat,
                'result' => $result,
            ];

            $this->webhookService->trigger(
                $document->company_id,
                $event,
                $payload
            );

        } catch (\Exception $e) {
            Log::warning('Error al disparar webhook', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
