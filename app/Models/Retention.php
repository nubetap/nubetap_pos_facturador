<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Retention extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'proveedor_id',
        'serie',
        'correlativo',
        'numero_completo',
        'fecha_emision',
        'regimen',
        'tasa',
        'observacion',
        'imp_retenido',
        'imp_pagado',
        'moneda',
        'detalles',
        'estado_sunat',
        'hash_cdr',
        'xml_path',
        'cdr_path',
        'pdf_path',
        'observaciones'
    ];

    protected $casts = [
        'fecha_emision' => 'datetime',
        'detalles' => 'array',
        'tasa' => 'decimal:2',
        'imp_retenido' => 'decimal:2',
        'imp_pagado' => 'decimal:2'
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'proveedor_id');
    }

    protected static function booted()
    {
        static::creating(function ($retention) {
            if (empty($retention->numero_completo)) {
                $retention->numero_completo = $retention->serie . '-' . str_pad($retention->correlativo, 6, '0', STR_PAD_LEFT);
            }
        });
    }
}