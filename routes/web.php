<?php

use App\Http\Controllers\CampanaSelectorController;
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

    Route::inertia('mapa', 'Mapa')->name('mapa');
    Route::get('api/cobertura.geojson', [MapaController::class, 'cobertura'])->name('mapa.cobertura');
    Route::get('api/secciones/{seccion}/resumen', [MapaController::class, 'resumenSeccion'])->name('secciones.resumen');

    Route::middleware('rol:coordinador,admin')->group(function () {
        Route::get('metas', [MapaController::class, 'metas'])->name('metas');
        Route::put('api/secciones/{seccion}/meta', [MapaController::class, 'definirMeta'])->name('secciones.meta');
    });
});

require __DIR__.'/settings.php';
