<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use ZipArchive;
use Carbon\Carbon;

class BackupDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:backup
                            {--company= : ID de la empresa específica a respaldar}
                            {--days=30 : Días anteriores a incluir en el backup}
                            {--type=all : Tipo de archivos (all, xml, cdr, pdf)}
                            {--compress : Comprimir el backup en ZIP}
                            {--cloud : Subir backup a almacenamiento en la nube}
                            {--clean : Limpiar archivos antiguos después del backup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crear backup de documentos electrónicos (XML, CDR, PDF)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Iniciando proceso de backup de documentos...');

        $companyId = $this->option('company');
        $days = (int) $this->option('days');
        $type = $this->option('type');
        $compress = $this->option('compress');
        $cloud = $this->option('cloud');
        $clean = $this->option('clean');

        try {
            // Obtener documentos a respaldar
            $documents = $this->getDocuments($companyId, $days);
            $this->info("📄 Documentos encontrados: {$documents->count()}");

            if ($documents->isEmpty()) {
                $this->warn('⚠️ No hay documentos para respaldar');
                return Command::SUCCESS;
            }

            // Crear directorio de backup
            $backupPath = $this->createBackupDirectory($companyId);
            $this->info("📁 Directorio de backup: {$backupPath}");

            // Copiar archivos
            $stats = $this->copyFiles($documents, $backupPath, $type);
            $this->displayStats($stats);

            // Comprimir si se solicita
            if ($compress) {
                $zipPath = $this->compressBackup($backupPath);
                $this->info("🗜️ Backup comprimido: {$zipPath}");

                // Eliminar archivos sin comprimir
                $this->deleteDirectory($backupPath);
            }

            // Subir a la nube si se solicita
            if ($cloud) {
                $this->uploadToCloud($compress ? $zipPath : $backupPath, $compress);
            }

            // Limpiar archivos antiguos
            if ($clean) {
                $this->cleanOldBackups();
            }

            Log::channel('audit')->info('Backup completado exitosamente', [
                'company_id' => $companyId,
                'days' => $days,
                'documents_count' => $documents->count(),
                'stats' => $stats,
                'compressed' => $compress,
                'cloud_upload' => $cloud
            ]);

            $this->info('✅ Backup completado exitosamente');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error en el backup: {$e->getMessage()}");
            Log::channel('critical')->error('Error en backup de documentos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Get documents to backup
     */
    protected function getDocuments(?int $companyId, int $days)
    {
        $query = \App\Models\Invoice::query()
            ->where('estado_sunat', 'ACEPTADO')
            ->whereDate('fecha_emision', '>=', now()->subDays($days));

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->orderBy('fecha_emision', 'desc')->get();
    }

    /**
     * Create backup directory
     */
    protected function createBackupDirectory(?int $companyId): string
    {
        $date = now()->format('Y-m-d_His');
        $companyPrefix = $companyId ? "company_{$companyId}" : 'all_companies';
        $path = storage_path("backups/documents/{$companyPrefix}_{$date}");

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * Copy files to backup directory
     */
    protected function copyFiles($documents, string $backupPath, string $type): array
    {
        $stats = [
            'xml' => 0,
            'cdr' => 0,
            'pdf' => 0,
            'errors' => 0
        ];

        $progressBar = $this->output->createProgressBar($documents->count());
        $progressBar->start();

        foreach ($documents as $document) {
            try {
                // Backup XML
                if (in_array($type, ['all', 'xml']) && $document->xml_path) {
                    if ($this->copyFile($document->xml_path, $backupPath, 'xml')) {
                        $stats['xml']++;
                    }
                }

                // Backup CDR
                if (in_array($type, ['all', 'cdr']) && $document->cdr_path) {
                    if ($this->copyFile($document->cdr_path, $backupPath, 'cdr')) {
                        $stats['cdr']++;
                    }
                }

                // Backup PDF
                if (in_array($type, ['all', 'pdf']) && $document->pdf_path) {
                    if ($this->copyFile($document->pdf_path, $backupPath, 'pdf')) {
                        $stats['pdf']++;
                    }
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::warning('Error al copiar archivos de documento', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        return $stats;
    }

    /**
     * Copy individual file
     */
    protected function copyFile(string $sourcePath, string $backupPath, string $type): bool
    {
        $fullSourcePath = storage_path("app/{$sourcePath}");

        if (!file_exists($fullSourcePath)) {
            return false;
        }

        $typeDir = "{$backupPath}/{$type}";
        if (!is_dir($typeDir)) {
            mkdir($typeDir, 0755, true);
        }

        $filename = basename($sourcePath);
        $destinationPath = "{$typeDir}/{$filename}";

        return copy($fullSourcePath, $destinationPath);
    }

    /**
     * Compress backup directory
     */
    protected function compressBackup(string $backupPath): string
    {
        $zipPath = $backupPath . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("No se pudo crear el archivo ZIP: {$zipPath}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $this->info('🗜️ Comprimiendo archivos...');
        $progressBar = $this->output->createProgressBar(iterator_count($files));
        $progressBar->start();

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($backupPath) + 1);
                $zip->addFile($filePath, $relativePath);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine();

        $zip->close();

        return $zipPath;
    }

    /**
     * Delete directory recursively
     */
    protected function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = array_diff(scandir($path), ['.', '..']);

        foreach ($files as $file) {
            $fullPath = "{$path}/{$file}";
            is_dir($fullPath) ? $this->deleteDirectory($fullPath) : unlink($fullPath);
        }

        rmdir($path);
    }

    /**
     * Upload backup to cloud storage
     */
    protected function uploadToCloud(string $path, bool $isCompressed): void
    {
        $this->info('☁️ Subiendo backup a almacenamiento en la nube...');

        try {
            $disk = Storage::disk('s3'); // o el disco configurado para la nube
            $filename = basename($path);
            $cloudPath = 'backups/' . $filename;

            if ($isCompressed) {
                $disk->put($cloudPath, file_get_contents($path));
            } else {
                // Subir directorio completo
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($path) + 1);
                        $disk->put("backups/{$filename}/{$relativePath}", file_get_contents($filePath));
                    }
                }
            }

            $this->info("✅ Backup subido a la nube: {$cloudPath}");

        } catch (\Exception $e) {
            $this->warn("⚠️ No se pudo subir a la nube: {$e->getMessage()}");
            Log::warning('Error al subir backup a la nube', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clean old backups
     */
    protected function cleanOldBackups(): void
    {
        $this->info('🧹 Limpiando backups antiguos...');

        $backupsPath = storage_path('backups/documents');
        $daysToKeep = 90; // Mantener backups de los últimos 90 días

        if (!is_dir($backupsPath)) {
            return;
        }

        $files = scandir($backupsPath);
        $cutoffDate = now()->subDays($daysToKeep);
        $deletedCount = 0;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = "{$backupsPath}/{$file}";
            $fileTime = filemtime($filePath);

            if ($fileTime < $cutoffDate->timestamp) {
                if (is_dir($filePath)) {
                    $this->deleteDirectory($filePath);
                } else {
                    unlink($filePath);
                }
                $deletedCount++;
            }
        }

        $this->info("✅ Backups eliminados: {$deletedCount}");
    }

    /**
     * Display backup statistics
     */
    protected function displayStats(array $stats): void
    {
        $this->newLine();
        $this->info('📊 Estadísticas del backup:');
        $this->table(
            ['Tipo', 'Cantidad'],
            [
                ['XML', $stats['xml']],
                ['CDR', $stats['cdr']],
                ['PDF', $stats['pdf']],
                ['Errores', $stats['errors']],
            ]
        );
    }
}
