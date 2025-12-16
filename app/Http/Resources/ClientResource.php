<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo_documento' => $this->tipo_documento,
            'tipo_documento_nombre' => $this->getTipoDocumentoNombre(),
            'numero_documento' => $this->numero_documento,
            'razon_social' => $this->razon_social,
            'nombre_comercial' => $this->nombre_comercial,
            'direccion' => $this->direccion,
            'ubigeo' => $this->ubigeo,
            'distrito' => $this->distrito,
            'provincia' => $this->provincia,
            'departamento' => $this->departamento,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Get tipo documento nombre
     */
    protected function getTipoDocumentoNombre(): string
    {
        return match($this->tipo_documento) {
            '0' => 'DOC. TRIB. NO DOM. SIN RUC',
            '1' => 'DNI',
            '4' => 'CARNET DE EXTRANJERÍA',
            '6' => 'RUC',
            '7' => 'PASAPORTE',
            '11' => 'PARTIDA DE NACIMIENTO',
            '12' => 'CÉDULA DIPLOMÁTICA',
            default => 'OTROS'
        };
    }
}
