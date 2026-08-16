<?php

namespace App\Services\Encuestas;

use App\Contracts\Encuestas\FuenteEncuesta;
use App\Models\Encuesta;
use App\Support\GoogleSheets\ClienteSheets;

/**
 * Canal pull Google Sheets (F9): traduce filas del Sheet a
 * {identificador, respuestas}. `configuracion_canal` de la Encuesta trae
 * {spreadsheet_id, nombre_hoja, campo_identificador} — nada de credenciales,
 * esas viven en conexiones_google a nivel tenant.
 */
class GoogleSheetsFuente implements FuenteEncuesta
{
    public function verificarAcceso(Encuesta $encuesta): array
    {
        [$spreadsheetId, $nombreHoja] = $this->configuracion($encuesta);

        $valores = $this->cliente($encuesta)->obtenerValores($spreadsheetId, $nombreHoja);
        $encabezados = $valores[0] ?? [];

        return [
            'ok' => true,
            'columnas' => $encabezados,
            'filas' => max(count($valores) - 1, 0),
        ];
    }

    public function obtenerRespuestas(Encuesta $encuesta): iterable
    {
        [$spreadsheetId, $nombreHoja, $campoIdentificador] = $this->configuracion($encuesta);

        $valores = $this->cliente($encuesta)->obtenerValores($spreadsheetId, $nombreHoja);

        if ($valores === []) {
            return;
        }

        $encabezados = array_shift($valores);
        $indiceIdentificador = array_search($campoIdentificador, $encabezados, true);

        if ($indiceIdentificador === false) {
            return;
        }

        foreach ($valores as $fila) {
            $identificador = trim((string) ($fila[$indiceIdentificador] ?? ''));
            $respuestas = [];

            foreach ($encabezados as $indice => $campo) {
                if ($indice === $indiceIdentificador) {
                    continue;
                }

                $respuestas[$campo] = $fila[$indice] ?? null;
            }

            yield ['identificador' => $identificador, 'respuestas' => $respuestas];
        }
    }

    private function cliente(Encuesta $encuesta): ClienteSheets
    {
        return new ClienteSheets($encuesta->tenant_id);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function configuracion(Encuesta $encuesta): array
    {
        $config = $encuesta->configuracion_canal ?? [];

        return [
            (string) ($config['spreadsheet_id'] ?? ''),
            (string) ($config['nombre_hoja'] ?? ''),
            (string) ($config['campo_identificador'] ?? ''),
        ];
    }
}
