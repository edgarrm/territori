<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Renombra el modo de captura 'individual' a 'enlace_seccional' en los
     * electores ya capturados, para alinear el dato con el nuevo nombre.
     */
    public function up(): void
    {
        DB::table('electores')
            ->where('modo_captura', 'individual')
            ->update(['modo_captura' => 'enlace_seccional']);
    }

    public function down(): void
    {
        DB::table('electores')
            ->where('modo_captura', 'enlace_seccional')
            ->update(['modo_captura' => 'individual']);
    }
};
