<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El ambiente de las guías (beta/producción) vive en la misma empresa,
 * así que una guía de prueba T001-00000001 y la primera real chocaban en
 * el unique (company, serie, correlativo) y la creación devolvía la de
 * beta. Igual que ElectronicDocument en Django, el ambiente pasa a ser
 * parte de la identidad del documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_guides', function (Blueprint $table) {
            $table->boolean('modo_produccion')->default(false)->after('tipo_documento');
        });

        Schema::table('dispatch_guides', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'serie', 'correlativo']);
            $table->unique(
                ['company_id', 'serie', 'correlativo', 'modo_produccion'],
                'dispatch_guides_company_serie_corr_env_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_guides', function (Blueprint $table) {
            $table->dropUnique('dispatch_guides_company_serie_corr_env_unique');
            $table->unique(['company_id', 'serie', 'correlativo']);
            $table->dropColumn('modo_produccion');
        });
    }
};
