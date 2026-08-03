<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La retención por cierre de campaña (LFPDPPP art. 11) medía el plazo con
 * `tenants.updated_at`, bajo la premisa de que nada más muta el tenant después
 * de cerrarlo. Esa premisa dejó de ser cierta cuando se añadió la configuración
 * del análisis electoral: `PATCH /settings/campana` toca el tenant y, con ella,
 * guardar cualquier ajuste en una campaña cerrada aplazaba 180 días más el
 * borrado de la PII — en silencio, sin error ni aviso.
 *
 * `cerrada_en` es el dato real y no lo mueve ninguna otra escritura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('cerrada_en')->nullable()->after('estado');
        });

        // Campañas ya cerradas antes de esta migración: se conserva el plazo que
        // venían corriendo con `updated_at`, para no reiniciarles el reloj.
        DB::table('tenants')
            ->where('estado', 'cerrada')
            ->whereNull('cerrada_en')
            ->update(['cerrada_en' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('cerrada_en');
        });
    }
};
