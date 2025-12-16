<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            
            // Identificación del documento
            $table->string('correlativo', 3); // 001, 002, etc.
            $table->string('numero_completo', 50)->nullable(); // RC-YYYYMMDD-###
            
            // Fechas
            $table->date('fecha_generacion');
            $table->date('fecha_resumen'); // Fecha de los comprobantes incluidos
            
            // Configuración
            $table->string('ubl_version', 5)->default('2.1');
            $table->string('moneda', 3)->default('PEN');
            
            // Estados de proceso
            $table->string('estado_proceso', 20)->default('GENERADO'); // GENERADO, ENVIADO, PROCESANDO, COMPLETADO, ERROR
            
            // Detalles del resumen
            $table->json('detalles'); // Resumen de boletas y notas por serie
            
            // Archivos generados
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('pdf_path')->nullable();
            
            // Estado SUNAT
            $table->string('estado_sunat', 20)->default('PENDIENTE'); // PENDIENTE, PROCESANDO, ACEPTADO, RECHAZADO
            $table->text('respuesta_sunat')->nullable();
            $table->string('ticket')->nullable(); // Para consulta de estado
            $table->string('codigo_hash')->nullable();
            
            // Auditoría
            $table->string('usuario_creacion')->nullable();
            $table->timestamps();
            
            $table->index(['company_id', 'branch_id']);
            $table->index(['fecha_generacion']);
            $table->index(['fecha_resumen']);
            $table->index(['estado_sunat']);
            $table->index(['estado_proceso']);
            $table->index(['ticket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_summaries');
    }
};
