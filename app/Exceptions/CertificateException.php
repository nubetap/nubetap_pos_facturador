<?php

namespace App\Exceptions;

class CertificateException extends SunatException
{
    public function __construct(string $detail)
    {
        parent::__construct(
            userMessage: "Error en el certificado digital: {$detail}",
            sunatCode: 'CERTIFICATE_ERROR',
            context: ['detail' => $detail],
            httpCode: 500
        );
    }

    public static function notFound(string $path): self
    {
        return new self("Certificado no encontrado en: {$path}");
    }

    public static function invalid(): self
    {
        return new self("El certificado PEM no tiene una estructura válida");
    }

    public static function expired(): self
    {
        return new self("El certificado ha expirado");
    }
}
