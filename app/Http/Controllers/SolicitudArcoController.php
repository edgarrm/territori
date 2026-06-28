<?php

namespace App\Http\Controllers;

use App\Actions\Privacidad\RegistrarSolicitudArco;
use App\Http\Requests\StoreSolicitudArcoRequest;
use Illuminate\Http\JsonResponse;

class SolicitudArcoController extends Controller
{
    public function store(StoreSolicitudArcoRequest $request, RegistrarSolicitudArco $registrar): JsonResponse
    {
        $solicitud = $registrar->handle($request->validated());

        return response()->json([
            'id' => $solicitud->id,
            'tipo' => $solicitud->tipo,
            'estado' => $solicitud->estado,
            'elector_id' => $solicitud->elector_id,
        ], 201);
    }
}
