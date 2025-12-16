<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    /**
     * Trigger webhooks for an event
     */
    public function trigger(int $companyId, string $event, array $payload): void
    {
        $webhooks = Webhook::where('company_id', $companyId)
            ->where('active', true)
            ->get()
            ->filter(fn($webhook) => $webhook->handlesEvent($event));

        foreach ($webhooks as $webhook) {
            $this->createDelivery($webhook, $event, $payload);
        }
    }

    /**
     * Create a webhook delivery
     */
    protected function createDelivery(Webhook $webhook, string $event, array $payload): WebhookDelivery
    {
        return WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => $this->preparePayload($payload, $event),
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    /**
     * Prepare payload with metadata
     */
    protected function preparePayload(array $payload, string $event): array
    {
        return [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $payload,
        ];
    }

    /**
     * Deliver a webhook
     */
    public function deliver(WebhookDelivery $delivery): bool
    {
        $webhook = $delivery->webhook;

        try {
            $signature = $this->generateSignature($delivery->payload, $webhook->secret);

            $headers = array_merge($webhook->headers ?? [], [
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Event' => $delivery->event,
                'User-Agent' => 'FacturacionElectronica/1.0',
            ]);

            $response = Http::timeout($webhook->timeout)
                ->withHeaders($headers)
                ->send($webhook->method, $webhook->url, [
                    'json' => $delivery->payload
                ]);

            if ($response->successful()) {
                $delivery->markAsSuccess($response->status(), $response->body());
                $webhook->recordSuccess();

                Log::channel('audit')->info('Webhook entregado exitosamente', [
                    'webhook_id' => $webhook->id,
                    'delivery_id' => $delivery->id,
                    'event' => $delivery->event,
                    'response_code' => $response->status()
                ]);

                return true;
            } else {
                $error = "HTTP {$response->status()}: {$response->body()}";
                $delivery->markAsFailed($error, $response->status());
                $webhook->recordFailure($error);

                Log::channel('audit')->warning('Webhook falló', [
                    'webhook_id' => $webhook->id,
                    'delivery_id' => $delivery->id,
                    'error' => $error
                ]);

                return false;
            }

        } catch (\Exception $e) {
            $error = "Error de conexión: {$e->getMessage()}";
            $delivery->markAsFailed($error);
            $webhook->recordFailure($error);

            Log::channel('critical')->error('Error al entregar webhook', [
                'webhook_id' => $webhook->id,
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return false;
        }
    }

    /**
     * Generate signature for webhook payload
     */
    protected function generateSignature(array $payload, ?string $secret): string
    {
        if (!$secret) {
            return '';
        }

        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    /**
     * Verify webhook signature
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Process pending deliveries
     */
    public function processPendingDeliveries(int $limit = 100): int
    {
        $deliveries = WebhookDelivery::with('webhook')
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('next_retry_at')
                      ->orWhere('next_retry_at', '<=', now());
            })
            ->limit($limit)
            ->get();

        $processed = 0;

        foreach ($deliveries as $delivery) {
            if ($delivery->shouldRetry()) {
                $this->deliver($delivery);
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Test webhook connection
     */
    public function test(Webhook $webhook): array
    {
        $testPayload = [
            'event' => 'webhook.test',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'message' => 'Test webhook delivery',
                'webhook_id' => $webhook->id,
                'webhook_name' => $webhook->name,
            ]
        ];

        try {
            $signature = $this->generateSignature($testPayload, $webhook->secret);

            $headers = array_merge($webhook->headers ?? [], [
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Event' => 'webhook.test',
            ]);

            $response = Http::timeout($webhook->timeout)
                ->withHeaders($headers)
                ->send($webhook->method, $webhook->url, [
                    'json' => $testPayload
                ]);

            return [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'response_time' => $response->handlerStats()['total_time'] ?? null,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
