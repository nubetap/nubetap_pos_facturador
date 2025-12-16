<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
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
            'ruc' => $this->ruc,
            'razon_social' => $this->razon_social,
            'nombre_comercial' => $this->nombre_comercial,
            'direccion' => $this->direccion,
            'ubigeo' => $this->ubigeo,
            'distrito' => $this->distrito,
            'provincia' => $this->provincia,
            'departamento' => $this->departamento,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'logo' => $this->logo,

            'configuracion' => [
                'modo' => $this->modo_produccion ? 'PRODUCCIÓN' : 'BETA',
                'activo' => (bool) $this->activo,
                'usuario_sol' => $this->usuario_sol,
                'has_gre_credentials' => $this->hasGreCredentials()
            ],

            'estadisticas' => $this->when($request->input('include_stats'), function() {
                return [
                    'total_facturas' => $this->invoices()->count(),
                    'total_boletas' => $this->boletas()->count(),
                    'sucursales' => $this->branches()->count()
                ];
            }),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
