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
        Schema::table('boletas', function (Blueprint $table) {
            $table->unsignedBigInteger('daily_summary_id')->nullable()->after('client_id');
            $table->string('metodo_envio', 20)->default('individual')->after('moneda');
            
            $table->foreign('daily_summary_id')->references('id')->on('daily_summaries')->onDelete('set null');
            $table->index('daily_summary_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->dropForeign(['daily_summary_id']);
            $table->dropColumn(['daily_summary_id', 'metodo_envio']);
        });
    }
};
