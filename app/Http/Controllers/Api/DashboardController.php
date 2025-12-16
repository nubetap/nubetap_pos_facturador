<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\InvoiceRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\ClientRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected $invoiceRepository;
    protected $companyRepository;
    protected $clientRepository;

    public function __construct(
        InvoiceRepository $invoiceRepository,
        CompanyRepository $companyRepository,
        ClientRepository $clientRepository
    ) {
        $this->invoiceRepository = $invoiceRepository;
        $this->companyRepository = $companyRepository;
        $this->clientRepository = $clientRepository;
    }

    /**
     * Get dashboard statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $companyId = $request->input('company_id');
            $startDate = $request->input('start_date', now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->input('end_date', now()->endOfMonth()->format('Y-m-d'));

            $data = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'totals_pen' => $this->invoiceRepository->getTotalsByPeriod($companyId, $startDate, $endDate, 'PEN'),
                'totals_usd' => $this->invoiceRepository->getTotalsByPeriod($companyId, $startDate, $endDate, 'USD'),
                'top_clients' => $this->invoiceRepository->getTopClientsByRevenue($companyId, 10, $startDate, $endDate),
                'pending_documents' => $this->invoiceRepository->getPendingSunat($companyId, 10),
                'expiring_invoices' => $this->invoiceRepository->getExpiringSoon($companyId, 7),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Estadísticas obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get monthly summary
     */
    public function monthlySummary(Request $request): JsonResponse
    {
        try {
            $companyId = $request->input('company_id');
            $year = $request->input('year', now()->year);
            $month = $request->input('month', now()->month);

            $summary = $this->invoiceRepository->getMonthlySummary($companyId, $year, $month);

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Resumen mensual obtenido correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener resumen mensual',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get client statistics
     */
    public function clientStatistics(Request $request): JsonResponse
    {
        try {
            $companyId = $request->input('company_id');
            $clientId = $request->input('client_id');

            if ($clientId) {
                $data = $this->invoiceRepository->getByClient($clientId, 20);
            } else {
                $data = $this->clientRepository->getWithStatistics($companyId);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Estadísticas de clientes obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas de clientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get documents requiring resend
     */
    public function requiresResend(Request $request): JsonResponse
    {
        try {
            $companyId = $request->input('company_id');
            $maxAttempts = $request->input('max_attempts', 3);

            $documents = $this->invoiceRepository->getRequiringResend($companyId, $maxAttempts);

            return response()->json([
                'success' => true,
                'data' => $documents,
                'count' => $documents->count(),
                'message' => 'Documentos pendientes de reenvío obtenidos correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener documentos pendientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get companies with expired certificates
     */
    public function expiredCertificates(Request $request): JsonResponse
    {
        try {
            $days = $request->input('days_before_expiration', 30);

            $companies = $this->companyRepository->getWithExpiredCertificates($days);

            return response()->json([
                'success' => true,
                'data' => $companies,
                'count' => $companies->count(),
                'message' => 'Empresas con certificados por vencer obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener certificados',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
