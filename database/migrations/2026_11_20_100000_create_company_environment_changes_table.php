<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de cambios de ambiente de cada empresa (modo_produccion,
 * cpe_provider, gre_modo_produccion): quién, cuándo y desde dónde.
 * Caso PUNTO FIJO 2026: la empresa pasó a beta sin rastro y 51 boletas
 * se emitieron en el ambiente de pruebas sin que nadie lo notara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_environment_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);
            $table->string('old_value', 40)->nullable();
            $table->string('new_value', 40)->nullable();
            $table->string('source', 191)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_environment_changes');
    }
};
