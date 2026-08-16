<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encuesta_preguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encuesta_id')->constrained('encuestas')->cascadeOnDelete();
            // Columna de origen a detectar en canales pull (Sheets, F9). En
            // 'nativo' no hay columna que detectar: siempre null.
            $table->string('campo')->nullable();
            $table->string('etiqueta', 300);
            $table->string('tipo', 20);
            $table->jsonb('opciones')->nullable();
            // Default true: toda pregunta creada vía 'nativo' es intencional.
            // El default false para canales pull es cosa de F9, no de aquí.
            $table->boolean('incluida')->default(true);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });

        // Único (encuesta_id, campo) solo cuando campo no es null: en 'nativo'
        // muchas preguntas comparten campo=null y no deben chocar entre sí.
        DB::statement('CREATE UNIQUE INDEX encuesta_preguntas_encuesta_id_campo_unique ON encuesta_preguntas (encuesta_id, campo) WHERE campo IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('encuesta_preguntas');
    }
};
