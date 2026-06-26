<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entidades', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('clave')->unique();
            $table->string('nombre');
            $table->geometry('geom', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX entidades_geom_gist ON entidades USING GIST (geom);');
    }

    public function down(): void
    {
        Schema::dropIfExists('entidades');
    }
};
