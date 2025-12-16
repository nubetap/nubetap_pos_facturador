<?php

namespace App\DTOs;

class ClientDTO extends BaseDTO
{
    public function __construct(
        public int $company_id,
        public string $tipo_documento,
        public string $numero_documento,
        public string $razon_social,
        public ?string $nombre_comercial = null,
        public ?string $direccion = null,
        public ?string $ubigeo = null,
        public ?string $telefono = null,
        public ?string $email = null,
        public bool $activo = true
    ) {
        $this->validateData();
    }

    /**
     * Validate DTO data
     */
    protected function validateData(): void
    {
        // Validar tipo de documento
        $tiposDocumento = ['1', '6', '7', '0', '4', 'A']; // DNI, RUC, Pasaporte, etc.
        if (!in_array($this->tipo_documento, $tiposDocumento)) {
            throw new \InvalidArgumentException("Tipo de documento inválido: {$this->tipo_documento}");
        }

        // Validar longitud de número de documento
        $this->validateDocumentLength();

        // Validar email si está presente
        if ($this->email && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Email inválido: {$this->email}");
        }

        // Validar ubigeo si está presente
        if ($this->ubigeo && !preg_match('/^\d{6}$/', $this->ubigeo)) {
            throw new \InvalidArgumentException("Ubigeo debe tener 6 dígitos: {$this->ubigeo}");
        }
    }

    /**
     * Validate document length based on type
     */
    protected function validateDocumentLength(): void
    {
        $length = strlen($this->numero_documento);

        switch ($this->tipo_documento) {
            case '1': // DNI
                if ($length !== 8) {
                    throw new \InvalidArgumentException("DNI debe tener 8 dígitos");
                }
                break;
            case '6': // RUC
                if ($length !== 11) {
                    throw new \InvalidArgumentException("RUC debe tener 11 dígitos");
                }
                break;
            case '7': // Pasaporte
                if ($length < 5 || $length > 12) {
                    throw new \InvalidArgumentException("Pasaporte debe tener entre 5 y 12 caracteres");
                }
                break;
            case '4': // Carnet de extranjería
                if ($length < 8 || $length > 12) {
                    throw new \InvalidArgumentException("Carnet de extranjería debe tener entre 8 y 12 caracteres");
                }
                break;
        }
    }

    /**
     * Check if client is company (RUC)
     */
    public function isCompany(): bool
    {
        return $this->tipo_documento === '6';
    }

    /**
     * Check if client is person (DNI)
     */
    public function isPerson(): bool
    {
        return $this->tipo_documento === '1';
    }
}
