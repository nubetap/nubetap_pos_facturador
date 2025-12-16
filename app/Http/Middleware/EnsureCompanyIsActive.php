<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Obtener company_id de diferentes fuentes
        $companyId = $this->extractCompanyId($request);

        if ($companyId) {
            $company = Company::find($companyId);

            // Verificar si la empresa existe
            if (!$company) {
                Log::warning('Intento de acceso con empresa inexistente', [
                    'company_id' => $companyId,
                    'user_id' => auth()->id(),
                    'ip' => $request->ip(),
                    'endpoint' => $request->path()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'La empresa especificada no existe.',
                    'error_code' => 'COMPANY_NOT_FOUND'
                ], 404);
            }

            // Verificar si la empresa está activa
            if (!$company->activo) {
                Log::warning('Intento de acceso con empresa inactiva', [
                    'company_id' => $companyId,
                    'company_ruc' => $company->ruc,
                    'user_id' => auth()->id(),
                    'ip' => $request->ip(),
                    'endpoint' => $request->path()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'La empresa está inactiva. Contacte con el administrador para reactivarla.',
                    'error_code' => 'COMPANY_INACTIVE',
                    'company' => [
                        'id' => $company->id,
                        'ruc' => $company->ruc,
                        'razon_social' => $company->razon_social
                    ]
                ], 403);
            }

            // Agregar empresa al request para uso posterior sin consultas adicionales
            $request->merge(['_company' => $company]);
            $request->attributes->set('company', $company);
        }

        return $next($request);
    }

    /**
     * Extraer company_id del request
     */
    protected function extractCompanyId(Request $request): ?int
    {
        // 1. Desde parámetros del body (POST/PUT)
        if ($request->has('company_id')) {
            return (int) $request->input('company_id');
        }

        // 2. Desde parámetros de la URL (GET)
        if ($request->query('company_id')) {
            return (int) $request->query('company_id');
        }

        // 3. Desde route parameters
        if ($request->route('company_id')) {
            return (int) $request->route('company_id');
        }

        // 4. Si está en el route parameters como 'id' y la ruta contiene 'companies'
        if ($request->route('id') && str_contains($request->path(), 'companies')) {
            return (int) $request->route('id');
        }

        return null;
    }
}

