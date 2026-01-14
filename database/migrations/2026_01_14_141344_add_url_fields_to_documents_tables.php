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
        // Boletas
        Schema::table('boletas', function (Blueprint $table) {
            $table->string('xml_url')->nullable()->after('xml_path');
            $table->string('cdr_url')->nullable()->after('cdr_path');
            $table->string('pdf_url')->nullable()->after('pdf_path');
        });

        // Invoices (Facturas)
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('xml_url')->nullable()->after('xml_path');
            $table->string('cdr_url')->nullable()->after('cdr_path');
            $table->string('pdf_url')->nullable()->after('pdf_path');
        });

        // Credit Notes (Notas de Crédito)
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('xml_url')->nullable()->after('xml_path');
            $table->string('cdr_url')->nullable()->after('cdr_path');
            $table->string('pdf_url')->nullable()->after('pdf_path');
        });

        // Debit Notes (Notas de Débito)
        Schema::table('debit_notes', function (Blueprint $table) {
            $table->string('xml_url')->nullable()->after('xml_path');
            $table->string('cdr_url')->nullable()->after('cdr_path');
            $table->string('pdf_url')->nullable()->after('pdf_path');
        });

        // Dispatch Guides (Guías de Remisión)
        Schema::table('dispatch_guides', function (Blueprint $table) {
            $table->string('xml_url')->nullable()->after('xml_path');
            $table->string('cdr_url')->nullable()->after('cdr_path');
            $table->string('pdf_url')->nullable()->after('pdf_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->dropColumn(['xml_url', 'cdr_url', 'pdf_url']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['xml_url', 'cdr_url', 'pdf_url']);
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn(['xml_url', 'cdr_url', 'pdf_url']);
        });

        Schema::table('debit_notes', function (Blueprint $table) {
            $table->dropColumn(['xml_url', 'cdr_url', 'pdf_url']);
        });

        Schema::table('dispatch_guides', function (Blueprint $table) {
            $table->dropColumn(['xml_url', 'cdr_url', 'pdf_url']);
        });
    }
};
