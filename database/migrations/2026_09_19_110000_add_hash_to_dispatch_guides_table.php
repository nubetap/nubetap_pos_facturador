<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El valor resumen (DigestValue de la firma) es dato obligatorio de la
 * representación impresa de la GRE. Las facturas ya lo guardan; las guías
 * lo descartaban al enviar y el PDF salía sin él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_guides', function (Blueprint $table) {
            $table->string('hash', 100)->nullable()->after('xml_path');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_guides', function (Blueprint $table) {
            $table->dropColumn('hash');
        });
    }
};
