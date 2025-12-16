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
        Schema::table('companies', function (Blueprint $table) {
            // Credenciales GRE para ambiente beta
            $table->string('gre_client_id_beta')->nullable()->after('certificado_password');
            $table->string('gre_client_secret_beta')->nullable()->after('gre_client_id_beta');
            
            // Credenciales GRE para ambiente producción
            $table->string('gre_client_id_produccion')->nullable()->after('gre_client_secret_beta');
            $table->string('gre_client_secret_produccion')->nullable()->after('gre_client_id_produccion');
            
            // RUC del proveedor GRE (opcional, por defecto usa el RUC de la empresa)
            $table->string('gre_ruc_proveedor')->nullable()->after('gre_client_secret_produccion');
            
            // Usuario y clave SOL específicos para GRE (opcional, por defecto usa los generales)
            $table->string('gre_usuario_sol')->nullable()->after('gre_ruc_proveedor');
            $table->string('gre_clave_sol')->nullable()->after('gre_usuario_sol');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'gre_client_id_beta',
                'gre_client_secret_beta', 
                'gre_client_id_produccion',
                'gre_client_secret_produccion',
                'gre_ruc_proveedor',
                'gre_usuario_sol',
                'gre_clave_sol'
            ]);
        });
    }
};