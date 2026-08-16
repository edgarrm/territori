<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encuesta_respuestas_agregadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encuesta_id')->constrained('encuestas')->cascadeOnDelete();
            $table->foreignId('pregunta_id')->constrained('encuesta_preguntas')->cascadeOnDelete();
            $table->string('ambito_tipo', 20);
            // Sin FK a propósito: apunta a secciones.id o zonas.id según
            // ambito_tipo (patrón polimórfico manual, F6/F7 aún no existen).
            $table->unsignedBigInteger('ambito_id');
            $table->string('opcion', 300);
            $table->unsignedInteger('conteo')->default(0);
            $table->timestampTz('actualizado_en')->nullable();

            $table->unique(['encuesta_id', 'pregunta_id', 'ambito_tipo', 'ambito_id', 'opcion'], 'encuesta_respuestas_agregadas_unicidad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encuesta_respuestas_agregadas');
    }
};
