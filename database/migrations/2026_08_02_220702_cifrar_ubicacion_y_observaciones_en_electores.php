<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7: `ubicacion` (Point WGS84) y `observaciones` se guardaban en claro, lo que
 * anulaba el cifrado de `domicilio` — una coordenada exacta *es* el domicilio, y
 * las observaciones son texto libre sobre la persona.
 *
 * `ubicacion` pasa de `geometry(Point,4326)` a `text` con cast `encrypted:array`
 * (`{lat, lng}`). Se pierde el índice GiST y la capacidad de consultar por
 * geometría, que nadie usaba: la resolución de sección al capturar hace
 * `ST_Contains` del punto de entrada contra `secciones.geom`, no contra esta
 * columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electores', function (Blueprint $table): void {
            $table->text('ubicacion_cifrada')->nullable();
        });

        DB::table('electores')
            ->whereNotNull('ubicacion')
            ->orderBy('id')
            ->select(['id', DB::raw('ST_Y(ubicacion::geometry) as lat'), DB::raw('ST_X(ubicacion::geometry) as lng')])
            ->chunk(500, function ($filas): void {
                foreach ($filas as $fila) {
                    DB::table('electores')->where('id', $fila->id)->update([
                        'ubicacion_cifrada' => Crypt::encryptString((string) json_encode([
                            'lat' => (float) $fila->lat,
                            'lng' => (float) $fila->lng,
                        ])),
                    ]);
                }
            });

        DB::statement('DROP INDEX IF EXISTS electores_ubicacion_gist;');

        Schema::table('electores', function (Blueprint $table): void {
            $table->dropColumn('ubicacion');
        });

        Schema::table('electores', function (Blueprint $table): void {
            $table->renameColumn('ubicacion_cifrada', 'ubicacion');
        });

        DB::table('electores')
            ->whereNotNull('observaciones')
            ->orderBy('id')
            ->select(['id', 'observaciones'])
            ->chunk(500, function ($filas): void {
                foreach ($filas as $fila) {
                    DB::table('electores')->where('id', $fila->id)->update([
                        'observaciones' => Crypt::encryptString((string) $fila->observaciones),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('electores')
            ->whereNotNull('observaciones')
            ->orderBy('id')
            ->select(['id', 'observaciones'])
            ->chunk(500, function ($filas): void {
                foreach ($filas as $fila) {
                    DB::table('electores')->where('id', $fila->id)->update([
                        'observaciones' => Crypt::decryptString((string) $fila->observaciones),
                    ]);
                }
            });

        Schema::table('electores', function (Blueprint $table): void {
            $table->geometry('ubicacion_punto', subtype: 'point', srid: 4326)->nullable();
        });

        DB::table('electores')
            ->whereNotNull('ubicacion')
            ->orderBy('id')
            ->select(['id', 'ubicacion'])
            ->chunk(500, function ($filas): void {
                foreach ($filas as $fila) {
                    /** @var array{lat: float, lng: float} $punto */
                    $punto = json_decode(Crypt::decryptString((string) $fila->ubicacion), true);

                    DB::statement(
                        'UPDATE electores SET ubicacion_punto = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                        [$punto['lng'], $punto['lat'], $fila->id],
                    );
                }
            });

        Schema::table('electores', function (Blueprint $table): void {
            $table->dropColumn('ubicacion');
        });

        Schema::table('electores', function (Blueprint $table): void {
            $table->renameColumn('ubicacion_punto', 'ubicacion');
        });

        DB::statement('CREATE INDEX electores_ubicacion_gist ON electores USING GIST (ubicacion);');
    }
};
