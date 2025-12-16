<?php

namespace App\DTOs;

class CompanyDTO extends BaseDTO
{
    public function __construct(
        public string $ruc,
        public string $razon_social,
        public string $nombre_comercial,
        public string $direccion_fiscal,
        public string $ubigeo,
        public ?string $urbanizacion = null,
        public ?string $departamento = null,
        public ?string $provincia = null,
        public ?string $distrito = null,
        public ?string $codigo_pais = 'PE',
        public ?string $telefono = null,
        public ?string $email = null,
        public ?string $logo = null,
        public ?string $usuario_sol = null,
        public ?string $clave_sol = null,
        public ?string $certificado_digital = null,
        public ?string $clave_certificado = null,
        public bool $modo_produccion = false,
        public bool $activo = true
    ) {
        $this->validateData();
    }

    /**
     * Validate DTO data
     */
    protected function validateData(): void
    {
        // Validar RUC
        if (!preg_match('/^\d{11}$/', $this->ruc)) {
            throw new \InvalidArgumentException("RUC debe tener 11 dígitos");
        }

        // Validar ubigeo
        if (!preg_match('/^\d{6}$/', $this->ubigeo)) {
            throw new \InvalidArgumentException("Ubigeo debe tener 6 dígitos: {$this->ubigeo}");
        }

        // Validar email si está presente
        if ($this->email && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Email inválido: {$this->email}");
        }

        // Validar credenciales SOL en modo producción
        if ($this->modo_produccion) {
            if (empty($this->usuario_sol) || empty($this->clave_sol)) {
                throw new \InvalidArgumentException("Credenciales SOL requeridas para modo producción");
            }

            if (empty($this->certificado_digital) || empty($this->clave_certificado)) {
                throw new \InvalidArgumentException("Certificado digital requerido para modo producción");
            }
        }
    }

    /**
     * Check if company is in production mode
     */
    public function isProduction(): bool
    {
        return $this->modo_produccion;
    }

    /**
     * Check if company has valid credentials
     */
    public function hasValidCredentials(): bool
    {
        return !empty($this->usuario_sol)
            && !empty($this->clave_sol)
            && !empty($this->certificado_digital)
            && !empty($this->clave_certificado);
    }
}
