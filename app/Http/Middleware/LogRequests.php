<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogRequests
{
    /**
     * Campos sensibles que se ocultan del log.
     */
    protected array $hiddenFields = [
        'password',
        'password_confirmation',
        'clave_sol',
        'usuario_sol',
        'token',
        'secret',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $duration = round((microtime(true) - $start) * 1000);

        $this->logRequest($request, $response, $duration);

        return $response;
    }

    protected function logRequest(Request $request, Response $response, float $duration): void
    {
        $method = $request->method();
        $url = $request->fullUrl();
        $status = $response->getStatusCode();

        // Solo loguear body en requests que envían datos
        $body = null;
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $body = $this->sanitize($request->all());
        }

        $logData = [
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'duration_ms' => $duration,
            'ip' => $request->ip(),
        ];

        if ($body !== null) {
            $logData['body'] = $body;
        }

        // Loguear respuesta solo si hubo error
        if ($status >= 400) {
            $responseContent = $response->getContent();
            $decoded = json_decode($responseContent, true);
            $logData['response'] = $decoded ?? mb_substr($responseContent, 0, 500);
        }

        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');

        Log::$level("[API] {$method} {$request->path()} → {$status} ({$duration}ms)", $logData);
    }

    protected function sanitize(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (in_array(strtolower($key), $this->hiddenFields)) {
                $value = '***';
            } elseif (is_array($value)) {
                $value = $this->sanitize($value);
            }
        }

        return $data;
    }
}
