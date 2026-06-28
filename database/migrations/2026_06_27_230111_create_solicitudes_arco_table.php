<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_arco', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // elector_id queda null si el elector se borró físicamente; en la baja
            // lógica (Cancelación) el elector persiste soft-deleted y se conserva el id.
            $table->foreignId('elector_id')->nullable()->constrained('electores')->nullOnDelete();
            $table->string('tipo', 12);
            $table->string('estado', 12)->default('pendiente');
            $table->timestampTz('solicitado_en');
            $table->timestampTz('atendido_en')->nullable();

            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_arco');
    }
};
