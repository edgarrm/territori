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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 160);
            $table->foreignId('municipio_id')->constrained('municipios');
            $table->string('plan', 40)->default('basico');
            $table->string('estado', 20)->default('prueba');
            $table->unsignedInteger('limite_brigadistas')->nullable();
            $table->string('marca_nombre', 120)->nullable();
            $table->string('marca_logo_url')->nullable();
            $table->string('marca_color', 7)->nullable();
            $table->string('subdominio', 63)->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
