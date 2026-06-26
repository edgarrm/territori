<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_privacidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('version', 20);
            $table->text('texto');
            $table->timestamp('vigente_desde');
            $table->timestamps();

            $table->index(['tenant_id', 'vigente_desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_privacidad');
    }
};
