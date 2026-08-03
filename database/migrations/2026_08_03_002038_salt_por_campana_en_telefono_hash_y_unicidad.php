<?php

use App\Support\Telefono;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P11 + P18 + P19, en un solo paso porque el índice único se define sobre la
 * columna que el recálculo reescribe:
 *
 * - **P11**: `telefono_hash` se recalcula con el salt por campaña. Con el HMAC
 *   anterior (solo `APP_KEY`) el mismo teléfono daba el mismo hash en campañas
 *   rivales del mismo host, así que comparar las columnas de dos tenants
 *   revelaba qué contactos comparten sin descifrar nada.
 * - **P18**: índice UNIQUE parcial sobre `(tenant_id, telefono_hash)` para los
 *   electores vivos. El dedup de la captura era un `SELECT` seguido de un
 *   `INSERT`: dos peticiones simultáneas con el mismo número creaban dos filas.
 *   Es parcial porque los cancelados tienen `telefono_hash` nulo y porque el
 *   mismo número debe poder recapturarse tras una Cancelación.
 * - **P19**: `electores.membership_id` pasa a `nullOnDelete`. Con RESTRICT,
 *   borrar un `User` con capturas reventaba con violación de FK, lo que en la
 *   práctica impedía ejercer el derecho de un *usuario* a que lo borren.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El recálculo va primero: el índice único se crea ya sobre los hashes
        // definitivos, y si el salt destapara un duplicado real la migración
        // falla ahí y no a medias.
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            $filas = DB::table('electores')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('telefono_hash')
                ->orderBy('id')
                ->select(['id', 'telefono']);

            foreach ($filas->cursor() as $fila) {
                DB::table('electores')->where('id', $fila->id)->update([
                    'telefono_hash' => Telefono::hash(
                        Crypt::decryptString((string) $fila->telefono),
                        (int) $tenantId,
                    ),
                ]);
            }
        }

        // El dedup por SELECT+INSERT ya dejó duplicados en las bases existentes
        // (es justo la carrera que P18 cierra). No se borra ninguna fila: se
        // conserva la más antigua con su hash y a las posteriores se les quita
        // —el teléfono sigue guardado en `telefono`, así que no se pierde nada
        // y quedan localizables para resolverlas a mano.
        DB::statement(
            'UPDATE electores SET telefono_hash = NULL
             WHERE id IN (
                 SELECT id FROM (
                     SELECT id, row_number() OVER (
                         PARTITION BY tenant_id, telefono_hash ORDER BY id
                     ) AS posicion
                     FROM electores
                     WHERE telefono_hash IS NOT NULL AND deleted_at IS NULL
                 ) duplicados
                 WHERE posicion > 1
             )'
        );

        DB::statement(
            'CREATE UNIQUE INDEX electores_tenant_telefono_hash_unique
             ON electores (tenant_id, telefono_hash)
             WHERE telefono_hash IS NOT NULL AND deleted_at IS NULL'
        );

        Schema::table('electores', function (Blueprint $table): void {
            $table->dropForeign(['membership_id']);
            $table->unsignedBigInteger('membership_id')->nullable()->change();
            $table->foreign('membership_id')->references('id')->on('memberships')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('electores', function (Blueprint $table): void {
            $table->dropForeign(['membership_id']);
            $table->unsignedBigInteger('membership_id')->nullable(false)->change();
            $table->foreign('membership_id')->references('id')->on('memberships');
        });

        DB::statement('DROP INDEX IF EXISTS electores_tenant_telefono_hash_unique');
    }
};
