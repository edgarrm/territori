<?php

namespace App\Http\Controllers;

use App\Models\AvisoPrivacidad;
use Illuminate\Http\JsonResponse;

class AvisoPrivacidadController extends Controller
{
    public function vigente(): JsonResponse
    {
        $aviso = AvisoPrivacidad::vigente();

        return response()->json([
            'aviso' => $aviso === null ? null : [
                'id' => $aviso->id,
                'version' => $aviso->version,
                'texto' => $aviso->texto,
                'vigente_desde' => $aviso->vigente_desde,
            ],
        ]);
    }
}
