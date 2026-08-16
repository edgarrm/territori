<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conexiones_google', function (Blueprint $table) {
            $table->id();
            // Único por tenant: un tenant = una conexión de Google (F9, sin
            // selector multi-cuenta, decisión explícita de la spec).
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            // Primera vez que este codebase guarda un secreto OAuth de
            // terceros: mismo criterio de cifrado en reposo que
            // electores.telefono/domicilio (ADR-004), aunque no es PII de un
            // ciudadano sino un secreto operativo de la campaña.
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestampTz('expira_en');
            $table->string('cuenta_email');
            // SET NULL, no CASCADE: mismo criterio que el resto de FKs hacia
            // memberships.
            $table->foreignId('conectado_por_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestampTz('conectado_en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conexiones_google');
    }
};
