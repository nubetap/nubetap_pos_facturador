<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reemplazar el unique constraint de clients para incluir company_id.
     * Esto permite que el mismo documento exista en distintas empresas.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Eliminar el constraint original (tipo_documento, numero_documento)
            $table->dropUnique('clients_tipo_documento_numero_documento_unique');

            // Crear nuevo constraint que incluye company_id
            $table->unique(['company_id', 'tipo_documento', 'numero_documento']);
        });
    }

    /**
     * Revertir al constraint original sin company_id.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'tipo_documento', 'numero_documento']);
            $table->unique(['tipo_documento', 'numero_documento']);
        });
    }
};
