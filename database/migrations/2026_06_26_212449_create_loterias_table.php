<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loterias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignId('seccion_id')->constrained('secciones')->cascadeOnDelete();
            $table->timestamp('abierta_en');
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'membership_id', 'cerrada_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loterias');
    }
};
