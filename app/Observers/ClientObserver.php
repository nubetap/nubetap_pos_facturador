<?php

namespace App\Observers;

use App\Models\Client;
use Illuminate\Support\Facades\Log;

class ClientObserver
{
    /**
     * Handle the Client "created" event.
     */
    public function created(Client $client): void
    {
        Log::debug('Nuevo cliente registrado', [
            'client_id' => $client->id,
            'tipo_documento' => $client->tipo_documento,
            'numero_documento' => $client->numero_documento,
            'razon_social' => $client->razon_social
        ]);
    }

    /**
     * Handle the Client "updating" event.
     */
    public function updating(Client $client): void
    {
        // Auditar cambios en información fiscal
        if ($client->isDirty('numero_documento') || $client->isDirty('razon_social')) {
            Log::channel('audit')->info('Información fiscal de cliente modificada', [
                'client_id' => $client->id,
                'numero_documento' => [
                    'old' => $client->getOriginal('numero_documento'),
                    'new' => $client->numero_documento
                ],
                'razon_social' => [
                    'old' => $client->getOriginal('razon_social'),
                    'new' => $client->razon_social
                ],
                'modified_by' => auth()->user()->email ?? 'system'
            ]);
        }
    }
}
