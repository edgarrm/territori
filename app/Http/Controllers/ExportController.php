<?php

namespace App\Http\Controllers;

use App\Actions\Privacidad\RegistrarAcceso;
use App\Models\Elector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Tope de filas por volcado (P4). Un export es el acceso masivo a PII con
     * más impacto de la aplicación; sin tope, un solo GET saca el padrón entero.
     * El truncamiento se registra en la bitácora para que sea visible.
     */
    private const MAX_FILAS = 50000;

    /**
     * Exporta los electores del tenant a CSV (rol coordinador/admin vía ruta).
     * Filtros: seccion_id, desde, hasta (sobre created_at). PII descifrada por el
     * cast del modelo. Cancelados (soft-deleted) quedan fuera por el global scope.
     * Cada volcado deja traza en la bitácora de accesos (filtros y conteo).
     */
    public function electores(Request $request, RegistrarAcceso $bitacora): StreamedResponse
    {
        $validado = $request->validate([
            'seccion_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $query = Elector::query()->with('seccion:id,numero')->latest();

        if (! empty($validado['seccion_id'])) {
            $query->where('seccion_id', (int) $validado['seccion_id']);
        }
        if (! empty($validado['desde'])) {
            $query->where('created_at', '>=', $validado['desde']);
        }
        if (! empty($validado['hasta'])) {
            $query->where('created_at', '<=', $validado['hasta']);
        }

        // El conteo se resuelve antes de abrir el stream: una vez enviadas las
        // cabeceras ya no hay forma de registrar el volcado si algo falla.
        $coincidencias = $query->count();
        $filas = min($coincidencias, self::MAX_FILAS);

        $query->limit(self::MAX_FILAS);

        // Solo filtros y conteos: ni un dato personal en la bitácora.
        $bitacora->handle('export.electores', 'elector', null, [
            'filtros' => array_filter($validado, fn ($valor): bool => $valor !== null && $valor !== ''),
            'filas' => $filas,
            'truncado' => $coincidencias > self::MAX_FILAS,
        ]);

        $encabezados = ['id', 'nombre', 'telefono', 'domicilio', 'seccion', 'modo_captura', 'consentimiento', 'capturado_en'];

        return response()->streamDownload(function () use ($query, $encabezados): void {
            $salida = fopen('php://output', 'w');

            if ($salida === false) {
                return;
            }

            fputcsv($salida, $encabezados);

            foreach ($query->cursor() as $elector) {
                fputcsv($salida, [
                    $elector->id,
                    $this->seguro($elector->nombre),
                    $this->seguro($elector->telefono),
                    $this->seguro($elector->domicilio ?? ''),
                    $elector->seccion->numero ?? '',
                    $elector->modo_captura,
                    $elector->consentimiento ? 'si' : 'no',
                    $elector->created_at?->toIso8601String() ?? '',
                ]);
            }

            fclose($salida);
        }, 'electores.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Mitiga CSV injection: prefija con comilla simple las celdas que abran con
     * un carácter que Excel/Sheets interpretaría como fórmula.
     */
    private function seguro(string $valor): string
    {
        if ($valor !== '' && in_array($valor[0], ['=', '+', '-', '@'], true)) {
            return "'".$valor;
        }

        return $valor;
    }
}
