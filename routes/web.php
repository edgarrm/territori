<?php

use App\Http\Controllers\AvisoPrivacidadController;
use App\Http\Controllers\BrigadistaController;
use App\Http\Controllers\CampanaController;
use App\Http\Controllers\CampanaSelectorController;
use App\Http\Controllers\CapturaController;
use App\Http\Controllers\ElectorController;
use App\Http\Controllers\EventoController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\InteraccionController;
use App\Http\Controllers\LoteriaController;
use App\Http\Controllers\MapaController;
use App\Http\Controllers\SolicitudArcoController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Selección de campaña: no requiere una campaña activa todavía.
    Route::get('campanas/seleccionar', [CampanaSelectorController::class, 'index'])->name('campanas.seleccionar');
    Route::post('campanas/seleccionar', [CampanaSelectorController::class, 'store'])->name('campanas.seleccionar.store');
    Route::get('campanas/sin-membership', [CampanaSelectorController::class, 'sinMembership'])->name('campanas.sin-membership');

    // Rutas de dominio: exigen una campaña activa (o enrutan al selector).
    // throttle limita el scraping del catálogo de contactos (PII) por usuario/IP.
    Route::middleware(['tenant', 'throttle:120,1'])->group(function () {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');

        // Crear campaña: solo un admin de la campaña activa.
        Route::middleware('rol:admin')->group(function () {
            Route::get('campanas/crear', [CampanaController::class, 'create'])->name('campanas.crear');
            Route::post('campanas', [CampanaController::class, 'store'])->name('campanas.store');
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

            // Cancelación ARCO (baja lógica) y export: acciones de gestión.
            Route::delete('api/electores/{elector}', [ElectorController::class, 'destroy'])->name('electores.destroy');
            Route::get('api/export/electores.csv', [ExportController::class, 'electores'])->name('export.electores');
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
        Route::get('secciones/{seccion}', [MapaController::class, 'detalle'])->name('secciones.detalle');
        Route::get('api/secciones/{seccion}/electores', [ElectorController::class, 'indexPorSeccion'])->name('secciones.electores');

        // Interacciones (timeline) + agenda de seguimientos.
        Route::get('api/electores/{elector}/interacciones', [InteraccionController::class, 'indexPorElector'])->name('interacciones.index');
        Route::post('api/electores/{elector}/interacciones', [InteraccionController::class, 'store'])->name('interacciones.store');
        Route::put('api/interacciones/{interaccion}/atendido', [InteraccionController::class, 'atendido'])->name('interacciones.atendido');
        Route::get('agenda', [InteraccionController::class, 'agenda'])->name('agenda');
        Route::get('api/agenda', [InteraccionController::class, 'agendaData'])->name('agenda.data');

        // Eventos (cualquier miembro) + solicitudes ARCO.
        Route::get('eventos', [EventoController::class, 'index'])->name('eventos');
        Route::post('eventos', [EventoController::class, 'store'])->name('eventos.store');
        Route::get('api/eventos/{evento}/asistentes', [EventoController::class, 'asistentes'])->name('eventos.asistentes');
        Route::post('api/solicitudes-arco', [SolicitudArcoController::class, 'store'])->name('solicitudes-arco.store');
    });
});

require __DIR__.'/settings.php';
