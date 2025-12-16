<?php

namespace App\Repositories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ClientRepository extends BaseRepository
{
    /**
     * Specify Model class name
     */
    protected function model(): string
    {
        return Client::class;
    }

    /**
     * Find client by document number
     */
    public function findByDocument(string $tipoDocumento, string $numeroDocumento): ?Client
    {
        return $this->model
            ->where('tipo_documento', $tipoDocumento)
            ->where('numero_documento', $numeroDocumento)
            ->first();
    }

    /**
     * Get clients with purchase statistics
     */
    public function getWithStatistics(int $companyId): Collection
    {
        return $this->model
            ->select([
                'clients.*',
                DB::raw('COUNT(invoices.id) as total_invoices'),
                DB::raw('SUM(invoices.mto_imp_venta) as total_purchases'),
                DB::raw('MAX(invoices.fecha_emision) as last_purchase_date'),
            ])
            ->leftJoin('invoices', 'clients.id', '=', 'invoices.client_id')
            ->where('clients.company_id', $companyId)
            ->groupBy('clients.id')
            ->orderBy('total_purchases', 'desc')
            ->get();
    }

    /**
     * Search clients
     */
    public function search(int $companyId, string $term): Collection
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where(function ($query) use ($term) {
                $query->where('razon_social', 'like', "%{$term}%")
                      ->orWhere('nombre_comercial', 'like', "%{$term}%")
                      ->orWhere('numero_documento', 'like', "%{$term}%");
            })
            ->limit(20)
            ->get();
    }

    /**
     * Get top clients by company
     */
    public function getTopByRevenue(int $companyId, int $limit = 10): Collection
    {
        return $this->model
            ->select([
                'clients.*',
                DB::raw('COUNT(invoices.id) as invoice_count'),
                DB::raw('SUM(invoices.mto_imp_venta) as total_revenue'),
            ])
            ->join('invoices', 'clients.id', '=', 'invoices.client_id')
            ->where('clients.company_id', $companyId)
            ->where('invoices.estado_sunat', 'ACEPTADO')
            ->groupBy('clients.id')
            ->orderBy('total_revenue', 'desc')
            ->limit($limit)
            ->get();
    }
}
