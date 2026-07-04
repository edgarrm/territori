<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estadística pública por sección (resultados 2024 + perfil demográfico por
     * edad). Datos globales del municipio, no tenant-scoped (como lista_nominal).
     * Todas las columnas de datos son nullable: las fuentes electoral y
     * demográfica no cubren exactamente las mismas secciones.
     */
    public function up(): void
    {
        Schema::create('estadisticas_seccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->unique()->constrained('secciones')->cascadeOnDelete();

            // Resultados electorales 2024 por bloque.
            $table->unsignedInteger('lista_nominal_2024')->nullable();
            $table->unsignedInteger('total_votos')->nullable();
            $table->decimal('participacion_pct', 5, 2)->nullable();
            $table->unsignedInteger('votos_fuerza')->nullable();
            $table->decimal('pct_fuerza', 5, 2)->nullable();
            $table->unsignedInteger('votos_morena_pvem')->nullable();
            $table->decimal('pct_morena_pvem', 5, 2)->nullable();
            $table->unsignedInteger('votos_otros')->nullable();
            $table->decimal('pct_otros', 5, 2)->nullable();
            $table->string('ganador_bloque')->nullable();
            $table->integer('margen_votos')->nullable();
            $table->decimal('margen_pp', 6, 2)->nullable();
            $table->string('ganador_partido')->nullable();
            $table->jsonb('votos_partidos')->nullable();

            // Perfil demográfico por grupos de edad (análisis 2024).
            $table->decimal('participacion_2024_pct', 5, 2)->nullable();
            $table->decimal('abstencion_2024_pct', 5, 2)->nullable();
            $table->decimal('indice_oportunidad', 5, 2)->nullable();
            $table->string('nivel_oportunidad')->nullable();
            $table->string('grupo_dominante')->nullable();
            $table->string('grupo_mayor_abstencion')->nullable();
            $table->unsignedInteger('potencial_movilizacion')->nullable();
            $table->string('tipo_composicion_edad')->nullable();
            $table->string('universo_operativo')->nullable();
            $table->text('recomendacion')->nullable();
            $table->jsonb('grupos_edad')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estadisticas_seccion');
    }
};
