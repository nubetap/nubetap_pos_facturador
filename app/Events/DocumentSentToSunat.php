<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentSentToSunat implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Crear nueva instancia del evento
     *
     * @param mixed $document Modelo del documento
     * @param string $documentType Tipo de documento
     * @param array $result Resultado del envío
     */
    public function __construct(
        public $document,
        public string $documentType,
        public array $result
    ) {
        //
    }

    /**
     * Obtener los canales donde debe transmitirse el evento
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('company.' . $this->document->company_id),
        ];
    }

    /**
     * Nombre del evento broadcast
     */
    public function broadcastAs(): string
    {
        return 'document.sent.sunat';
    }

    /**
     * Datos a transmitir
     */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->document->id,
            'document_type' => $this->documentType,
            'numero' => $this->document->numero_completo,
            'success' => $this->result['success'],
            'estado_sunat' => $this->document->estado_sunat,
            'timestamp' => now()->toISOString()
        ];
    }
}
