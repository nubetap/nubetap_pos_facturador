<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoidedDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'tipo_documento',
        'identificador',
        'correlativo',
        'fecha_emision',
        'fecha_referencia',
        'ubl_version',
        'detalles',
        'motivo_baja',
        'total_documentos',
        'xml_path',
        'cdr_path',
        'estado_sunat',
        'respuesta_sunat',
        'ticket',
        'usuario_creacion',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_referencia' => 'date',
        'detalles' => 'array',
        'total_documentos' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function getTipoDocumentoNameAttribute(): string
    {
        return 'Comunicación de Baja';
    }

    public function getEstadoSunatColorAttribute(): string
    {
        return match($this->estado_sunat) {
            'PENDIENTE' => 'warning',
            'ENVIADO' => 'info',
            'PROCESANDO' => 'info',
            'ACEPTADO' => 'success',
            'RECHAZADO' => 'danger',
            default => 'secondary'
        };
    }

    public function scopePending($query)
    {
        return $query->where('estado_sunat', 'PENDIENTE');
    }

    public function scopeSent($query)
    {
        return $query->whereIn('estado_sunat', ['ENVIADO', 'PROCESANDO']);
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
        return $query->whereBetween('fecha_emision', [$startDate, $endDate]);
    }

    public function scopeByReferenceDate($query, $referenceDate)
    {
        return $query->where('fecha_referencia', $referenceDate);
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($voidedDocument) {
            if (empty($voidedDocument->identificador)) {
                $company = $voidedDocument->company ?? Company::find($voidedDocument->company_id);
                $fechaRef = $voidedDocument->fecha_referencia->format('Ymd');
                $voidedDocument->identificador = $company->ruc . '-RA-' . $fechaRef . '-' . $voidedDocument->correlativo;
            }
            
            if (empty($voidedDocument->total_documentos) && !empty($voidedDocument->detalles)) {
                $voidedDocument->total_documentos = count($voidedDocument->detalles);
            }
        });
    }
}
