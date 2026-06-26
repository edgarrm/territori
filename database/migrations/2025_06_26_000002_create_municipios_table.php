<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entidad_id')->constrained('entidades')->cascadeOnDelete();
            $table->unsignedSmallInteger('clave');
            $table->string('nombre')->nullable();
            $table->geometry('geom', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->timestamps();

            $table->unique(['entidad_id', 'clave']);
        });

        DB::statement('CREATE INDEX municipios_geom_gist ON municipios USING GIST (geom);');
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
    }
};
