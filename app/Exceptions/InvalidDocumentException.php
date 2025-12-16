<?php

namespace App\Exceptions;

class InvalidDocumentException extends SunatException
{
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct(
            userMessage: $message,
            sunatCode: 'INVALID_DOCUMENT',
            context: ['validation_errors' => $errors],
            httpCode: 422 // Unprocessable Entity
        );
    }
}
