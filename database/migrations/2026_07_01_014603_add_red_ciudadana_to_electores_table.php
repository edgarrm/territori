<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electores', function (Blueprint $table) {
            // Espejo de loteria_id/evento_id: origen de la captura cuando el
            // modo_captura es 'red_ciudadana'.
            $table->foreignId('red_ciudadana_id')
                ->nullable()
                ->after('evento_id')
                ->constrained('redes_ciudadanas')
                ->nullOnDelete();

            // 'red_ciudadana' (13) no cabe en el varchar(12) original.
            $table->string('modo_captura', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('electores', function (Blueprint $table) {
            $table->dropForeign(['red_ciudadana_id']);
            $table->dropColumn('red_ciudadana_id');
        });
    }
};
