<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailySummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'correlativo',
        'numero_completo',
        'fecha_generacion',
        'fecha_resumen',
        'ubl_version',
        'moneda',
        'estado_proceso',
        'detalles',
        'xml_path',
        'cdr_path',
        'pdf_path',
        'estado_sunat',
        'respuesta_sunat',
        'ticket',
        'codigo_hash',
        'usuario_creacion',
    ];

    protected $casts = [
        'fecha_generacion' => 'date',
        'fecha_resumen' => 'date',
        'detalles' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function boletas(): HasMany
    {
        return $this->hasMany(Boleta::class, 'daily_summary_id');
    }

    public function getTipoDocumentoNameAttribute(): string
    {
        return 'Resumen Diario de Boletas';
    }

    public function getEstadoSunatColorAttribute(): string
    {
        return match($this->estado_sunat) {
            'PENDIENTE' => 'warning',
            'PROCESANDO' => 'info',
            'ACEPTADO' => 'success',
            'RECHAZADO' => 'danger',
            default => 'secondary'
        };
    }

    public function getEstadoProcesoColorAttribute(): string
    {
        return match($this->estado_proceso) {
            'GENERADO' => 'primary',
            'ENVIADO' => 'info',
            'PROCESANDO' => 'warning',
            'COMPLETADO' => 'success',
            'ERROR' => 'danger',
            default => 'secondary'
        };
    }

    public function scopePending($query)
    {
        return $query->where('estado_sunat', 'PENDIENTE');
    }

    public function scopeProcessing($query)
    {
        return $query->where('estado_sunat', 'PROCESANDO');
    }

    public function scopeAccepted($query)
    {
        return $query->where('estado_sunat', 'ACEPTADO');
    }

    public function scopeRejected($query)
    {
        return $query->where('estado_sunat', 'RECHAZADO');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('fecha_resumen', [$startDate, $endDate]);
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($summary) {
            if (empty($summary->numero_completo)) {
                $fecha = \Carbon\Carbon::parse($summary->fecha_resumen);
                $summary->numero_completo = 'RC-' . $fecha->format('Ymd') . '-' . $summary->correlativo;
            }
        });
    }
}
