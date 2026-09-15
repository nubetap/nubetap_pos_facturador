<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `observaciones` se venía pasando a DispatchGuide::create() y a las
 * plantillas PDF, pero la columna nunca existió: mass-assignment lo
 * descartaba en silencio y el bloque de observaciones del PDF salía vacío.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_guides', function (Blueprint $table) {
            // 250 es el máximo que admite SUNAT para la observación del CPE.
            $table->string('observaciones', 250)->nullable()->after('des_traslado');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_guides', function (Blueprint $table) {
            $table->dropColumn('observaciones');
        });
    }
};
