<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interacciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('elector_id')->constrained('electores')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships');
            $table->string('tipo', 12);
            $table->string('resultado', 16)->nullable();
            $table->text('nota')->nullable();
            $table->timestampTz('fecha');
            $table->date('proximo_seguimiento')->nullable();
            // atendido_en saca el seguimiento de la agenda (decisión Sprint 6).
            $table->timestampTz('atendido_en')->nullable();
            // Solo created_at (data-model): sin updated_at.
            $table->timestampTz('created_at')->nullable();

            $table->index(['tenant_id', 'elector_id']);
            $table->index(['tenant_id', 'membership_id', 'proximo_seguimiento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interacciones');
    }
};
