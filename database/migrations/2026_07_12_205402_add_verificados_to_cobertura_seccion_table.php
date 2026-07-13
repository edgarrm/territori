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
        Schema::table('cobertura_seccion', function (Blueprint $table) {
            $table->unsignedInteger('verificados')->default(0)->after('penetracion');
            $table->decimal('movilizacion_verificada', 6, 4)->default(0)->after('verificados');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cobertura_seccion', function (Blueprint $table) {
            $table->dropColumn(['verificados', 'movilizacion_verificada']);
        });
    }
};
