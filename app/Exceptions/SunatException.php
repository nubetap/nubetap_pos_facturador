<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;

class SunatException extends Exception
{
    /**
     * Constructor de la excepción SUNAT
     *
     * @param string $userMessage Mensaje amigable para el usuario
     * @param string $sunatCode Código de error SUNAT
     * @param array $context Contexto adicional para logging
     * @param int $httpCode Código HTTP de respuesta (default: 400)
     */
    public function __construct(
        public string $userMessage,
        public string $sunatCode = '',
        public array $context = [],
        public int $httpCode = 400
    ) {
        parent::__construct($userMessage);
    }

    /**
     * Reportar la excepción en los logs
     */
    public function report(): void
    {
        Log::error('SUNAT Exception', [
            'code' => $this->sunatCode,
            'message' => $this->userMessage,
            'context' => $this->context,
            'trace' => $this->getTraceAsString()
        ]);
    }

    /**
     * Renderizar la respuesta HTTP
     */
    public function render($request)
    {
        // Si es una petición API, devolver JSON
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => $this->userMessage,
                'error_code' => $this->sunatCode,
                'timestamp' => now()->toISOString()
            ], $this->httpCode);
        }

        // Para peticiones web, redirigir con error
        return redirect()->back()->withErrors([
            'error' => $this->userMessage
        ]);
    }
}
