<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metas_seccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->unsignedInteger('meta_capturas');
            $table->string('fuente_meta', 20);
            $table->decimal('pct_lista_nominal', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'seccion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metas_seccion');
    }
};
