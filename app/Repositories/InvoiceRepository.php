<?php

namespace App\Repositories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InvoiceRepository extends BaseRepository
{
    /**
     * Specify Model class name
     */
    protected function model(): string
    {
        return Invoice::class;
    }

    /**
     * Find invoice with all relations
     */
    public function findWithRelations(int $id): ?Invoice
    {
        return $this->model
            ->with(['company', 'client', 'branch'])
            ->find($id);
    }

    /**
     * Get pending invoices to send to SUNAT
     */
    public function getPendingSunat(int $companyId, int $limit = 50): Collection
    {
        return $this->model
            ->where('company_id', $companyId)
            ->whereIn('estado_sunat', ['PENDIENTE', 'ERROR'])
            ->whereNull('deleted_at')
            ->orderBy('fecha_emision', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get invoices by date range with filters
     */
    public function getByDateRange(array $filters): LengthAwarePaginator
    {
        $query = $this->model
            ->with(['company', 'client'])
            ->when(isset($filters['company_id']), fn($q) => $q->where('company_id', $filters['company_id']))
            ->when(isset($filters['client_id']), fn($q) => $q->where('client_id', $filters['client_id']))
            ->when(isset($filters['estado_sunat']), fn($q) => $q->where('estado_sunat', $filters['estado_sunat']))
            ->when(isset($filters['fecha_inicio']), fn($q) => $q->whereDate('fecha_emision', '>=', $filters['fecha_inicio']))
            ->when(isset($filters['fecha_fin']), fn($q) => $q->whereDate('fecha_emision', '<=', $filters['fecha_fin']))
            ->when(isset($filters['moneda']), fn($q) => $q->where('moneda', $filters['moneda']))
            ->when(isset($filters['numero']), fn($q) => $q->where('numero_completo', 'like', '%' . $filters['numero'] . '%'))
            ->orderBy('fecha_emision', 'desc')
            ->orderBy('serie', 'desc')
            ->orderBy('correlativo', 'desc');

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get totals by period (for dashboard)
     */
    public function getTotalsByPeriod(int $companyId, string $startDate, string $endDate, string $moneda = 'PEN'): array
    {
        return $this->model
            ->select([
                DB::raw('COUNT(*) as total_documentos'),
                DB::raw('SUM(CASE WHEN estado_sunat = "ACEPTADO" THEN 1 ELSE 0 END) as aceptados'),
                DB::raw('SUM(CASE WHEN estado_sunat = "RECHAZADO" THEN 1 ELSE 0 END) as rechazados'),
                DB::raw('SUM(CASE WHEN estado_sunat = "PENDIENTE" THEN 1 ELSE 0 END) as pendientes'),
                DB::raw('SUM(CASE WHEN estado_sunat = "ACEPTADO" THEN mto_imp_venta ELSE 0 END) as total_monto'),
                DB::raw('SUM(total_igv) as total_igv'),
                DB::raw('SUM(total_exportacion + total_operaciones_gravadas + total_operaciones_exoneradas + total_operaciones_inafectas) as total_gravable'),
            ])
            ->where('company_id', $companyId)
            ->where('moneda', $moneda)
            ->whereDate('fecha_emision', '>=', $startDate)
            ->whereDate('fecha_emision', '<=', $endDate)
            ->whereNull('deleted_at')
            ->first()
            ->toArray();
    }

    /**
     * Get invoices by client with statistics
     */
    public function getByClient(int $clientId, int $limit = 20): array
    {
        $invoices = $this->model
            ->where('client_id', $clientId)
            ->with(['company'])
            ->orderBy('fecha_emision', 'desc')
            ->limit($limit)
            ->get();

        $stats = $this->model
            ->select([
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(mto_imp_venta) as monto_total'),
                DB::raw('AVG(mto_imp_venta) as monto_promedio'),
            ])
            ->where('client_id', $clientId)
            ->where('estado_sunat', 'ACEPTADO')
            ->first();

        return [
            'invoices' => $invoices,
            'statistics' => $stats
        ];
    }

    /**
     * Get invoices expiring soon (for payment follow-up)
     */
    public function getExpiringSoon(int $companyId, int $days = 7): Collection
    {
        // Las cuotas están almacenadas como JSON en forma_pago_cuotas
        // No como relación separada
        return $this->model
            ->where('company_id', $companyId)
            ->where('estado_sunat', 'ACEPTADO')
            ->where('forma_pago_tipo', 'Credito')
            ->whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '<=', now()->addDays($days))
            ->whereDate('fecha_vencimiento', '>=', now())
            ->with(['client'])
            ->get();
    }

    /**
     * Get top clients by revenue
     */
    public function getTopClientsByRevenue(int $companyId, int $limit = 10, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $query = $this->model
            ->select([
                'client_id',
                DB::raw('COUNT(*) as total_invoices'),
                DB::raw('SUM(mto_imp_venta) as total_revenue'),
                DB::raw('AVG(mto_imp_venta) as average_invoice'),
            ])
            ->with('client')
            ->where('company_id', $companyId)
            ->where('estado_sunat', 'ACEPTADO')
            ->groupBy('client_id');

        if ($startDate) {
            $query->whereDate('fecha_emision', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('fecha_emision', '<=', $endDate);
        }

        return $query->orderBy('total_revenue', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get documents requiring resend
     */
    public function getRequiringResend(int $companyId, int $maxAttempts = 3): Collection
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('estado_sunat', 'ERROR')
            ->where(function ($query) use ($maxAttempts) {
                $query->whereNull('intentos_envio')
                      ->orWhere('intentos_envio', '<', $maxAttempts);
            })
            ->orderBy('fecha_emision', 'asc')
            ->get();
    }

    /**
     * Get monthly sales summary
     */
    public function getMonthlySummary(int $companyId, int $year, int $month): array
    {
        $startDate = "{$year}-{$month}-01";
        $endDate = date('Y-m-t', strtotime($startDate));

        return [
            'PEN' => $this->getTotalsByPeriod($companyId, $startDate, $endDate, 'PEN'),
            'USD' => $this->getTotalsByPeriod($companyId, $startDate, $endDate, 'USD'),
        ];
    }

    /**
     * Bulk update status
     */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        return $this->model
            ->whereIn('id', $ids)
            ->update(['estado_sunat' => $status, 'updated_at' => now()]);
    }

    /**
     * Search invoices by multiple criteria
     */
    public function search(array $criteria, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->with(['company', 'client']);

        // Full-text search
        if (!empty($criteria['search'])) {
            $search = $criteria['search'];
            $query->where(function ($q) use ($search) {
                $q->where('numero_completo', 'like', "%{$search}%")
                  ->orWhere('observaciones', 'like', "%{$search}%")
                  ->orWhereHas('client', function ($clientQuery) use ($search) {
                      $clientQuery->where('razon_social', 'like', "%{$search}%")
                                  ->orWhere('numero_documento', 'like', "%{$search}%");
                  });
            });
        }

        // Exact filters
        foreach (['company_id', 'client_id', 'estado_sunat', 'moneda', 'tipo_operacion'] as $field) {
            if (!empty($criteria[$field])) {
                $query->where($field, $criteria[$field]);
            }
        }

        // Range filters
        if (!empty($criteria['monto_min'])) {
            $query->where('mto_imp_venta', '>=', $criteria['monto_min']);
        }
        if (!empty($criteria['monto_max'])) {
            $query->where('mto_imp_venta', '<=', $criteria['monto_max']);
        }

        return $query->orderBy('fecha_emision', 'desc')
                     ->paginate($perPage);
    }
}
