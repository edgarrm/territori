<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10: la tabla que existe para dar trazabilidad a los derechos ARCO no
 * registraba quién atendió (`membership_id`), cómo contactar al titular para
 * entregarle la resolución (`titular_contacto`) ni cuándo se creó o modificó la
 * fila (`$timestamps = false`). Sin eso no hay forma de acreditar ante el INAI
 * que la solicitud se atendió en plazo ni por quién.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_arco', function (Blueprint $table): void {
            // Quien atendió la solicitud. Nulo mientras está pendiente y en las
            // filas previas a esta migración.
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            // Dato de contacto del titular (correo o teléfono) para entregarle la
            // resolución. Cifrado: es dato personal de quien ejerce el derecho.
            $table->text('titular_contacto')->nullable();
            $table->timestampsTz();
        });

        // Las filas existentes no tienen created_at: se toma solicitado_en, que
        // es el momento real en que entró la solicitud.
        DB::statement('UPDATE solicitudes_arco SET created_at = solicitado_en, updated_at = COALESCE(atendido_en, solicitado_en) WHERE created_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('solicitudes_arco', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('membership_id');
            $table->dropColumn(['titular_contacto', 'created_at', 'updated_at']);
        });
    }
};
