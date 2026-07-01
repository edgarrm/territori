<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electores', function (Blueprint $table) {
            // Email es PII opcional: se cifra en reposo igual que telefono/domicilio
            // (ADR-004), por eso la columna es text y no string.
            $table->text('email')->nullable()->after('telefono_hash');
        });
    }

    public function down(): void
    {
        Schema::table('electores', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
