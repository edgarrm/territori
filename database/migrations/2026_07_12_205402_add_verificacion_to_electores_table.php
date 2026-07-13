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
        Schema::table('electores', function (Blueprint $table) {
            $table->timestampTz('verificado_en')->nullable()->after('observaciones');
            $table->string('verificado_via', 12)->nullable()->after('verificado_en');
            $table->foreignId('verificado_membership_id')->nullable()->after('verificado_via')->constrained('memberships')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('electores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verificado_membership_id');
            $table->dropColumn(['verificado_en', 'verificado_via']);
        });
    }
};
