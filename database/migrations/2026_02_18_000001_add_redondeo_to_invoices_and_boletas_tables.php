<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'redondeo')) {
                $table->decimal('redondeo', 10, 2)->default(0)->after('mto_imp_venta');
            }
            if (!Schema::hasColumn('invoices', 'descuento_global')) {
                $table->decimal('descuento_global', 12, 2)->default(0)->after('redondeo');
            }
        });

        Schema::table('boletas', function (Blueprint $table) {
            if (!Schema::hasColumn('boletas', 'redondeo')) {
                $table->decimal('redondeo', 10, 2)->default(0)->after('mto_imp_venta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['redondeo', 'descuento_global']);
        });

        Schema::table('boletas', function (Blueprint $table) {
            $table->dropColumn('redondeo');
        });
    }
};
