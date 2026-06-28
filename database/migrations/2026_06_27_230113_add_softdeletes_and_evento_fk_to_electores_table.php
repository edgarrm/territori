<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electores', function (Blueprint $table) {
            $table->softDeletesTz();
            // FK pendiente desde Sprint 4: la columna evento_id ya existe nullable.
            $table->foreign('evento_id')->references('id')->on('eventos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('electores', function (Blueprint $table) {
            $table->dropForeign(['evento_id']);
            $table->dropSoftDeletesTz();
        });
    }
};
