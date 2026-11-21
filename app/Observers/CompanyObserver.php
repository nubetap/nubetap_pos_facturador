<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\CompanyEnvironmentChange;
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
     * Rastro persistente de cada cambio de ambiente (modo_produccion,
     * cpe_provider, gre_modo_produccion): quién, cuándo y desde dónde.
     * Los logs caducan; esta tabla no. Se lee en
     * GET /api/v1/companies/{company}/environment-changes.
     */
    public function updated(Company $company): void
    {
        $changes = array_intersect_key($company->getChanges(), array_flip(CompanyEnvironmentChange::WATCHED));
        if (!$changes) {
            return;
        }

        // Petición HTTP con ruta resuelta = API/panel; si no, comando o job.
        $request = request();
        $viaHttp = $request->route() !== null;
        $source = $viaHttp ? ($request->method() . ' /' . ltrim($request->path(), '/')) : 'console';

        foreach ($changes as $field => $new) {
            CompanyEnvironmentChange::create([
                'company_id' => $company->id,
                'field' => $field,
                'old_value' => CompanyEnvironmentChange::stringify($company->getOriginal($field)),
                'new_value' => CompanyEnvironmentChange::stringify($company->getAttribute($field)),
                'source' => mb_substr($source, 0, 191),
                'user_id' => auth()->id(),
                'ip' => $viaHttp ? $request->ip() : null,
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
