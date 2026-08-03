<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La política de borrado de las FK hacia `memberships` era inconsistente, y eso
 * convertía "borrar mi cuenta" en dos fallos distintos:
 *
 * - `loterias.membership_id` y `redes_ciudadanas.enlace_membership_id` estaban
 *   en CASCADE, así que borrar un usuario arrastraba en silencio sus loterías y
 *   sus redes ciudadanas — datos de la campaña, no del usuario.
 * - `interacciones.membership_id` estaba en NO ACTION, así que el borrado
 *   reventaba con SQLSTATE[23503] para cualquiera que hubiera registrado una
 *   sola interacción.
 *
 * Se unifican a SET NULL, que es lo que ya hacían `electores.membership_id`,
 * `bitacora_accesos.membership_id` y `solicitudes_arco.membership_id`: el dato
 * de campaña sobrevive y solo se pierde la atribución del miembro que se fue.
 */
return new class extends Migration
{
    /**
     * Tabla => columna cuya FK hacia `memberships` pasa a SET NULL.
     *
     * @var array<string, string>
     */
    private const COLUMNAS = [
        'loterias' => 'membership_id',
        'redes_ciudadanas' => 'enlace_membership_id',
        'interacciones' => 'membership_id',
    ];

    public function up(): void
    {
        foreach (self::COLUMNAS as $tabla => $columna) {
            Schema::table($tabla, function (Blueprint $table) use ($columna): void {
                $table->dropForeign([$columna]);
                $table->unsignedBigInteger($columna)->nullable()->change();
                $table->foreign($columna)->references('id')->on('memberships')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Se restaura la política previa (CASCADE / NO ACTION) pero NO la
        // obligatoriedad de la columna: volver a NOT NULL fallaría si ya hay
        // filas con la atribución en null, que es justo lo que esta migración
        // hace posible.
        Schema::table('loterias', function (Blueprint $table): void {
            $table->dropForeign(['membership_id']);
            $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
        });

        Schema::table('redes_ciudadanas', function (Blueprint $table): void {
            $table->dropForeign(['enlace_membership_id']);
            $table->foreign('enlace_membership_id')->references('id')->on('memberships')->cascadeOnDelete();
        });

        Schema::table('interacciones', function (Blueprint $table): void {
            $table->dropForeign(['membership_id']);
            $table->foreign('membership_id')->references('id')->on('memberships');
        });
    }
};
