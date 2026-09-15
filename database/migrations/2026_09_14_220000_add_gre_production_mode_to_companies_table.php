<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ambiente propio para las guías de remisión.
 *
 * Hasta ahora la GRE seguía a `modo_produccion`, el de factura/boleta. Eso
 * impedía probar guías en beta mientras la empresa ya factura en producción,
 * que es justo lo que se necesita al activar la función por primera vez.
 *
 * Default false: toda empresa existente queda en beta para guías, que es el
 * estado seguro (ninguna emite guías todavía).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('gre_modo_produccion')
                ->default(false)
                ->after('modo_produccion');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('gre_modo_produccion');
        });
    }
};
