<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega soporte para proveedor PSE externo (ValidaPSE) en la tabla companies.
 *
 * Ver: docs/INTEGRACION-VALIDAPSE-NRUS.md (Paso 6)
 *
 * - cpe_provider: 'greenter' (default) o 'validapse'. Decide el flujo de
 *   firma+envío de comprobantes en DocumentService::sendToSunat().
 * - validapse_empresa_id: ID de la empresa en ValidaPSE.
 * - validapse_token_acceso: Token por empresa entregado por ValidaPSE.
 *
 * Importante: solo un set de campos validapse_* (no separamos por
 * staging/producción) porque cada Company ya pertenece a UN ambiente
 * vía modo_produccion. La separación staging/prod vive del lado Django.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cpe_provider', 20)
                ->default('greenter')
                ->after('modo_produccion')
                ->comment('Proveedor de firma CPE: greenter | validapse');

            $table->unsignedBigInteger('validapse_empresa_id')
                ->nullable()
                ->after('cpe_provider')
                ->comment('ID de la empresa en ValidaPSE');

            $table->string('validapse_token_acceso', 500)
                ->nullable()
                ->after('validapse_empresa_id')
                ->comment('Token de acceso por empresa de ValidaPSE');

            $table->index('cpe_provider', 'companies_cpe_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('companies_cpe_provider_idx');
            $table->dropColumn([
                'cpe_provider',
                'validapse_empresa_id',
                'validapse_token_acceso',
            ]);
        });
    }
};
