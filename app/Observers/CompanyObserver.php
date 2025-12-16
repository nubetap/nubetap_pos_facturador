<?php

namespace App\Observers;

use App\Models\Company;
use Illuminate\Support\Facades\Log;

class CompanyObserver
{
    /**
     * Handle the Company "created" event.
     */
    public function created(Company $company): void
    {
        Log::info('Nueva empresa registrada', [
            'company_id' => $company->id,
            'ruc' => $company->ruc,
            'razon_social' => $company->razon_social,
            'created_by' => auth()->user()->email ?? 'system'
        ]);
    }

    /**
     * Handle the Company "updating" event.
     */
    public function updating(Company $company): void
    {
        // Auditar cambios en credenciales SOL
        if ($company->isDirty('usuario_sol') || $company->isDirty('clave_sol')) {
            Log::channel('audit')->warning('Credenciales SOL modificadas', [
                'company_id' => $company->id,
                'ruc' => $company->ruc,
                'usuario_sol_changed' => $company->isDirty('usuario_sol'),
                'clave_sol_changed' => $company->isDirty('clave_sol'),
                'modified_by' => auth()->user()->email ?? 'system',
                'ip' => request()->ip()
            ]);
        }

        // Auditar cambio de modo (beta/producción)
        if ($company->isDirty('modo_produccion')) {
            Log::channel('audit')->warning('Modo de operación cambiado', [
                'company_id' => $company->id,
                'ruc' => $company->ruc,
                'old_mode' => $company->getOriginal('modo_produccion') ? 'PRODUCCIÓN' : 'BETA',
                'new_mode' => $company->modo_produccion ? 'PRODUCCIÓN' : 'BETA',
                'modified_by' => auth()->user()->email ?? 'system',
                'ip' => request()->ip()
            ]);
        }

        // Auditar cambio de estado activo
        if ($company->isDirty('activo')) {
            Log::channel('audit')->warning('Estado de empresa modificado', [
                'company_id' => $company->id,
                'ruc' => $company->ruc,
                'old_status' => $company->getOriginal('activo') ? 'ACTIVA' : 'INACTIVA',
                'new_status' => $company->activo ? 'ACTIVA' : 'INACTIVA',
                'modified_by' => auth()->user()->email ?? 'system'
            ]);
        }
    }

    /**
     * Handle the Company "deleted" event.
     */
    public function deleted(Company $company): void
    {
        Log::channel('audit')->critical('Empresa eliminada', [
            'company_id' => $company->id,
            'ruc' => $company->ruc,
            'razon_social' => $company->razon_social,
            'deleted_by' => auth()->user()->email ?? 'system',
            'timestamp' => now()->toISOString()
        ]);
    }
}
