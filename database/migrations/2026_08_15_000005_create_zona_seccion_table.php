<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Intersección geométrica zona (F6) ⨯ sección INE (F7). Pivote calculado
     * (no capturado a mano), mismo patrón exacto que
     * `create_brigadista_seccion_table`: tenant_id por columna (zonas SÍ usa
     * BelongsToTenant, pero secciones es global sin tenant_id, así que este
     * pivote necesita su propia columna), PK compuesta.
     */
    public function up(): void
    {
        Schema::create('zona_seccion', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('zona_id')->constrained('zonas')->cascadeOnDelete();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            // 0-1: fracción del área de la sección cubierta por la zona.
            $table->decimal('pct_interseccion', 5, 4);
            $table->timestampTz('calculado_en');

            $table->primary(['zona_id', 'seccion_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zona_seccion');
    }
};
