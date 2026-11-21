<?php

namespace App\Http\Middleware;

use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\DailySummary;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Retention;
use App\Models\VoidedDocument;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rechaza un envío a SUNAT si la empresa no está en el ambiente que espera
 * quien lo pide.
 *
 * Nubetap manda en cada envío:
 *   X-Expected-Environment:  production | beta
 *   X-Expected-Cpe-Provider: greenter | validapse   (opcional)
 *
 * Si el ambiente de la empresa en el facturador no coincide (alguien la pasó
 * a beta, o cambió su proveedor), el comprobante NO se envía y se responde
 * 409 ENVIRONMENT_MISMATCH. Así un comprobante real nunca termina en el
 * ambiente de pruebas en silencio (caso PUNTO FIJO, sept-oct 2026).
 *
 * Sin cabecera no se valida: los clientes que no la mandan siguen igual.
 */
class EnsureExpectedEnvironment
{
    public const ERROR_CODE = 'ENVIRONMENT_MISMATCH';

    protected const MODELS = [
        'invoice' => Invoice::class,
        'boleta' => Boleta::class,
        'daily-summary' => DailySummary::class,
        'credit-note' => CreditNote::class,
        'debit-note' => DebitNote::class,
        'retention' => Retention::class,
        'voided' => VoidedDocument::class,
    ];

    public function handle(Request $request, Closure $next, string $documentType): Response
    {
        $expectedEnv = strtolower(trim((string) $request->header('X-Expected-Environment', '')));
        $expectedProvider = strtolower(trim((string) $request->header('X-Expected-Cpe-Provider', '')));

        if ($expectedEnv === '' && $expectedProvider === '') {
            return $next($request);
        }

        $modelClass = self::MODELS[$documentType] ?? null;
        $document = $modelClass ? $modelClass::with('company')->find($request->route('id')) : null;
        $company = $document?->company;

        // Documento inexistente: que el controlador responda su 404 de siempre.
        if (!$company) {
            return $next($request);
        }

        $actualEnv = $company->modo_produccion ? 'production' : 'beta';
        $actualProvider = $company->usesValidapse() ? 'validapse' : 'greenter';

        $mismatches = [];
        if ($expectedEnv !== '' && $expectedEnv !== $actualEnv) {
            $mismatches['environment'] = ['expected' => $expectedEnv, 'actual' => $actualEnv];
        }
        if ($expectedProvider !== '' && $expectedProvider !== $actualProvider) {
            $mismatches['cpe_provider'] = ['expected' => $expectedProvider, 'actual' => $actualProvider];
        }

        if (!$mismatches) {
            return $next($request);
        }

        Log::critical('Envío rechazado: ambiente de la empresa distinto al esperado', [
            'company_id' => $company->id,
            'ruc' => $company->ruc,
            'document_type' => $documentType,
            'document_id' => $document->getKey(),
            'numero' => $document->numero_completo ?? $document->identificador ?? null,
            'mismatches' => $mismatches,
        ]);

        $detail = collect($mismatches)
            ->map(fn ($m, $field) => "{$field}: se esperaba {$m['expected']} y la empresa está en {$m['actual']}")
            ->implode('; ');

        return response()->json([
            'success' => false,
            'error_code' => self::ERROR_CODE,
            'message' => "No se envió a SUNAT: el ambiente de la empresa {$company->ruc} no coincide ({$detail}).",
            'data' => [
                'company_id' => $company->id,
                'ruc' => $company->ruc,
                'mismatches' => $mismatches,
            ],
        ], 409);
    }
}
