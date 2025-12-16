<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class RestoreDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:restore
                            {backup : Nombre o ruta del backup a restaurar}
                            {--cloud : Descargar desde almacenamiento en la nube}
                            {--verify : Verificar integridad de archivos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restaurar documentos desde un backup';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Iniciando proceso de restauración...');

        $backup = $this->argument('backup');
        $fromCloud = $this->option('cloud');
        $verify = $this->option('verify');

        try {
            // Obtener ruta del backup
            $backupPath = $fromCloud
                ? $this->downloadFromCloud($backup)
                : $this->getLocalBackupPath($backup);

            if (!$backupPath || !file_exists($backupPath)) {
                $this->error("❌ Backup no encontrado: {$backup}");
                return Command::FAILURE;
            }

            $this->info("📁 Backup encontrado: {$backupPath}");

            // Descomprimir si es ZIP
            if (pathinfo($backupPath, PATHINFO_EXTENSION) === 'zip') {
                $extractPath = $this->extractZip($backupPath);
                $backupPath = $extractPath;
            }

            // Restaurar archivos
            $stats = $this->restoreFiles($backupPath);
            $this->displayStats($stats);

            // Verificar integridad si se solicita
            if ($verify) {
                $this->verifyIntegrity($stats);
            }

            Log::channel('audit')->info('Restauración completada exitosamente', [
                'backup' => $backup,
                'stats' => $stats
            ]);

            $this->info('✅ Restauración completada exitosamente');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error en la restauración: {$e->getMessage()}");
            Log::channel('critical')->error('Error en restauración de documentos', [
                'backup' => $backup,
                'error' => $e->getMessage()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Download backup from cloud
     */
    protected function downloadFromCloud(string $backup): ?string
    {
        $this->info('☁️ Descargando backup desde la nube...');

        try {
            $disk = Storage::disk('s3');
            $cloudPath = "backups/{$backup}";

            if (!$disk->exists($cloudPath)) {
                return null;
            }

            $localPath = storage_path("temp/{$backup}");
            $localDir = dirname($localPath);

            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            file_put_contents($localPath, $disk->get($cloudPath));

            $this->info("✅ Backup descargado");
            return $localPath;

        } catch (\Exception $e) {
            $this->error("❌ Error al descargar desde la nube: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get local backup path
     */
    protected function getLocalBackupPath(string $backup): ?string
    {
        // Si es ruta completa
        if (file_exists($backup)) {
            return $backup;
        }

        // Buscar en directorio de backups
        $backupsDir = storage_path('backups/documents');
        $fullPath = "{$backupsDir}/{$backup}";

        return file_exists($fullPath) ? $fullPath : null;
    }

    /**
     * Extract ZIP backup
     */
    protected function extractZip(string $zipPath): string
    {
        $this->info('📦 Extrayendo backup comprimido...');

        $extractPath = storage_path('temp/' . pathinfo($zipPath, PATHINFO_FILENAME));

        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new \Exception("No se pudo abrir el archivo ZIP: {$zipPath}");
        }

        $zip->extractTo($extractPath);
        $zip->close();

        $this->info("✅ Backup extraído");

        return $extractPath;
    }

    /**
     * Restore files from backup
     */
    protected function restoreFiles(string $backupPath): array
    {
        $stats = [
            'xml' => 0,
            'cdr' => 0,
            'pdf' => 0,
            'errors' => 0
        ];

        $types = ['xml', 'cdr', 'pdf'];

        foreach ($types as $type) {
            $typePath = "{$backupPath}/{$type}";

            if (!is_dir($typePath)) {
                continue;
            }

            $files = scandir($typePath);
            $this->info("📄 Restaurando archivos {$type}...");

            $progressBar = $this->output->createProgressBar(count($files) - 2); // Excluir . y ..
            $progressBar->start();

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                try {
                    $sourcePath = "{$typePath}/{$file}";
                    $this->restoreFile($sourcePath, $type);
                    $stats[$type]++;
                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::warning("Error al restaurar archivo {$type}", [
                        'file' => $file,
                        'error' => $e->getMessage()
                    ]);
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();
        }

        return $stats;
    }

    /**
     * Restore individual file
     */
    protected function restoreFile(string $sourcePath, string $type): void
    {
        $filename = basename($sourcePath);

        // Determinar directorio de destino según el tipo
        $destinationDir = storage_path("app/sunat/{$type}");

        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        $destinationPath = "{$destinationDir}/{$filename}";

        // No sobrescribir si ya existe
        if (file_exists($destinationPath)) {
            if (!$this->confirm("El archivo {$filename} ya existe. ¿Sobrescribir?", false)) {
                return;
            }
        }

        copy($sourcePath, $destinationPath);
    }

    /**
     * Verify file integrity
     */
    protected function verifyIntegrity(array $stats): void
    {
        $this->info('🔍 Verificando integridad de archivos...');

        $totalFiles = $stats['xml'] + $stats['cdr'] + $stats['pdf'];
        $verified = 0;
        $corrupted = 0;

        $progressBar = $this->output->createProgressBar($totalFiles);
        $progressBar->start();

        $types = ['xml', 'cdr', 'pdf'];

        foreach ($types as $type) {
            $dir = storage_path("app/sunat/{$type}");

            if (!is_dir($dir)) {
                continue;
            }

            $files = array_diff(scandir($dir), ['.', '..']);

            foreach ($files as $file) {
                $filePath = "{$dir}/{$file}";

                if (filesize($filePath) > 0 && is_readable($filePath)) {
                    $verified++;
                } else {
                    $corrupted++;
                    $this->warn("⚠️ Archivo corrupto o ilegible: {$file}");
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("✅ Archivos verificados: {$verified}");

        if ($corrupted > 0) {
            $this->warn("⚠️ Archivos corruptos: {$corrupted}");
        }
    }

    /**
     * Display restore statistics
     */
    protected function displayStats(array $stats): void
    {
        $this->newLine();
        $this->info('📊 Estadísticas de la restauración:');
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
