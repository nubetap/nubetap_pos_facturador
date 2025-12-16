<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            
            // Identificación del documento
            $table->string('tipo_documento', 2)->default('08'); // 08: Nota de Débito
            $table->string('serie', 4);
            $table->string('correlativo', 8);
            $table->string('numero_completo', 15); // Serie-Correlativo (FD01-000001)
            
            // Documento afectado
            $table->string('tipo_doc_afectado', 2); // 01: Factura, 03: Boleta
            $table->string('num_doc_afectado', 20); // F001-123 o B001-456
            $table->string('cod_motivo', 2); // Catálogo 10: 01-Intereses por mora, etc.
            $table->string('des_motivo'); // Descripción del motivo
            
            // Fechas
            $table->date('fecha_emision');
            
            // Configuración
            $table->string('ubl_version', 3)->default('2.1');
            $table->string('moneda', 3)->default('PEN');
            
            // Montos
            $table->decimal('valor_venta', 12, 2)->default(0);
            $table->decimal('mto_oper_gravadas', 12, 2)->default(0);
            $table->decimal('mto_oper_exoneradas', 12, 2)->default(0);
            $table->decimal('mto_oper_inafectas', 12, 2)->default(0);
            $table->decimal('mto_igv', 12, 2)->default(0);
            $table->decimal('mto_isc', 12, 2)->default(0);
            $table->decimal('total_impuestos', 12, 2)->default(0);
            $table->decimal('mto_imp_venta', 12, 2)->default(0);
            
            // Detalles y configuraciones
            $table->json('detalles'); // Items de la nota de débito
            $table->json('leyendas')->nullable();
            $table->json('datos_adicionales')->nullable();
            
            // Archivos generados
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('pdf_path')->nullable();
            
            // Estado SUNAT
            $table->string('estado_sunat', 20)->default('PENDIENTE'); // PENDIENTE, ENVIADO, ACEPTADO, RECHAZADO
            $table->text('respuesta_sunat')->nullable();
            $table->string('codigo_hash')->nullable();
            
            // Auditoría
            $table->string('usuario_creacion')->nullable();
            $table->timestamps();
            
            $table->unique(['company_id', 'serie', 'correlativo']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['fecha_emision']);
            $table->index(['estado_sunat']);
            $table->index(['tipo_doc_afectado', 'num_doc_afectado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_notes');
    }
};
