<?php

namespace App\Exceptions;

class DocumentAlreadySentException extends SunatException
{
    public function __construct(string $documentType, string $documentNumber)
    {
        parent::__construct(
            userMessage: "El documento {$documentType} {$documentNumber} ya fue enviado y aceptado por SUNAT",
            sunatCode: 'DOC_ALREADY_SENT',
            context: [
                'document_type' => $documentType,
                'document_number' => $documentNumber
            ],
            httpCode: 409 // Conflict
        );
    }
}
