<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DailySummary;
use App\Services\SummaryRegularizationService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Resúmenes diarios para informar boletas que nunca llegaron a SUNAT.
 * Ver SummaryRegularizationService.
 */
class RegularizationSummaryController extends Controller
{
    public function __construct(protected SummaryRegularizationService $service)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => 'required|integer',
            'boleta_ids' => 'required|array|min:1|max:500',
            'boleta_ids.*' => 'integer',
            'usuario' => 'nullable|string|max:191',
        ]);

        try {
            $company = Company::findOrFail($data['company_id']);
            $summary = $this->service->create($company, $data['boleta_ids'], $data['usuario'] ?? null);

            return response()->json(['success' => true, 'data' => $this->payload($summary)], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function send(string $id): JsonResponse
    {
        try {
            $summary = DailySummary::with('company')->findOrFail($id);
            $result = $this->service->send($summary);

            if (!$result['success']) {
                [$code, $message] = self::errorInfo($result['error'] ?? null);
                Log::warning('[REGULARIZACION] SUNAT no recibió el resumen', [
                    'summary' => $summary->numero_completo, 'code' => $code, 'message' => $message,
                ]);
                $summary->update(['respuesta_sunat' => json_encode(['code' => $code, 'message' => $message])]);
                return response()->json([
                    'success' => false,
                    'message' => $message ?: 'Error al enviar',
                    'error_code' => $code,
                    'data' => $this->payload($summary->fresh()),
                ], 422);
            }

            return response()->json(['success' => true, 'data' => $this->payload($summary->fresh())]);
        } catch (Exception $e) {
            Log::error('[REGULARIZACION] Error al enviar', ['summary_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function status(string $id): JsonResponse
    {
        try {
            $summary = DailySummary::with('company')->findOrFail($id);
            $result = $this->service->status($summary);

            return response()->json([
                'success' => true,
                'data' => [
                    'state' => $result['state'],
                    'code' => $result['code'],
                    'description' => $result['description'],
                    'notes' => $result['notes'],
                ] + $this->payload($result['summary']),
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** [código, mensaje] de un error de Greenter (métodos) o de un objeto simple (propiedades). */
    public static function errorInfo(mixed $error): array
    {
        if (is_object($error)) {
            $code = method_exists($error, 'getCode') ? $error->getCode() : ($error->code ?? '');
            $message = method_exists($error, 'getMessage') ? $error->getMessage() : ($error->message ?? '');
            return [(string) $code, (string) $message];
        }
        return ['', (string) $error];
    }

    protected function payload(DailySummary $summary): array
    {
        return [
            'id' => $summary->id,
            'numero_completo' => $summary->numero_completo,
            'fecha_resumen' => $summary->fecha_resumen?->toDateString(),
            'fecha_generacion' => $summary->fecha_generacion?->toDateString(),
            'estado_sunat' => $summary->estado_sunat,
            'ticket' => $summary->ticket,
            'xml_path' => $summary->xml_path,
            'cdr_path' => $summary->cdr_path,
            'lineas' => count($summary->detalles ?? []),
        ];
    }
}
