<?php

use App\Http\Controllers\AvisoPrivacidadController;
use App\Http\Controllers\BrigadistaController;
use App\Http\Controllers\CampanaSelectorController;
use App\Http\Controllers\CapturaController;
use App\Http\Controllers\ElectorController;
use App\Http\Controllers\InteraccionController;
use App\Http\Controllers\LoteriaController;
use App\Http\Controllers\MapaController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('campanas/seleccionar', [CampanaSelectorController::class, 'index'])->name('campanas.seleccionar');
    Route::post('campanas/seleccionar', [CampanaSelectorController::class, 'store'])->name('campanas.seleccionar.store');
    Route::get('campanas/sin-membership', [CampanaSelectorController::class, 'sinMembership'])->name('campanas.sin-membership');

    Route::middleware('rol:admin')->group(function () {
        Route::get('campanas/crear', fn () => '')->name('campanas.crear');
    });

    Route::get('mapa', [MapaController::class, 'index'])->name('mapa');
    Route::get('api/cobertura.geojson', [MapaController::class, 'cobertura'])->name('mapa.cobertura');
    Route::get('api/secciones/{seccion}/resumen', [MapaController::class, 'resumenSeccion'])->name('secciones.resumen');

    Route::middleware('rol:coordinador,admin')->group(function () {
        Route::get('metas', [MapaController::class, 'metas'])->name('metas');
        Route::put('api/secciones/{seccion}/meta', [MapaController::class, 'definirMeta'])->name('secciones.meta');

        Route::get('brigadistas', [BrigadistaController::class, 'index'])->name('brigadistas');
        Route::post('brigadistas', [BrigadistaController::class, 'store'])->name('brigadistas.store');
        Route::put('api/brigadistas/{membership}/activo', [BrigadistaController::class, 'activo'])->name('brigadistas.activo');
        Route::put('api/brigadistas/{membership}/zonas', [BrigadistaController::class, 'zonas'])->name('brigadistas.zonas');
        Route::get('api/brigadistas/{membership}/ratios', [BrigadistaController::class, 'ratios'])->name('brigadistas.ratios');
    });

    // Captura de electores (cualquier rol con membership; el 403 lo aplica el FormRequest/acción).
    Route::get('captura', [CapturaController::class, 'index'])->name('captura');
    Route::get('api/avisos-privacidad/vigente', [AvisoPrivacidadController::class, 'vigente'])->name('avisos.vigente');

    Route::post('api/loterias', [LoteriaController::class, 'store'])->name('loterias.store');
    Route::post('api/loterias/{loteria}/cerrar', [LoteriaController::class, 'cerrar'])->name('loterias.cerrar');
    Route::get('api/loterias/activa', [LoteriaController::class, 'activa'])->name('loterias.activa');

    Route::post('api/electores', [ElectorController::class, 'store'])->name('electores.store');
    Route::get('api/electores/{elector}', [ElectorController::class, 'show'])->name('electores.show');
    Route::put('api/electores/{elector}', [ElectorController::class, 'update'])->name('electores.update');
    Route::get('electores/{elector}', [ElectorController::class, 'page'])->name('electores.page');
    Route::get('api/secciones/{seccion}/electores', [ElectorController::class, 'indexPorSeccion'])->name('secciones.electores');

    // Interacciones (timeline) + agenda de seguimientos.
    Route::get('api/electores/{elector}/interacciones', [InteraccionController::class, 'indexPorElector'])->name('interacciones.index');
    Route::post('api/electores/{elector}/interacciones', [InteraccionController::class, 'store'])->name('interacciones.store');
    Route::put('api/interacciones/{interaccion}/atendido', [InteraccionController::class, 'atendido'])->name('interacciones.atendido');
    Route::get('agenda', [InteraccionController::class, 'agenda'])->name('agenda');
    Route::get('api/agenda', [InteraccionController::class, 'agendaData'])->name('agenda.data');
});

require __DIR__.'/settings.php';
