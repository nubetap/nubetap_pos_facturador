<?php

namespace App\Exceptions;

use Exception;

/**
 * Excepción para errores del proveedor PSE externo ValidaPSE.
 *
 * Se lanza desde ValidapseClient cuando una llamada HTTP falla por:
 * - Error de red / timeout.
 * - Respuesta 4xx/5xx (token inválido, cuota agotada, RUC no registrado, etc.).
 * - Body con isSuccess=false (validación de negocio del lado ValidaPSE).
 *
 * Doc: docs/INTEGRACION-VALIDAPSE-NRUS.md
 */
class ValidapseException extends Exception
{
    public function __construct(
        public string $userMessage,
        public ?int $httpStatus = null,
        public array $context = [],
    ) {
        parent::__construct($userMessage);
    }

    public static function networkError(string $reason, array $context = []): self
    {
        return new self(
            userMessage: "Error de red con ValidaPSE: {$reason}",
            httpStatus: null,
            context: $context,
        );
    }

    public static function http(int $status, string $message, array $context = []): self
    {
        return new self(
            userMessage: "ValidaPSE respondió HTTP {$status}: {$message}",
            httpStatus: $status,
            context: $context,
        );
    }

    public static function rejected(string $message, array $context = []): self
    {
        return new self(
            userMessage: "ValidaPSE rechazó la operación: {$message}",
            httpStatus: 200,
            context: $context,
        );
    }
}
