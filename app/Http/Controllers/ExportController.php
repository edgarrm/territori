<?php

namespace App\Http\Controllers;

use App\Models\Elector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Exporta los electores del tenant a CSV (rol coordinador/admin vía ruta).
     * Filtros: seccion_id, desde, hasta (sobre created_at). PII descifrada por el
     * cast del modelo. Cancelados (soft-deleted) quedan fuera por el global scope.
     */
    public function electores(Request $request): StreamedResponse
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
