<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipio_id')->constrained('municipios')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->unsignedSmallInteger('tipo')->nullable();
            $table->unsignedSmallInteger('distrito_federal')->nullable();
            $table->unsignedSmallInteger('distrito_local')->nullable();
            $table->unsignedInteger('lista_nominal')->nullable();
            $table->geometry('geom', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->timestamps();

            $table->unique(['municipio_id', 'numero']);
        });

        DB::statement('CREATE INDEX secciones_geom_gist ON secciones USING GIST (geom);');
    }

    public function down(): void
    {
        Schema::dropIfExists('secciones');
    }
};
