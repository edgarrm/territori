<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asignación de zonas: pivote brigadista (membership) ⨯ sección.
     * Tenant-scoped por columna (memberships NO usa global scope), PK compuesta.
     */
    public function up(): void
    {
        Schema::create('brigadista_seccion', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();

            $table->primary(['membership_id', 'seccion_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brigadista_seccion');
    }
};
