<?php

namespace App\Services;

use App\Models\Boleta;
use App\Models\Company;
use App\Models\DailySummary;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Informa a SUNAT, por resumen diario, boletas que nunca llegaron (p. ej.
 * emitidas cuando la empresa estaba en beta). Caso PUNTO FIJO 2026.
 *
 * Reglas:
 * - Un resumen por fecha de emisión (ReferenceDate = fecha original de las
 *   boletas). El nombre RC-AAAAMMDD-N usa la fecha de GENERACIÓN (hoy) y su
 *   correlativo es único por empresa y día de generación: varios resúmenes
 *   del mismo día no pueden repetir nombre.
 * - Solo boletas de la empresa, de esa fecha, firmadas con su certificado y
 *   sin otro resumen vigente.
 * - El estado de SUNAT se interpreta por código: 98 = en proceso (no es
 *   rechazo), 0/99 = procesado; aceptado solo si el CDR lo acepta.
 */
class SummaryRegularizationService
{
    public function __construct(
        protected DocumentService $documentService,
        protected StorageService $storageService,
        protected FileService $fileService,
    ) {
    }

    /**
     * @param int[] $boletaIds
     */
    public function create(Company $company, array $boletaIds, ?string $usuario = null): DailySummary
    {
        if (!$company->modo_produccion) {
            throw new Exception('La empresa no está en producción: no se informa nada a SUNAT.');
        }
        if ($company->usesValidapse()) {
            throw new Exception('La empresa emite con ValidaPSE: el resumen diario requiere su certificado propio.');
        }

        $boletaIds = array_values(array_unique(array_map('intval', $boletaIds)));
        if (!$boletaIds) {
            throw new Exception('No se indicaron boletas.');
        }

        return DB::transaction(function () use ($company, $boletaIds, $usuario) {
            $boletas = Boleta::with('client')
                ->whereIn('id', $boletaIds)
                ->lockForUpdate()
                ->get();

            if ($boletas->count() !== count($boletaIds)) {
                throw new Exception('Alguna boleta no existe.');
            }

            $fechas = $boletas->map(fn ($b) => $b->fecha_emision->toDateString())->unique();
            if ($fechas->count() !== 1) {
                throw new Exception('Todas las boletas de un resumen deben tener la misma fecha de emisión.');
            }

            foreach ($boletas as $boleta) {
                if ($boleta->company_id !== $company->id) {
                    throw new Exception("La boleta {$boleta->numero_completo} no es de esta empresa.");
                }
                if (!$boleta->xml_path || !$boleta->codigo_hash) {
                    throw new Exception("La boleta {$boleta->numero_completo} no está firmada con el certificado de la empresa.");
                }
                if ($boleta->daily_summary_id) {
                    $previo = DailySummary::find($boleta->daily_summary_id);
                    if ($previo && $previo->estado_sunat !== 'RECHAZADO') {
                        throw new Exception(
                            "La boleta {$boleta->numero_completo} ya está en el resumen {$previo->numero_completo} ({$previo->estado_sunat})."
                        );
                    }
                }
            }

            $hoy = now()->toDateString();
            // Nombre único por empresa y día de generación (regla SUNAT).
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [$company->id, crc32('RC:' . $hoy)]);
            }
            $ultimo = DailySummary::where('company_id', $company->id)
                ->whereDate('fecha_generacion', $hoy)
                ->get()
                ->max(fn ($s) => (int) $s->correlativo) ?? 0;
            $correlativo = str_pad((string) ($ultimo + 1), 3, '0', STR_PAD_LEFT);

            $summary = DailySummary::create([
                'company_id' => $company->id,
                'branch_id' => $boletas->first()->branch_id,
                'correlativo' => $correlativo,
                'numero_completo' => 'RC-' . now()->format('Ymd') . '-' . $correlativo,
                'fecha_generacion' => $hoy,
                'fecha_resumen' => $fechas->first(),
                'ubl_version' => '2.1',
                'moneda' => 'PEN',
                'estado_proceso' => 'GENERADO',
                'detalles' => $boletas->map(fn ($b) => self::line($b))->values()->all(),
                'estado_sunat' => 'PENDIENTE',
                'usuario_creacion' => $usuario,
            ]);

            Boleta::whereIn('id', $boletaIds)->update(['daily_summary_id' => $summary->id]);

            Log::info('[REGULARIZACION] Resumen creado', [
                'company_id' => $company->id,
                'summary' => $summary->numero_completo,
                'fecha_resumen' => $summary->fecha_resumen->toDateString(),
                'boletas' => $boletas->pluck('numero_completo')->all(),
            ]);

            return $summary;
        });
    }

    protected function greenter(Company $company): GreenterService
    {
        return new GreenterService($company, $this->storageService);
    }

    /** Línea de resumen (adición) con los importes ya guardados en la boleta. */
    public static function line(Boleta $boleta): array
    {
        return [
            'tipo_documento' => $boleta->tipo_documento,
            'serie_numero' => $boleta->serie . '-' . $boleta->correlativo,
            'estado' => '1',
            'cliente_tipo' => $boleta->client->tipo_documento ?? '1',
            'cliente_numero' => $boleta->client->numero_documento ?? '00000000',
            'total' => $boleta->mto_imp_venta,
            'mto_oper_gravadas' => $boleta->mto_oper_gravadas,
            'mto_oper_exoneradas' => $boleta->mto_oper_exoneradas,
            'mto_oper_inafectas' => $boleta->mto_oper_inafectas,
            'mto_oper_gratuitas' => $boleta->mto_oper_gratuitas,
            'mto_igv' => $boleta->mto_igv,
            'mto_isc' => $boleta->mto_isc ?? 0,
            'mto_icbper' => $boleta->mto_icbper ?? 0,
        ];
    }

    public function send(DailySummary $summary): array
    {
        if ($summary->fecha_generacion->toDateString() !== now()->toDateString()) {
            throw new Exception(
                "El resumen {$summary->numero_completo} se generó otro día: crea uno nuevo (el nombre debe ser del día de envío)."
            );
        }
        if (in_array($summary->estado_sunat, ['PROCESANDO', 'ACEPTADO'], true)) {
            throw new Exception("El resumen {$summary->numero_completo} ya fue enviado ({$summary->estado_sunat}).");
        }

        return $this->documentService->sendDailySummaryToSunat($summary);
    }

    /**
     * Consulta el ticket y deja el resumen y sus boletas en su estado real.
     *
     * @return array{state: string, code: ?string, description: ?string, notes: array, summary: DailySummary}
     */
    public function status(DailySummary $summary): array
    {
        if (empty($summary->ticket)) {
            throw new Exception('El resumen no tiene ticket: aún no se envió.');
        }
        if ($summary->estado_sunat === 'ACEPTADO') {
            $saved = json_decode((string) $summary->respuesta_sunat, true) ?: [];
            return ['state' => 'ACCEPTED', 'code' => $saved['code'] ?? '0', 'description' => $saved['description'] ?? null,
                'notes' => $saved['notes'] ?? [], 'summary' => $summary];
        }

        $result = $this->greenter($summary->company)->checkSummaryStatus($summary->ticket);
        $code = isset($result['code']) ? (string) $result['code'] : null;

        // 98: SUNAT aún lo procesa. No es rechazo.
        if ($code === '98') {
            return ['state' => 'PROCESSING', 'code' => $code, 'description' => 'En proceso en SUNAT', 'notes' => [],
                'summary' => $summary];
        }

        $cdr = $result['cdr_response'] ?? null;
        if (!$result['success'] || !$cdr) {
            $error = $result['error'] ?? null;
            $message = is_object($error) ? ($error->message ?? (method_exists($error, 'getMessage') ? $error->getMessage() : '')) : (string) $error;
            $errorCode = is_object($error) ? (string) ($error->code ?? (method_exists($error, 'getCode') ? $error->getCode() : '')) : '';
            // Sin respuesta procesada (red, SOAP): se puede volver a consultar.
            return ['state' => 'UNKNOWN', 'code' => $errorCode ?: $code, 'description' => $message, 'notes' => [],
                'summary' => $summary];
        }

        $accepted = $cdr->isAccepted();
        $respuesta = [
            'code' => $cdr->getCode(),
            'description' => $cdr->getDescription(),
            'notes' => $cdr->getNotes() ?? [],
            'summary' => $summary->numero_completo,
        ];

        DB::transaction(function () use ($summary, $result, $accepted, $respuesta) {
            $cdrPath = $result['cdr_zip'] ? $this->fileService->saveCdr($summary, $result['cdr_zip']) : null;
            $summary->update([
                'cdr_path' => $cdrPath,
                'estado_proceso' => $accepted ? 'COMPLETADO' : 'ERROR',
                'estado_sunat' => $accepted ? 'ACEPTADO' : 'RECHAZADO',
                'respuesta_sunat' => json_encode($respuesta),
            ]);

            if ($accepted) {
                Boleta::where('daily_summary_id', $summary->id)->update([
                    'estado_sunat' => 'ACEPTADO',
                    'respuesta_sunat' => json_encode($respuesta),
                ]);
            } else {
                // Rechazado: las boletas quedan libres para otro resumen.
                Boleta::where('daily_summary_id', $summary->id)->update([
                    'daily_summary_id' => null,
                    'estado_sunat' => 'PENDIENTE',
                    'respuesta_sunat' => json_encode($respuesta),
                ]);
            }
        });

        Log::info('[REGULARIZACION] Resultado SUNAT', ['summary' => $summary->numero_completo] + $respuesta);

        return [
            'state' => $accepted ? 'ACCEPTED' : 'REJECTED',
            'code' => (string) $respuesta['code'],
            'description' => $respuesta['description'],
            'notes' => $respuesta['notes'],
            'summary' => $summary->fresh(),
        ];
    }
}
