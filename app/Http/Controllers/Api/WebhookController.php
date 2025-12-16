<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __construct(
        protected WebhookService $webhookService
    ) {}

    /**
     * Get all webhooks for a company
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->input('company_id');

        $webhooks = Webhook::where('company_id', $companyId)
            ->with('deliveries:id,webhook_id,event,status,created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $webhooks,
            'message' => 'Webhooks obtenidos correctamente'
        ]);
    }

    /**
     * Create a new webhook
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:255',
            'url' => 'required|url',
            'method' => 'in:POST,PUT,PATCH',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:invoice.created,invoice.accepted,invoice.rejected,invoice.voided,boleta.created,boleta.accepted,boleta.rejected,credit_note.created,credit_note.accepted,debit_note.created,debit_note.accepted',
            'headers' => 'nullable|array',
            'secret' => 'nullable|string',
            'timeout' => 'nullable|integer|min:5|max:120',
            'max_retries' => 'nullable|integer|min:0|max:10',
            'retry_delay' => 'nullable|integer|min:10',
        ]);

        // Generate secret if not provided
        if (empty($validated['secret'])) {
            $validated['secret'] = Str::random(40);
        }

        $webhook = Webhook::create($validated);

        return response()->json([
            'success' => true,
            'data' => $webhook,
            'message' => 'Webhook creado correctamente'
        ], 201);
    }

    /**
     * Get webhook details
     */
    public function show(int $id): JsonResponse
    {
        $webhook = Webhook::with([
            'deliveries' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(50);
            }
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $webhook,
            'message' => 'Webhook obtenido correctamente'
        ]);
    }

    /**
     * Update webhook
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'url' => 'url',
            'method' => 'in:POST,PUT,PATCH',
            'events' => 'array|min:1',
            'headers' => 'nullable|array',
            'secret' => 'nullable|string',
            'active' => 'boolean',
            'timeout' => 'integer|min:5|max:120',
            'max_retries' => 'integer|min:0|max:10',
            'retry_delay' => 'integer|min:10',
        ]);

        $webhook->update($validated);

        return response()->json([
            'success' => true,
            'data' => $webhook->fresh(),
            'message' => 'Webhook actualizado correctamente'
        ]);
    }

    /**
     * Delete webhook
     */
    public function destroy(int $id): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);
        $webhook->delete();

        return response()->json([
            'success' => true,
            'message' => 'Webhook eliminado correctamente'
        ]);
    }

    /**
     * Test webhook
     */
    public function test(int $id): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);

        $result = $this->webhookService->test($webhook);

        return response()->json([
            'success' => $result['success'],
            'data' => $result,
            'message' => $result['success']
                ? 'Webhook probado exitosamente'
                : 'Webhook falló la prueba'
        ], $result['success'] ? 200 : 400);
    }

    /**
     * Get webhook deliveries
     */
    public function deliveries(Request $request, int $id): JsonResponse
    {
        $perPage = $request->input('per_page', 15);

        $deliveries = WebhookDelivery::where('webhook_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $deliveries,
            'message' => 'Entregas obtenidas correctamente'
        ]);
    }

    /**
     * Retry failed delivery
     */
    public function retryDelivery(int $deliveryId): JsonResponse
    {
        $delivery = WebhookDelivery::findOrFail($deliveryId);

        if ($delivery->isSuccessful()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede reintentar una entrega exitosa'
            ], 400);
        }

        // Reset attempts and status
        $delivery->update([
            'status' => 'pending',
            'attempts' => 0,
            'next_retry_at' => now(),
            'error_message' => null,
        ]);

        $success = $this->webhookService->deliver($delivery);

        return response()->json([
            'success' => $success,
            'data' => $delivery->fresh(),
            'message' => $success
                ? 'Webhook reintentado exitosamente'
                : 'Webhook falló al reintentar'
        ]);
    }

    /**
     * Get webhook statistics
     */
    public function statistics(int $id): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);

        $stats = [
            'total_deliveries' => $webhook->deliveries()->count(),
            'successful' => $webhook->deliveries()->where('status', 'success')->count(),
            'failed' => $webhook->deliveries()->where('status', 'failed')->count(),
            'pending' => $webhook->deliveries()->where('status', 'pending')->count(),
            'success_rate' => 100 - $webhook->getFailureRate(),
            'failure_rate' => $webhook->getFailureRate(),
            'last_triggered_at' => $webhook->last_triggered_at,
            'last_status' => $webhook->last_status,
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
            'message' => 'Estadísticas obtenidas correctamente'
        ]);
    }
}
