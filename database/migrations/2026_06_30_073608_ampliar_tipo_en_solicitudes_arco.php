<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'rectificacion' (13 caracteres) no cabe en varchar(12). Se amplía el tipo
     * para soportar los cuatro derechos ARCO sin truncar.
     */
    public function up(): void
    {
        Schema::table('solicitudes_arco', function (Blueprint $table) {
            $table->string('tipo', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_arco', function (Blueprint $table) {
            $table->string('tipo', 12)->change();
        });
    }
};
