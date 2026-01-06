<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class StorageService
{
    /**
     * Determina si se debe usar S3 o almacenamiento local
     */
    public function useS3(): bool
    {
        return config('filesystems.default') === 's3';
    }

    /**
     * Obtiene el disco a usar para documentos (XML, CDR, PDF)
     */
    public function getDocumentDisk(): string
    {
        return $this->useS3() ? 'documents_s3' : 'public';
    }

    /**
     * Obtiene el disco a usar para certificados
     */
    public function getCertificateDisk(): string
    {
        return $this->useS3() ? 'certificates_s3' : 'public';
    }

    /**
     * Obtiene el disco a usar para logos
     */
    public function getLogoDisk(): string
    {
        return $this->useS3() ? 's3' : 'public';
    }

    /**
     * Obtiene la ruta del certificado según el RUC de la empresa
     */
    public function getCertificatePath(string $ruc): string
    {
        $filename = "certificado_{$ruc}.pem";

        if ($this->useS3()) {
            // En S3: certificados/certificado_{RUC}.pem
            return $filename;
        }

        // En local: storage/app/public/certificado/certificado_{RUC}.pem
        return "certificado/{$filename}";
    }

    /**
     * Obtiene la ruta del logo según el RUC de la empresa y extensión
     */
    public function getLogoPath(string $ruc, string $extension): string
    {
        $filename = "logo_{$ruc}.{$extension}";

        // Siempre retorna el path completo con el directorio logos/
        // El disco 's3' no tiene root, así que guardará en s3://bucket/logos/logo_{ruc}.ext
        // El disco 'public' guardará en storage/app/public/logos/logo_{ruc}.ext
        return "logos/{$filename}";
    }

    /**
     * Verifica si existe un certificado para el RUC especificado
     */
    public function certificateExists(string $ruc): bool
    {
        $disk = $this->getCertificateDisk();
        $path = $this->getCertificatePath($ruc);

        return Storage::disk($disk)->exists($path);
    }

    /**
     * Obtiene el contenido del certificado
     */
    public function getCertificateContent(string $ruc): ?string
    {
        $disk = $this->getCertificateDisk();
        $path = $this->getCertificatePath($ruc);

        // Intentar con RUC específico
        if (Storage::disk($disk)->exists($path)) {
            Log::info("Certificado encontrado para RUC: {$ruc}", [
                'storage' => $this->useS3() ? 's3' : 'local',
                'path' => $path
            ]);
            return Storage::disk($disk)->get($path);
        }

        // Fallback: intentar con nombre genérico
        $genericPath = $this->useS3() ? 'certificado.pem' : 'certificado/certificado.pem';

        if (Storage::disk($disk)->exists($genericPath)) {
            Log::warning("Certificado genérico usado para RUC: {$ruc}", [
                'storage' => $this->useS3() ? 's3' : 'local',
                'path' => $genericPath
            ]);
            return Storage::disk($disk)->get($genericPath);
        }

        Log::error("Certificado no encontrado para RUC: {$ruc}", [
            'storage' => $this->useS3() ? 's3' : 'local',
            'path_buscado' => $path,
            'fallback_buscado' => $genericPath
        ]);

        return null;
    }

    /**
     * Guarda un certificado
     */
    public function saveCertificate(string $ruc, string $content): bool
    {
        $disk = $this->getCertificateDisk();
        $path = $this->getCertificatePath($ruc);

        try {
            Storage::disk($disk)->put($path, $content);

            Log::info("Certificado guardado exitosamente", [
                'ruc' => $ruc,
                'storage' => $this->useS3() ? 's3' : 'local',
                'disk' => $disk,
                'path' => $path
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Error al guardar certificado", [
                'ruc' => $ruc,
                'error' => $e->getMessage(),
                'storage' => $this->useS3() ? 's3' : 'local'
            ]);

            return false;
        }
    }

    /**
     * Guarda un logo
     */
    public function saveLogo(string $ruc, string $content, string $extension): bool
    {
        $disk = $this->getLogoDisk();
        $path = $this->getLogoPath($ruc, $extension);

        try {
            Storage::disk($disk)->put($path, $content);

            Log::info("Logo guardado exitosamente", [
                'ruc' => $ruc,
                'storage' => $this->useS3() ? 's3' : 'local',
                'disk' => $disk,
                'path' => $path,
                'extension' => $extension
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Error al guardar logo", [
                'ruc' => $ruc,
                'error' => $e->getMessage(),
                'storage' => $this->useS3() ? 's3' : 'local',
                'extension' => $extension
            ]);

            return false;
        }
    }

    /**
     * Obtiene la ruta absoluta del certificado (solo para local)
     * Para S3, retorna la URL firmada
     */
    public function getCertificateFullPath(string $ruc): ?string
    {
        if ($this->useS3()) {
            $disk = $this->getCertificateDisk();
            $path = $this->getCertificatePath($ruc);

            if (Storage::disk($disk)->exists($path)) {
                // Retornar URL temporal firmada (válida por 5 minutos)
                return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(5));
            }

            return null;
        }

        // Para local, retornar ruta absoluta
        $path = $this->getCertificatePath($ruc);
        $fullPath = Storage::disk('public')->path($path);

        return file_exists($fullPath) ? $fullPath : null;
    }

    /**
     * Verifica si un archivo existe en el disco de documentos
     */
    public function documentExists(string $path): bool
    {
        $disk = $this->getDocumentDisk();
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Obtiene el contenido de un documento
     */
    public function getDocumentContent(string $path): ?string
    {
        $disk = $this->getDocumentDisk();

        if (!Storage::disk($disk)->exists($path)) {
            return null;
        }

        return Storage::disk($disk)->get($path);
    }

    /**
     * Guarda un documento (XML, CDR, PDF)
     */
    public function saveDocument(string $path, string $content): bool
    {
        $disk = $this->getDocumentDisk();

        try {
            Storage::disk($disk)->put($path, $content);

            Log::info("Documento guardado exitosamente", [
                'storage' => $this->useS3() ? 's3' : 'local',
                'path' => $path
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Error al guardar documento", [
                'path' => $path,
                'error' => $e->getMessage(),
                'storage' => $this->useS3() ? 's3' : 'local'
            ]);

            return false;
        }
    }

    /**
     * Obtiene la URL o ruta absoluta de un documento
     */
    public function getDocumentUrl(string $path): ?string
    {
        $disk = $this->getDocumentDisk();

        if (!Storage::disk($disk)->exists($path)) {
            return null;
        }

        if ($this->useS3()) {
            // URL temporal firmada (5 minutos)
            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(5));
        }

        // Para local, retornar ruta absoluta
        return Storage::disk('public')->path($path);
    }

    /**
     * Descarga un documento
     */
    public function downloadDocument(string $path, string $filename)
    {
        $disk = $this->getDocumentDisk();

        if (!Storage::disk($disk)->exists($path)) {
            return null;
        }

        return Storage::disk($disk)->download($path, $filename);
    }

    /**
     * Crea un directorio si no existe
     */
    public function ensureDirectoryExists(string $path): void
    {
        $disk = $this->getDocumentDisk();

        if (!Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->makeDirectory($path);
        }
    }

    /**
     * Obtiene información del storage actual
     */
    public function getStorageInfo(): array
    {
        return [
            'type' => $this->useS3() ? 's3' : 'local',
            'document_disk' => $this->getDocumentDisk(),
            'certificate_disk' => $this->getCertificateDisk(),
            'bucket' => $this->useS3() ? config('filesystems.disks.s3.bucket') : null,
            'region' => $this->useS3() ? config('filesystems.disks.s3.region') : null,
        ];
    }
}
