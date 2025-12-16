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
        Schema::table('voided_documents', function (Blueprint $table) {
            // Aumentar el tamaño del campo identificador de 20 a 50 caracteres
            // Formato: RUC-RA-YYYYMMDD-### (ejemplo: 20123456789-RA-20250906-001)
            $table->string('identificador', 50)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voided_documents', function (Blueprint $table) {
            // Revertir al tamaño original
            $table->string('identificador', 20)->change();
        });
    }
};
