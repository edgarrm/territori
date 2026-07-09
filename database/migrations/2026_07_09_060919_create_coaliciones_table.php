<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('coaliciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 160);
            $table->unsignedSmallInteger('anio');
            $table->jsonb('partidos');
            $table->timestamps();

            $table->unique(['anio', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaliciones');
    }
};
