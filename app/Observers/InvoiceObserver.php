<?php

namespace App\Observers;

use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class InvoiceObserver
{
    /**
     * Handle the Invoice "creating" event.
     */
    public function creating(Invoice $invoice): void
    {
        // Registrar usuario que crea el documento si no está establecido
        if (empty($invoice->usuario_creacion) && auth()->check()) {
            $invoice->usuario_creacion = auth()->user()->name;
        }

        // Estado inicial
        if (empty($invoice->estado_sunat)) {
            $invoice->estado_sunat = 'PENDIENTE';
        }

        Log::info('Factura siendo creada', [
            'serie' => $invoice->serie,
            'correlativo' => $invoice->correlativo,
            'company_id' => $invoice->company_id,
            'branch_id' => $invoice->branch_id,
            'client_id' => $invoice->client_id,
            'monto' => $invoice->mto_imp_venta,
            'moneda' => $invoice->moneda,
            'usuario' => $invoice->usuario_creacion ?? 'System'
        ]);
    }

    /**
     * Handle the Invoice "created" event.
     */
    public function created(Invoice $invoice): void
    {
        Log::info('Factura creada exitosamente', [
            'invoice_id' => $invoice->id,
            'numero' => $invoice->numero_completo,
            'company_id' => $invoice->company_id,
            'monto' => $invoice->mto_imp_venta
        ]);
    }

    /**
     * Handle the Invoice "updating" event.
     */
    public function updating(Invoice $invoice): void
    {
        // Detectar cambios críticos
        $criticalFields = [
            'estado_sunat',
            'mto_imp_venta',
            'respuesta_sunat',
            'xml_path',
            'cdr_path'
        ];

        $changes = [];
        foreach ($criticalFields as $field) {
            if ($invoice->isDirty($field)) {
                $changes[$field] = [
                    'old' => $invoice->getOriginal($field),
                    'new' => $invoice->$field
                ];
            }
        }

        if (!empty($changes)) {
            Log::channel('audit')->info('Factura siendo actualizada', [
                'invoice_id' => $invoice->id,
                'numero' => $invoice->numero_completo,
                'changes' => $changes,
                'user' => auth()->user()->email ?? 'system',
                'ip' => request()->ip()
            ]);
        }
    }

    /**
     * Handle the Invoice "updated" event.
     */
    public function updated(Invoice $invoice): void
    {
        // Registro especial para cambios de estado SUNAT
        if ($invoice->wasChanged('estado_sunat')) {
            $level = match($invoice->estado_sunat) {
                'ACEPTADO' => 'info',
                'RECHAZADO' => 'warning',
                'ERROR' => 'error',
                default => 'debug'
            };

            Log::channel('sunat')->log($level, 'Estado SUNAT modificado', [
                'invoice_id' => $invoice->id,
                'numero' => $invoice->numero_completo,
                'old_status' => $invoice->getOriginal('estado_sunat'),
                'new_status' => $invoice->estado_sunat,
                'company_id' => $invoice->company_id,
                'timestamp' => now()->toISOString()
            ]);
        }
    }

    /**
     * Handle the Invoice "deleting" event.
     */
    public function deleting(Invoice $invoice): void
    {
        Log::channel('audit')->warning('Factura siendo eliminada', [
            'invoice_id' => $invoice->id,
            'numero' => $invoice->numero_completo,
            'company_id' => $invoice->company_id,
            'estado_sunat' => $invoice->estado_sunat,
            'monto' => $invoice->mto_imp_venta,
            'deleted_by' => auth()->user()->email ?? 'system',
            'ip' => request()->ip()
        ]);
    }

    /**
     * Handle the Invoice "deleted" event.
     */
    public function deleted(Invoice $invoice): void
    {
        Log::channel('audit')->critical('Factura eliminada permanentemente', [
            'invoice_id' => $invoice->id,
            'numero' => $invoice->numero_completo,
            'deleted_at' => now()->toISOString()
        ]);
    }

    /**
     * Handle the Invoice "restored" event.
     */
    public function restored(Invoice $invoice): void
    {
        Log::channel('audit')->info('Factura restaurada', [
            'invoice_id' => $invoice->id,
            'numero' => $invoice->numero_completo,
            'restored_by' => auth()->user()->email ?? 'system'
        ]);
    }
}
