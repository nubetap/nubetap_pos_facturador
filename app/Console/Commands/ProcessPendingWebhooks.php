<?php

namespace App\Console\Commands;

use App\Services\WebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessPendingWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhooks:process
                            {--limit=100 : Límite de webhooks a procesar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Procesar webhooks pendientes y reintentar los fallidos';

    /**
     * Execute the console command.
     */
    public function handle(WebhookService $webhookService): int
    {
        $this->info('🔄 Procesando webhooks pendientes...');

        $limit = (int) $this->option('limit');

        try {
            $processed = $webhookService->processPendingDeliveries($limit);

            $this->info("✅ Webhooks procesados: {$processed}");

            Log::channel('audit')->info('Webhooks procesados', [
                'processed' => $processed,
                'limit' => $limit
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error al procesar webhooks: {$e->getMessage()}");

            Log::channel('critical')->error('Error al procesar webhooks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }
}
