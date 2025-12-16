<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentAcceptedBySunat extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public $document,
        public string $documentType
    ) {
        // Configurar cola para notificaciones
        $this->onQueue('notifications');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $documentTypeName = $this->getDocumentTypeName();
        $simboloMoneda = $this->document->moneda === 'PEN' ? 'S/' : '$';

        return (new MailMessage)
            ->subject("✓ {$documentTypeName} {$this->document->numero_completo} aceptado por SUNAT")
            ->greeting("¡Hola!")
            ->line("Su {$documentTypeName} ha sido **aceptado exitosamente** por SUNAT.")
            ->line("**Detalles del documento:**")
            ->line("• Número: {$this->document->numero_completo}")
            ->line("• Fecha de emisión: {$this->document->fecha_emision->format('d/m/Y')}")
            ->line("• Cliente: {$this->document->client?->razon_social ?? 'N/A'}")
            ->line("• Total: {$simboloMoneda} " . number_format($this->document->mto_imp_venta, 2))
            ->action('Ver Documento', url('/api/v1/invoices/' . $this->document->id))
            ->line('Puede descargar los archivos XML, CDR y PDF desde el sistema.')
            ->line('Gracias por usar nuestro sistema de facturación electrónica.');
    }

    /**
     * Get the database representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'document_id' => $this->document->id,
            'document_type' => $this->documentType,
            'document_type_name' => $this->getDocumentTypeName(),
            'numero' => $this->document->numero_completo,
            'estado' => 'ACEPTADO',
            'company_id' => $this->document->company_id,
            'total' => $this->document->mto_imp_venta,
            'moneda' => $this->document->moneda,
            'message' => "{$this->getDocumentTypeName()} {$this->document->numero_completo} aceptado por SUNAT",
            'icon' => 'check-circle',
            'color' => 'success'
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * Get document type name
     */
    protected function getDocumentTypeName(): string
    {
        return match($this->documentType) {
            'invoice' => 'FACTURA',
            'boleta' => 'BOLETA',
            'credit_note' => 'NOTA DE CRÉDITO',
            'debit_note' => 'NOTA DE DÉBITO',
            'dispatch_guide' => 'GUÍA DE REMISIÓN',
            default => 'DOCUMENTO'
        };
    }
}
