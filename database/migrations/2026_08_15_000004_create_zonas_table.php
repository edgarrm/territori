<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zonas territoriales personalizadas del tenant (F6): cualquier nivel
     * (ciudad/municipio/estado/país/continente/mundial/custom), independientes
     * de la jerarquía electoral INE. `tipo` es texto libre (sin CHECK/enum de
     * BD) para que el cliente pueda inventar niveles nuevos sin migrar.
     */
    public function up(): void
    {
        Schema::create('zonas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nombre', 160);
            $table->string('clave', 60)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('tipo', 40);
            $table->foreignId('zona_padre_id')->nullable()->constrained('zonas')->nullOnDelete();
            // Nullable: un nivel intermedio (mundial/continente) puede solo
            // agrupar zonas hijas sin tener polígono propio.
            $table->geometry('geom', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->string('fuente', 20)->default('dibujada');
            $table->boolean('activa')->default(true);
            // SET NULL, no CASCADE: mismo criterio que el resto de FKs hacia
            // memberships (ver 2026_08_03_025804_uniformar_fk_de_membership_a_set_null).
            $table->foreignId('creado_por_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();

            // NULL no colisiona en Postgres (varias zonas sin clave conviven);
            // el upsert idempotente del comando de importación depende de esta
            // unicidad exacta como ON CONFLICT target.
            $table->unique(['tenant_id', 'clave']);
        });

        DB::statement('CREATE INDEX zonas_geom_gist ON zonas USING GIST (geom);');
    }

    public function down(): void
    {
        Schema::dropIfExists('zonas');
    }
};
