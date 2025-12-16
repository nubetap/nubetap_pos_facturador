<?php

namespace App\DTOs;

class SunatResponseDTO extends BaseDTO
{
    public function __construct(
        public bool $success,
        public ?string $cdr_hash = null,
        public ?string $cdr_notes = null,
        public ?string $sunat_code = null,
        public ?string $sunat_description = null,
        public ?string $error_message = null,
        public ?string $error_code = null,
        public ?array $metadata = []
    ) {}

    /**
     * Create from SUNAT success response
     */
    public static function fromSuccess(array $response): static
    {
        return new static(
            success: true,
            cdr_hash: $response['cdr_hash'] ?? null,
            cdr_notes: $response['cdr_notes'] ?? null,
            sunat_code: $response['code'] ?? '0',
            sunat_description: $response['description'] ?? 'Aceptado',
            metadata: $response
        );
    }

    /**
     * Create from SUNAT error response
     */
    public static function fromError(\Throwable $error, ?string $sunatCode = null): static
    {
        return new static(
            success: false,
            error_message: $error->getMessage(),
            error_code: $sunatCode ?? $error->getCode(),
            metadata: [
                'exception_class' => get_class($error),
                'file' => $error->getFile(),
                'line' => $error->getLine()
            ]
        );
    }

    /**
     * Check if document was accepted
     */
    public function isAccepted(): bool
    {
        return $this->success && in_array($this->sunat_code, ['0', null]);
    }

    /**
     * Check if document was rejected
     */
    public function isRejected(): bool
    {
        return !$this->success;
    }

    /**
     * Get formatted response for storage
     */
    public function toStorageArray(): array
    {
        return [
            'estado_sunat' => $this->success ? 'ACEPTADO' : 'RECHAZADO',
            'respuesta_sunat' => json_encode($this->toArray()),
            'hash_cdr' => $this->cdr_hash,
            'notas_cdr' => $this->cdr_notes,
        ];
    }
}
