<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9: el derecho de Oposición (LFPDPPP) no tenía efecto técnico — marcar la
 * solicitud "atendida" era un acuse vacío. `no_contactar` es ese efecto: saca
 * al elector de la agenda de seguimientos y del export, que son las dos
 * superficies desde las que se le contacta.
 *
 * Además de `consentimiento_en` / `consentimiento_via` (P17): hasta ahora el
 * consentimiento se guardaba hardcodeado a `true` y sin fecha ni modalidad, así
 * que era indemostrable ante el INAI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electores', function (Blueprint $table): void {
            $table->boolean('no_contactar')->default(false);
            // Modalidad del consentimiento: 'verbal' (captura en campo, el caso
            // por defecto) o 'escrito'. Nulo en los registros previos a esta
            // migración: no se puede inventar cómo consintieron.
            $table->timestampTz('consentimiento_en')->nullable();
            $table->string('consentimiento_via', 20)->nullable();

            $table->index(['tenant_id', 'no_contactar']);
        });
    }

    public function down(): void
    {
        Schema::table('electores', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'no_contactar']);
            $table->dropColumn(['no_contactar', 'consentimiento_en', 'consentimiento_via']);
        });
    }
};
