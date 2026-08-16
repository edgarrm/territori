<?php

use App\Http\Controllers\AvisoPrivacidadController;
use App\Http\Controllers\BrigadistaController;
use App\Http\Controllers\CampanaController;
use App\Http\Controllers\CampanaSelectorController;
use App\Http\Controllers\CapturaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ElectorController;
use App\Http\Controllers\EventoController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\InteraccionController;
use App\Http\Controllers\LoteriaController;
use App\Http\Controllers\MapaController;
use App\Http\Controllers\RedCiudadanaController;
use App\Http\Controllers\Settings\CampanaController as SettingsCampanaController;
use App\Http\Controllers\SolicitudArcoController;
use App\Http\Controllers\TerritorioController;
use Illuminate\Auth\Middleware\RequirePassword;
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
        // --- Rutas accesibles también al rol "enlace" (captura en sus redes) ---
        // El enlace tiene acceso restringido: solo sus redes ciudadanas, capturar
        // en ellas y leer el aviso de privacidad vigente para el consentimiento.
        Route::get('redes-ciudadanas', [RedCiudadanaController::class, 'index'])->name('redes-ciudadanas.index');
        Route::get('api/redes-ciudadanas/{red}/registros', [RedCiudadanaController::class, 'registros'])->name('redes-ciudadanas.registros');
        // Throttle propio, más estricto que el del grupo: limita el oráculo de
        // enumeración del 409 por teléfono duplicado (A6). Prefijo distinto:
        // el throttle sin nombre firma la clave solo por user id, así que sin
        // esto compartiría contador con el throttle:120,1 del grupo y dispararía
        // el límite de 20 mucho antes de las 20 peticiones reales.
        Route::post('api/electores', [ElectorController::class, 'store'])->middleware('throttle:20,1,electores-store')->name('electores.store');
        Route::get('api/avisos-privacidad/vigente', [AvisoPrivacidadController::class, 'vigente'])->name('avisos.vigente');

        // Crear red / designar enlace: solo gestión.
        Route::middleware('rol:coordinador,admin')->group(function () {
            Route::post('redes-ciudadanas', [RedCiudadanaController::class, 'store'])->name('redes-ciudadanas.store');
        });

        // --- Loterías: incluye al rol "anfitrion" (acceso restringido solo a
        // sus loterías); el enlace queda fuera (403) ---
        Route::middleware('rol:anfitrion,brigadista,coordinador,admin')->group(function () {
            Route::get('loterias', [LoteriaController::class, 'index'])->name('loterias.index');
            Route::post('loterias', [LoteriaController::class, 'store'])->name('loterias.store');
            Route::get('api/loterias/{loteria}/electores', [LoteriaController::class, 'electores'])->name('loterias.electores');
        });

        // --- Resto del dominio: el rol "enlace" queda fuera (403) ---
        Route::middleware('rol:brigadista,coordinador,admin')->group(function () {
            // Crear campaña: solo un admin de la campaña activa.
            Route::middleware('rol:admin')->group(function () {
                Route::get('campanas/crear', [CampanaController::class, 'create'])->name('campanas.crear');
                Route::post('campanas', [CampanaController::class, 'store'])->name('campanas.store');

                // Cerrar campaña: arranca la retención por finalidad agotada
                // (LFPDPPP art. 11) y acaba borrando la PII de sus electores.
                // Irreversible desde la app, así que lleva los mismos controles
                // que el export: 2FA + reconfirmar contraseña.
                Route::patch('campanas/cerrar', [CampanaController::class, 'cerrar'])
                    ->middleware(['2fa', RequirePassword::class])
                    ->name('campanas.cerrar');

                // Configuración del análisis electoral (partido, umbrales, indicadores).
                Route::get('settings/campana', [SettingsCampanaController::class, 'edit'])->name('campana.edit');
                Route::patch('settings/campana', [SettingsCampanaController::class, 'update'])->name('campana.update');
            });

            // Vista personal de capturados: pantalla de aterrizaje del brigadista.
            Route::get('mis-capturados', [ElectorController::class, 'mios'])->name('mis-capturados');
            Route::get('api/mis-capturados', [ElectorController::class, 'misDatos'])->name('mis-capturados.data');

            // Agregación territorial (F5): resumen y geometría a nivel
            // municipio/estado. El brigadista solo agrega sus secciones
            // asignadas (mismo criterio que mapa.cobertura).
            Route::get('api/territorio/resumen', [TerritorioController::class, 'resumen'])->name('territorio.resumen');
            Route::get('api/territorio/geojson', [TerritorioController::class, 'geojson'])->name('territorio.geojson');

            // --- Análisis y planeación: fuera del alcance del brigadista, cuyo
            // perfil es de captura (dashboard, mapa, prioridades, detalle de
            // sección y las vistas de gestión) ---
            Route::middleware('rol:coordinador,admin')->group(function () {
                // El dashboard solo expone agregados (conteos, metas, próximos
                // eventos): no lleva 2FA. La exigencia vive donde está la PII.
                Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

                Route::get('mapa', [MapaController::class, 'index'])->name('mapa');
                Route::get('api/cobertura.geojson', [MapaController::class, 'cobertura'])->name('mapa.cobertura');
                Route::get('prioridades', [MapaController::class, 'prioridades'])->name('prioridades');
                Route::get('api/secciones/{seccion}/resumen', [MapaController::class, 'resumenSeccion'])->name('secciones.resumen');
                // Detalle de sección y su lista de electores: PII de gestión,
                // igual que /capturados, así que ambas van con 2FA. La página
                // se incluye a propósito junto al endpoint: gatear solo el
                // endpoint la dejaría cargando con la tarjeta de electores
                // vacía y sin explicación.
                Route::middleware('2fa')->group(function () {
                    Route::get('secciones/{seccion}', [MapaController::class, 'detalle'])->name('secciones.detalle');
                    Route::get('api/secciones/{seccion}/electores', [ElectorController::class, 'indexPorSeccion'])->name('secciones.electores');
                });

                Route::get('metas', [MapaController::class, 'metas'])->name('metas');
                Route::put('api/secciones/{seccion}/meta', [MapaController::class, 'definirMeta'])->name('secciones.meta');

                Route::get('brigadistas', [BrigadistaController::class, 'index'])->name('brigadistas');
                Route::post('brigadistas', [BrigadistaController::class, 'store'])->name('brigadistas.store');
                Route::put('api/brigadistas/{membership}/activo', [BrigadistaController::class, 'activo'])->name('brigadistas.activo');
                Route::put('api/brigadistas/{membership}/zonas', [BrigadistaController::class, 'zonas'])->name('brigadistas.zonas');
                Route::get('api/brigadistas/{membership}/ratios', [BrigadistaController::class, 'ratios'])->name('brigadistas.ratios');

                // Cancelación ARCO (baja lógica) y export: acciones de gestión.
                Route::delete('api/electores/{elector}', [ElectorController::class, 'destroy'])->name('electores.destroy');
                // Export: el acceso masivo a PII más sensible de la app. Además
                // de 2FA exige reconfirmar contraseña y lleva throttle propio
                // (3/hora). El prefijo del throttle es obligatorio: sin él la
                // clave se firma solo por user id y compartiría contador con el
                // throttle:120,1 del grupo (ver A6).
                Route::get('api/export/electores.csv', [ExportController::class, 'electores'])
                    ->middleware(['2fa', RequirePassword::class, 'throttle:3,60,export-electores'])
                    ->name('export.electores');

                // Bandeja de solicitudes ARCO: listar y atender (LFPDPPP, ADR-004).
                Route::get('solicitudes-arco', [SolicitudArcoController::class, 'index'])->middleware('2fa')->name('solicitudes-arco.index');
                Route::put('api/solicitudes-arco/{solicitud}/atendido', [SolicitudArcoController::class, 'atendido'])->middleware('2fa')->name('solicitudes-arco.atendido');
                // Derecho de Acceso: volcado sin enmascarar de todo lo que la
                // campaña guarda sobre una persona, para entregarlo al titular.
                // Mismo criterio que el export: gestión + 2FA + traza.
                Route::get('api/electores/{elector}/reporte-acceso', [SolicitudArcoController::class, 'reporteAcceso'])
                    ->middleware('2fa')
                    ->name('electores.reporte-acceso');

                // Lista global de capturados (todos los electores del municipio),
                // paginada y con filtros por nombre, tipo de captura y sección.
                //
                // Exige 2FA: para gestión esta lista trae nombre y teléfono en
                // claro de todo el padrón. Sin ella, paginar de 25 en 25 obtenía
                // lo mismo que el export —que sí pedía 2FA y reconfirmación de
                // contraseña— sin ninguno de sus controles.
                Route::middleware('2fa')->group(function () {
                    Route::get('capturados', [ElectorController::class, 'index'])->name('capturados');
                    Route::get('api/capturados', [ElectorController::class, 'data'])->name('capturados.data');
                });
            });

            // Captura de electores (brigadista/coordinador/admin; el 403 fino lo aplica el FormRequest/acción).
            Route::get('captura', [CapturaController::class, 'index'])->name('captura');

            Route::get('api/electores/{elector}', [ElectorController::class, 'show'])->name('electores.show');
            Route::put('api/electores/{elector}', [ElectorController::class, 'update'])->name('electores.update');
            Route::delete('api/electores/{elector}/verificacion', [ElectorController::class, 'quitarVerificacion'])->name('electores.verificacion.destroy');
            Route::get('electores/{elector}', [ElectorController::class, 'page'])->name('electores.page');

            // Interacciones (timeline) + agenda de seguimientos.
            Route::get('api/electores/{elector}/interacciones', [InteraccionController::class, 'indexPorElector'])->name('interacciones.index');
            Route::post('api/electores/{elector}/interacciones', [InteraccionController::class, 'store'])->name('interacciones.store');
            Route::put('api/interacciones/{interaccion}/atendido', [InteraccionController::class, 'atendido'])->name('interacciones.atendido');
            Route::get('agenda', [InteraccionController::class, 'agenda'])->name('agenda');
            Route::get('api/agenda', [InteraccionController::class, 'agendaData'])->name('agenda.data');

            // Eventos (cualquier miembro de campo) + solicitudes ARCO.
            Route::get('eventos', [EventoController::class, 'index'])->name('eventos');
            Route::get('api/eventos', [EventoController::class, 'data'])->name('eventos.data');
            Route::post('eventos', [EventoController::class, 'store'])->name('eventos.store');
            Route::get('api/eventos/{evento}/asistentes', [EventoController::class, 'asistentes'])->name('eventos.asistentes');
            Route::post('api/solicitudes-arco', [SolicitudArcoController::class, 'store'])->name('solicitudes-arco.store');
        });
    });
});

require __DIR__.'/settings.php';
