<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redes_ciudadanas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // El enlace es la membership responsable de la red; puede ser de
            // cualquier rol (brigadista, coordinador, admin o el rol dedicado
            // "enlace"). La red se captura y se ve a través de él.
            $table->foreignId('enlace_membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->string('nombre', 160);
            $table->text('descripcion')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enlace_membership_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redes_ciudadanas');
    }
};
