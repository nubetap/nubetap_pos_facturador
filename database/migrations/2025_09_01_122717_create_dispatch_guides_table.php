<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade'); // Destinatario
            
            // Identificación del documento
            $table->string('tipo_documento', 2)->default('09'); // 09: Guía de Remisión
            $table->string('serie', 4);
            $table->string('correlativo', 8);
            $table->string('numero_completo', 15); // Serie-Correlativo (T001-000001)
            
            // Fechas
            $table->date('fecha_emision');
            $table->date('fecha_traslado');
            
            // Configuración
            $table->string('version', 10)->default('2022');
            
            // Traslado
            $table->string('cod_traslado', 2); // Catálogo 20: 01-Venta, 14-Venta sujeta a confirmación, etc.
            $table->string('des_traslado')->nullable(); // Descripción motivo traslado
            $table->string('mod_traslado', 2); // Catálogo 18: 01-Transporte público, 02-Transporte privado
            $table->decimal('peso_total', 10, 3);
            $table->string('und_peso_total', 3)->default('KGM');
            $table->integer('num_bultos')->nullable(); // Solo válido para importaciones
            
            // Direcciones
            $table->json('partida'); // {ubigeo, direccion}
            $table->json('llegada'); // {ubigeo, direccion}
            
            // Transportista (cuando aplica)
            $table->json('transportista')->nullable(); // {tipo_doc, num_doc, razon_social, nro_mtc, chofer}
            $table->json('vehiculo')->nullable(); // {placa_principal, placa_secundaria, autorizacion}
            
            // Detalles
            $table->json('detalles'); // Items transportados
            $table->json('documentos_relacionados')->nullable(); // Facturas, boletas relacionadas
            $table->json('datos_adicionales')->nullable();
            
            // Archivos generados
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('pdf_path')->nullable();
            
            // Estado SUNAT
            $table->string('estado_sunat', 20)->default('PENDIENTE'); // PENDIENTE, ENVIADO, ACEPTADO, RECHAZADO
            $table->text('respuesta_sunat')->nullable();
            $table->string('ticket')->nullable(); // Para consulta de estado
            
            // Auditoría
            $table->string('usuario_creacion')->nullable();
            $table->timestamps();
            
            $table->unique(['company_id', 'serie', 'correlativo']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['fecha_emision']);
            $table->index(['estado_sunat']);
            $table->index(['ticket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_guides');
    }
};
