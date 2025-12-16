<?php

namespace App\Jobs;

use App\Services\DocumentService;
use App\Events\DocumentSentToSunat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDocumentToSunat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de intentos antes de fallar
     */
    public $tries = 3;

    /**
     * Tiempos de espera entre reintentos (en segundos)
     */
    public $backoff = [30, 60, 120];

    /**
     * Timeout del job en segundos
     */
    public $timeout = 300; // 5 minutos

    /**
     * Crear nueva instancia del job
     *
     * @param mixed $document Modelo del documento (Invoice, Boleta, etc.)
     * @param string $documentType Tipo de documento ('invoice', 'boleta', etc.)
     */
    public function __construct(
        public $document,
        public string $documentType
    ) {
        // Configurar cola específica para envíos a SUNAT
        $this->onQueue('sunat-send');
    }

    /**
     * Ejecutar el job
     */
    public function handle(DocumentService $documentService): void
    {
        Log::info("Iniciando envío a SUNAT", [
            'document_type' => $this->documentType,
            'document_id' => $this->document->id,
            'numero' => $this->document->numero_completo,
            'attempt' => $this->attempts()
        ]);

        try {
            // Enviar documento a SUNAT
            $result = $documentService->sendToSunat($this->document, $this->documentType);

            // Disparar evento de documento enviado
            event(new DocumentSentToSunat(
                $this->document,
                $this->documentType,
                $result
            ));

            if ($result['success']) {
                Log::info("Documento enviado exitosamente a SUNAT", [
                    'document_type' => $this->documentType,
                    'document_id' => $this->document->id,
                    'numero' => $this->document->numero_completo
                ]);
            } else {
                Log::warning("SUNAT rechazó el documento", [
                    'document_type' => $this->documentType,
                    'document_id' => $this->document->id,
                    'numero' => $this->document->numero_completo,
                    'error' => $result['error']
                ]);

                // Si fue rechazado, no reintentar
                $this->delete();
            }

        } catch (\Throwable $e) {
            Log::error("Error al enviar documento a SUNAT", [
                'document_type' => $this->documentType,
                'document_id' => $this->document->id,
                'numero' => $this->document->numero_completo,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            // Re-lanzar excepción para que Laravel maneje los reintentos
            throw $e;
        }
    }

    /**
     * Manejar falla del job después de todos los intentos
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical("Job de envío a SUNAT falló definitivamente", [
            'document_type' => $this->documentType,
            'document_id' => $this->document->id,
            'numero' => $this->document->numero_completo,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Actualizar estado del documento
        $this->document->update([
            'estado_sunat' => 'ERROR',
            'respuesta_sunat' => json_encode([
                'error' => $exception->getMessage(),
                'code' => 'JOB_FAILED',
                'attempts' => $this->attempts()
            ])
        ]);

        // Notificar al usuario o administrador (implementar según necesidad)
        // Mail::to('admin@empresa.com')->send(new DocumentSendFailed($this->document));
    }

    /**
     * Determinar el tags para el job (útil para monitoreo)
     */
    public function tags(): array
    {
        return [
            'sunat-send',
            $this->documentType,
            "company:{$this->document->company_id}",
            "document:{$this->document->id}"
        ];
    }
}
