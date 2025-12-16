<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voided_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            
            // Identificación del documento
            $table->string('tipo_documento', 2)->default('RA'); // RA: Comunicación de Baja
            $table->string('identificador', 20); // RUC-RA-YYYYMMDD-###
            $table->string('correlativo', 3); // 001, 002, etc.
            
            // Fechas
            $table->date('fecha_emision');
            $table->date('fecha_referencia'); // Fecha de los comprobantes a dar de baja
            
            // Configuración
            $table->string('ubl_version', 3)->default('2.0');
            
            // Detalles de la comunicación de baja
            $table->json('detalles'); // Documentos a dar de baja
            $table->text('motivo_baja'); // Motivo de la baja
            $table->integer('total_documentos')->default(0);
            
            // Archivos generados
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            
            // Estado SUNAT
            $table->string('estado_sunat', 20)->default('PENDIENTE'); // PENDIENTE, ENVIADO, ACEPTADO, RECHAZADO
            $table->text('respuesta_sunat')->nullable();
            $table->string('ticket')->nullable(); // Para consulta de estado
            
            // Auditoría
            $table->string('usuario_creacion')->nullable();
            $table->timestamps();
            
            $table->unique(['company_id', 'identificador']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['fecha_emision']);
            $table->index(['fecha_referencia']);
            $table->index(['estado_sunat']);
            $table->index(['ticket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voided_documents');
    }
};
