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
        Schema::create('cobertura_seccion', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->unsignedInteger('capturados')->default(0);
            $table->unsignedInteger('meta')->default(0);
            $table->decimal('cobertura', 6, 4)->default(0);
            $table->decimal('penetracion', 6, 4)->default(0);
            $table->timestampTz('actualizado_en')->nullable();

            $table->primary(['tenant_id', 'seccion_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cobertura_seccion');
    }
};
