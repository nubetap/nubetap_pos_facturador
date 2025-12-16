<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('proveedor_id')->constrained('clients')->onDelete('cascade');
            
            // Información del documento
            $table->string('serie', 10);
            $table->string('correlativo', 20);
            $table->string('numero_completo', 50)->unique();
            $table->datetime('fecha_emision');
            
            // Información de retención
            $table->string('regimen', 10); // 01, 02, 03
            $table->decimal('tasa', 5, 2); // 3.00, 6.00
            $table->text('observacion')->nullable();
            $table->decimal('imp_retenido', 12, 2);
            $table->decimal('imp_pagado', 12, 2);
            $table->string('moneda', 3)->default('PEN');
            
            // Detalles (JSON)
            $table->json('detalles');
            
            // Estados y archivos
            $table->string('estado_sunat')->nullable();
            $table->string('hash_cdr')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->text('observaciones')->nullable();
            
            $table->timestamps();
            
            // Índices
            $table->index(['company_id', 'serie', 'correlativo']);
            $table->index(['fecha_emision']);
            $table->index(['proveedor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retentions');
    }
};