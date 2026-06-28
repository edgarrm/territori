<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nombre', 160);
            $table->string('tipo', 40);
            $table->timestampTz('fecha');
            $table->string('lugar', 200)->nullable();
            $table->foreignId('seccion_id')->nullable()->constrained('secciones');
            $table->geometry('ubicacion', subtype: 'point', srid: 4326)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fecha']);
        });

        DB::statement('CREATE INDEX eventos_ubicacion_gist ON eventos USING GIST (ubicacion);');
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos');
    }
};
