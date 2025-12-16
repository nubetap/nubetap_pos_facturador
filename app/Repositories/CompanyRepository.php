<?php

namespace App\Repositories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Collection;

class CompanyRepository extends BaseRepository
{
    /**
     * Specify Model class name
     */
    protected function model(): string
    {
        return Company::class;
    }

    /**
     * Get active companies
     */
    public function getActive(): Collection
    {
        return $this->model
            ->where('activo', true)
            ->orderBy('razon_social', 'asc')
            ->get();
    }

    /**
     * Find company with configurations
     */
    public function findWithConfigurations(int $id): ?Company
    {
        return $this->model
            ->with('configurations')
            ->find($id);
    }

    /**
     * Get companies with expired certificates
     */
    public function getWithExpiredCertificates(int $daysBeforeExpiration = 30): Collection
    {
        $expirationDate = now()->addDays($daysBeforeExpiration);

        return $this->model
            ->where('activo', true)
            ->whereNotNull('certificado_digital')
            ->where('certificado_expires_at', '<=', $expirationDate)
            ->get();
    }

    /**
     * Get companies in production mode
     */
    public function getInProduction(): Collection
    {
        return $this->model
            ->where('activo', true)
            ->whereHas('configurations', function ($query) {
                $query->where('config_type', 'sunat')
                      ->where('environment', 'production');
            })
            ->get();
    }

    /**
     * Update certificate info
     */
    public function updateCertificate(int $id, string $certificatePath, ?\DateTime $expiresAt): bool
    {
        return $this->update($id, [
            'certificado_digital' => $certificatePath,
            'certificado_expires_at' => $expiresAt,
        ]);
    }
}
