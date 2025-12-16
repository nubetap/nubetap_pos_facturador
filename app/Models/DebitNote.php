<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebitNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'client_id',
        'tipo_documento',
        'serie',
        'correlativo',
        'numero_completo',
        'tipo_doc_afectado',
        'num_doc_afectado',
        'cod_motivo',
        'des_motivo',
        'fecha_emision',
        'ubl_version',
        'moneda',
        'valor_venta',
        'mto_oper_gravadas',
        'mto_oper_exoneradas',
        'mto_oper_inafectas',
        'mto_igv',
        'mto_isc',
        'total_impuestos',
        'mto_imp_venta',
        'detalles',
        'leyendas',
        'datos_adicionales',
        'xml_path',
        'cdr_path',
        'pdf_path',
        'estado_sunat',
        'respuesta_sunat',
        'codigo_hash',
        'usuario_creacion',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'valor_venta' => 'decimal:2',
        'mto_oper_gravadas' => 'decimal:2',
        'mto_oper_exoneradas' => 'decimal:2',
        'mto_oper_inafectas' => 'decimal:2',
        'mto_igv' => 'decimal:2',
        'mto_isc' => 'decimal:2',
        'total_impuestos' => 'decimal:2',
        'mto_imp_venta' => 'decimal:2',
        'detalles' => 'array',
        'leyendas' => 'array',
        'datos_adicionales' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function getTipoDocumentoNameAttribute(): string
    {
        return 'Nota de Débito Electrónica';
    }

    public function getDocumentoAfectadoNameAttribute(): string
    {
        return match($this->tipo_doc_afectado) {
            '01' => 'Factura',
            '03' => 'Boleta',
            default => 'Documento'
        };
    }

    public function getMotivoNameAttribute(): string
    {
        return match($this->cod_motivo) {
            '01' => 'Intereses por mora',
            '02' => 'Aumento en el valor',
            '03' => 'Penalidades/ otros conceptos',
            '10' => 'Ajustes de operaciones de exportación',
            '11' => 'Ajustes afectos al IVAP',
            default => $this->des_motivo
        };
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($note) {
            if (empty($note->numero_completo)) {
                $note->numero_completo = $note->serie . '-' . $note->correlativo;
            }
        });
    }
}