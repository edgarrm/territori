<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 160);
            // Catálogo de canales vive en código (App\Enums\CanalEncuesta), no
            // como CHECK de BD: agregar un canal nuevo no debe pedir migración.
            $table->string('canal', 40)->default('nativo');
            $table->jsonb('configuracion_canal')->nullable();
            $table->string('ambito_tipo', 20);
            $table->string('modo_acceso', 20)->default('brigadista');
            // No adivinable (Str::random(40)); solo se puebla si modo_acceso
            // incluye 'publico'. Único porque es la puerta de entrada pública.
            $table->string('token_publico')->nullable()->unique();
            $table->string('estado', 20)->default('activa');
            $table->timestampTz('ultima_sincronizacion')->nullable();
            $table->jsonb('ultimo_resultado')->nullable();
            $table->text('ultimo_error')->nullable();
            // SET NULL, no CASCADE: mismo criterio que el resto de FKs hacia
            // memberships (ver 2026_08_03_025804_uniformar_fk_de_membership_a_set_null).
            $table->foreignId('creado_por_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encuestas');
    }
};
