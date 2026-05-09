<?php

namespace App\Services\Cpe;

use App\Exceptions\ValidapseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente HTTP para la API de comprobantes (CPE) de ValidaPSE.
 *
 * Cubre los endpoints que necesita DocumentService::sendToSunat() cuando
 * Company.cpe_provider == 'validapse':
 *
 *  - signAndSend(): firma el XML en ValidaPSE y lo envía a SUNAT (1 sola llamada).
 *  - getCdr(): recupera la Constancia de Recepción (CDR) de SUNAT.
 *
 * Endpoints distintos según ambiente (production vs DEMO):
 *  - PRODUCCIÓN: /api/cpe/generarenviar      /api/cpe/consultar/{nombre}
 *  - DEMO:       /api/cpe/generarenviar-demo /api/cpe/consultar-demo/{nombre}
 *
 * El cliente NO conoce el modelo Company. Recibe token_acceso y production_mode
 * como argumentos en cada llamada → trivial de testear, sin acoplamiento.
 *
 * Doc: docs/INTEGRACION-VALIDAPSE-NRUS.md (Paso 8)
 */
class ValidapseClient
{
    private string $baseUrl;
    private int $timeout;
    private int $retryTimes;
    private int $retrySleepMs;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.validapse.base_url'), '/');
        $this->timeout = (int) config('services.validapse.timeout', 30);
        $this->retryTimes = (int) config('services.validapse.retry_times', 2);
        $this->retrySleepMs = (int) config('services.validapse.retry_sleep_ms', 500);
    }

    /**
     * Firma + envía el XML a SUNAT en una sola llamada.
     *
     * @param string $tokenAcceso  Token por empresa (Company.validapse_token_acceso).
     * @param bool   $production   true → endpoint normal, false → endpoint -demo.
     * @param string $nombreArchivo Formato "RUC-TIPO-SERIE-NUMERO" sin .xml.
     * @param string $unsignedXml   XML SIN firmar (UBL plano construido por Greenter).
     *
     * @return array{
     *     isSuccess: bool,
     *     estado: int,
     *     codigo_hash: string|null,
     *     mensaje: string|null,
     *     xml: string|null,
     *     external_id: string|null,
     *     raw: array<string,mixed>
     * }
     *
     * @throws ValidapseException
     */
    public function signAndSend(
        string $tokenAcceso,
        bool $production,
        string $nombreArchivo,
        string $unsignedXml,
    ): array {
        $path = $production ? '/api/cpe/generarenviar' : '/api/cpe/generarenviar-demo';

        $payload = [
            'nombre_archivo' => $nombreArchivo,
            'contenido_archivo' => base64_encode($unsignedXml),
        ];

        $response = $this->request($tokenAcceso, 'POST', $path, $payload, [
            'nombre_archivo' => $nombreArchivo,
            'production' => $production,
        ]);

        return $this->normalizeCpeResponse($response, $nombreArchivo);
    }

    /**
     * Recupera el CDR de SUNAT vía ValidaPSE.
     *
     * Llamado por el job asíncrono que hace polling después de signAndSend.
     *
     * @return array{
     *     isSuccess: bool,
     *     estado: int,
     *     codigo_hash: string|null,
     *     mensaje: string|null,
     *     xml: string|null,
     *     external_id: string|null,
     *     raw: array<string,mixed>
     * }
     *
     * @throws ValidapseException
     */
    public function getCdr(
        string $tokenAcceso,
        bool $production,
        string $nombreArchivo,
    ): array {
        $base = $production ? '/api/cpe/consultar' : '/api/cpe/consultar-demo';
        $path = $base . '/' . rawurlencode($nombreArchivo);

        $response = $this->request($tokenAcceso, 'GET', $path, null, [
            'nombre_archivo' => $nombreArchivo,
            'production' => $production,
        ]);

        return $this->normalizeCpeResponse($response, $nombreArchivo);
    }

    // ----------------------------------------------------------------------
    // HTTP plumbing
    // ----------------------------------------------------------------------

    private function request(
        string $tokenAcceso,
        string $method,
        string $path,
        ?array $jsonBody,
        array $logContext,
    ): Response {
        if ($tokenAcceso === '') {
            throw new ValidapseException(
                userMessage: 'token_acceso de ValidaPSE vacío. La empresa no está sincronizada con ValidaPSE.',
                httpStatus: null,
                context: $logContext,
            );
        }

        $url = $this->baseUrl . $path;

        try {
            $request = $this->buildPendingRequest($tokenAcceso);
            $response = match (strtoupper($method)) {
                'GET'  => $request->get($url),
                'POST' => $request->post($url, $jsonBody ?? []),
                default => throw new ValidapseException(
                    userMessage: "Método HTTP no soportado: {$method}",
                    context: $logContext,
                ),
            };
        } catch (ConnectionException $e) {
            Log::warning('ValidaPSE conexión falló', $logContext + [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw ValidapseException::networkError($e->getMessage(), $logContext);
        } catch (Throwable $e) {
            Log::error('ValidaPSE error inesperado', $logContext + [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw ValidapseException::networkError($e->getMessage(), $logContext);
        }

        if ($response->serverError()) {
            Log::warning('ValidaPSE respondió 5xx', $logContext + [
                'status' => $response->status(),
                'body' => $this->safeBody($response),
            ]);
            throw ValidapseException::http(
                $response->status(),
                $this->safeMessage($response) ?: 'Error de servidor ValidaPSE',
                $logContext,
            );
        }

        if ($response->clientError()) {
            Log::warning('ValidaPSE respondió 4xx', $logContext + [
                'status' => $response->status(),
                'body' => $this->safeBody($response),
            ]);
            throw ValidapseException::http(
                $response->status(),
                $this->safeMessage($response) ?: 'Error de cliente ValidaPSE',
                $logContext + ['body' => $this->safeBody($response)],
            );
        }

        return $response;
    }

    private function buildPendingRequest(string $tokenAcceso): PendingRequest
    {
        return Http::withToken($tokenAcceso)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->retry(
                $this->retryTimes,
                $this->retrySleepMs,
                // Solo reintentar errores de red / 5xx — nunca 4xx (token inválido,
                // cuota agotada, validación). Esos son determinísticos.
                throw: false,
            );
    }

    /**
     * Normaliza el envoltorio de respuesta de ValidaPSE.
     *
     * Respuesta éxito:
     *  { "isSuccess": true, "estado": 200, "codigo_hash": "...",
     *    "mensaje": "...", "xml": "<base64>", "external_id": "..." }
     *
     * Respuesta error (incluye rechazo de SUNAT):
     *  { "isSuccess": false, "estado": 501, "code": "3111",
     *    "errores": "El monto de afectación de IGV...", "xml": "" }
     *
     * Notar:
     *  - El campo de mensaje en error puede ser "errores" (plural ES),
     *    "mensaje", "message", "errors" o "error". Probamos todos.
     *  - SUNAT trae el código de rechazo en `code` (ej. "3111").
     *  - Si isSuccess=false con HTTP 200, lanza ValidapseException::rejected
     *    con el mensaje + code SUNAT concatenados para diagnóstico inmediato.
     *
     * @return array{isSuccess: bool, estado: int, codigo_hash: string|null, mensaje: string|null, xml: string|null, external_id: string|null, raw: array<string,mixed>}
     */
    private function normalizeCpeResponse(Response $response, string $nombreArchivo): array
    {
        $body = $response->json();
        if (!is_array($body)) {
            throw new ValidapseException(
                userMessage: 'Respuesta ValidaPSE no es JSON válido',
                httpStatus: $response->status(),
                context: ['nombre_archivo' => $nombreArchivo, 'raw' => $response->body()],
            );
        }

        $isSuccess = (bool) ($body['isSuccess'] ?? false);
        $mensaje = self::extractMessage($body);

        if (!$isSuccess) {
            // Concatenar code SUNAT al mensaje si está disponible.
            // ej. "[3111] El monto de afectación de IGV por linea debe ser ≠ 0.00"
            $sunatCode = $body['code'] ?? null;
            $fullMessage = $mensaje ?? 'ValidaPSE rechazó sin detalle';
            if ($sunatCode) {
                $fullMessage = "[SUNAT {$sunatCode}] {$fullMessage}";
            }
            throw ValidapseException::rejected(
                $fullMessage,
                ['nombre_archivo' => $nombreArchivo, 'body' => $body],
            );
        }

        return [
            'isSuccess' => true,
            'estado' => (int) ($body['estado'] ?? 200),
            'codigo_hash' => $body['codigo_hash'] ?? null,
            'mensaje' => $mensaje,
            'xml' => $body['xml'] ?? null,
            'external_id' => $body['external_id'] ?? null,
            'raw' => $body,
        ];
    }

    /**
     * Busca el mensaje legible en una respuesta de ValidaPSE,
     * probando los nombres de campo conocidos en orden.
     */
    private static function extractMessage(array $body): ?string
    {
        foreach (['mensaje', 'message', 'errores', 'errors', 'error'] as $key) {
            $value = $body[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function safeBody(Response $response): mixed
    {
        try {
            return $response->json() ?? $response->body();
        } catch (Throwable) {
            return $response->body();
        }
    }

    private function safeMessage(Response $response): ?string
    {
        $body = $this->safeBody($response);
        if (is_array($body)) {
            return self::extractMessage($body);
        }
        return null;
    }
}
