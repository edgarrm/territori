<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('seccion_id')->constrained('secciones');
            $table->foreignId('membership_id')->constrained('memberships');
            $table->string('modo_captura', 12);
            $table->foreignId('loteria_id')->nullable()->constrained('loterias')->nullOnDelete();
            // eventos llega en Sprint 6; columna nullable sin FK por ahora.
            $table->unsignedBigInteger('evento_id')->nullable();
            $table->string('nombre', 160);
            // telefono/domicilio guardan ciphertext del cast encrypted (ADR-004).
            $table->text('telefono');
            $table->string('telefono_hash', 64)->nullable();
            $table->text('domicilio')->nullable();
            $table->geometry('ubicacion', subtype: 'point', srid: 4326)->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('consentimiento');
            $table->foreignId('aviso_privacidad_id')->constrained('avisos_privacidad');
            $table->timestamps();

            $table->index(['tenant_id', 'seccion_id']);
            $table->index(['tenant_id', 'membership_id']);
            $table->index(['tenant_id', 'telefono_hash']);
        });

        DB::statement('CREATE INDEX electores_ubicacion_gist ON electores USING GIST (ubicacion);');
    }

    public function down(): void
    {
        Schema::dropIfExists('electores');
    }
};
