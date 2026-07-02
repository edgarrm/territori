<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa creador de encargado en loterías: membership_id queda como el
 * asignado/encargado y creada_por_membership_id guarda quién la creó
 * (nullable: si el creador se borra, la lotería sigue siendo del asignado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loterias', function (Blueprint $table) {
            $table->foreignId('creada_por_membership_id')
                ->nullable()
                ->after('membership_id')
                ->constrained('memberships')
                ->nullOnDelete();
        });

        DB::table('loterias')->whereNull('creada_por_membership_id')
            ->update(['creada_por_membership_id' => DB::raw('membership_id')]);
    }

    public function down(): void
    {
        Schema::table('loterias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creada_por_membership_id');
        });
    }
};
