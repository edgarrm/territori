<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La lotería deja de ser una sesión efímera (abrir/cerrar) para volverse un
 * registro persistente con nombre y fecha, gestionado por su encargado
 * (membership_id). Espejo del modelo de Evento, más el encargado.
 *
 * Respalda las loterías existentes: la fecha hereda de abierta_en y el nombre
 * se deriva de la sección, para no romper filas ya capturadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loterias', function (Blueprint $table) {
            $table->string('nombre', 160)->nullable()->after('seccion_id');
            $table->timestampTz('fecha')->nullable()->after('nombre');
        });

        DB::table('loterias')->whereNull('fecha')
            ->update(['fecha' => DB::raw('COALESCE(abierta_en, created_at, now())')]);
        DB::table('loterias')->whereNull('nombre')
            ->update(['nombre' => DB::raw("'Lotería sección ' || seccion_id")]);

        Schema::table('loterias', function (Blueprint $table) {
            $table->string('nombre', 160)->nullable(false)->change();
            $table->timestampTz('fecha')->nullable(false)->change();

            $table->dropIndex(['tenant_id', 'membership_id', 'cerrada_en']);
            $table->dropColumn(['abierta_en', 'cerrada_en']);

            $table->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::table('loterias', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'fecha']);

            $table->timestamp('abierta_en')->nullable();
            $table->timestamp('cerrada_en')->nullable();
            $table->dropColumn(['nombre', 'fecha']);

            $table->index(['tenant_id', 'membership_id', 'cerrada_en']);
        });
    }
};
