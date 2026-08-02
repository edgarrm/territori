<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de accesos a datos personales (ADR-004, LFPDPPP art. 21). Es
     * append-only: la aplicación solo inserta, nunca actualiza ni borra.
     * `metadatos` guarda contexto no identificable (filtros aplicados, número de
     * filas, campos modificados) — nunca el valor de un dato personal.
     */
    public function up(): void
    {
        Schema::create('bitacora_accesos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // El actor se conserva aunque su membresía o su cuenta desaparezcan:
            // la traza debe sobrevivir a la baja de quien la generó.
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 40);
            // Sin FK: el recurso puede ser de cualquier tabla y la entrada debe
            // seguir existiendo después de que ese recurso se borre.
            $table->string('recurso_tipo', 40)->nullable();
            $table->unsignedBigInteger('recurso_id')->nullable();
            $table->jsonb('metadatos')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['membership_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_accesos');
    }
};
