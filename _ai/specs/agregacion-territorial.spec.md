# Spec — Agregación estadística territorial (municipio/estado)

- **Feature ID**: F5 (nueva iteración post F1-F4: "Análisis territorial multinivel")
- **Estado**: Implementado
- **Depende de**: Sprint 1 (cartografía `entidades`/`municipios`/`secciones`), Sprint 3 (`cobertura_seccion`, `MapaController::cobertura()`), F1-F3 (`Tenant::configuracion()`, `App\Support\Bloques`, `CalcularCompetitividadSeccion`, `DesglosarVotosSeccion`)

## Objetivo
Poder ver la estadística electoral 2024 y la cobertura de campaña agregadas a nivel **municipio** y **estado** (no solo por sección), reusando exactamente los mismos cálculos de competitividad/desglose ya validados por sección.

## Por qué
Hoy el mapa y `/prioridades` solo responden "¿cómo va esta sección?". El cliente necesita también "¿cómo va mi municipio contra el resto del estado?" para presentar avance a nivel macro y, más adelante, dar soporte a zonas fuera de la jerarquía INE (F6/F7) y a las encuestas por sección/zona (F8-F10), que necesitarán el mismo tipo de agregación.

## Alcance

### Incluye
- **Decisión de diseño central**: una sola Action agrega estadísticas dado un conjunto de `seccion_id`; el "nivel" (municipio/entidad, y más adelante zona en F7) es solo un resolver de esa lista. No se construyen dos mecanismos de agregación distintos.
- **`App\Actions\Estadisticas\AgregarEstadisticaTerritorial::handle(Collection $seccionIds): array`**:
  - Consulta `EstadisticaSeccion::whereIn('seccion_id', $seccionIds)->get()`.
  - Suma `total_votos`, `lista_nominal_2024`, y `votos_partidos` (suma por clave de opción de boleta, uniendo los arrays de todas las secciones).
  - `participacion_pct` agregada = `sum(total_votos) / sum(lista_nominal_2024) * 100` (recalculada desde los totales, **no** promedio de los `participacion_pct` por sección — evita sesgar hacia secciones chicas).
  - `indice_oportunidad` agregado = **promedio ponderado por `lista_nominal_2024`** de cada sección (criterio de aceptación explícito abajo — un promedio simple sesga igual que arriba).
  - `grupos_edad` agregado: por cada bucket de edad, suma `ln`/`votos`/`potencial` de todas las secciones y recalcula `participacion`/`abstencion` de ese bucket desde los totales sumados (mismo criterio que `participacion_pct`).
  - Con el `votos_partidos` ya sumado, llama a `App\Actions\Estadisticas\CalcularCompetitividadSeccion::handle()` y `App\Actions\Estadisticas\DesglosarVotosSeccion::handle()` **sin cambios** en esas clases — mismas firmas que usa `MapaController` hoy (`categorias`, `modoCalculo`, `coaliciones`, `partidoSiglas`, `totalVotosSeccion` = el `total_votos` ya sumado).
  - Devuelve `{ total_votos, lista_nominal_2024, participacion_pct, indice_oportunidad, grupos_edad, competitividad, desglose, secciones_incluidas }` (`secciones_incluidas` = cuántas de las `$seccionIds` tenían `EstadisticaSeccion`, para que el front pueda avisar si el dato es parcial).
  - Si `$seccionIds` está vacío o ninguna tiene estadística, devuelve `null` en todos los campos calculados salvo `secciones_incluidas = 0`.
- **`App\Actions\Cobertura\AgregarCoberturaTerritorial::handle(Collection $seccionIds): array`** (requiere `TenantContext` activo, igual que `RecalcularCoberturaSeccion`):
  - Consulta `CoberturaSeccion::whereIn('seccion_id', $seccionIds)->get()` (ya tenant-scoped por `BelongsToTenant`, sin filtrar `tenant_id` a mano).
  - Suma `capturados`, `meta`, `verificados`.
  - Recalcula `cobertura = capturados/meta` (si `meta > 0`, si no `0`) y `movilizacion_verificada = verificados/lista_nominal` — **nunca promediar los ratios ya calculados por sección** (criterio de aceptación explícito).
  - `penetracion = capturados / sum(Seccion.lista_nominal)` de las mismas `$seccionIds` (ojo: es `secciones.lista_nominal`, el conteo INE, **no** `estadisticas_seccion.lista_nominal_2024` que usa la Action anterior — son columnas distintas con la misma semántica pero de fuentes distintas, no mezclarlas).
- **Resolvers de nivel** (métodos privados en el controlador, sin Action propia — son un `pluck` simple):
  - `municipio` → `Seccion::where('municipio_id', $id)->pluck('id')`.
  - `entidad` → `Seccion::whereIn('municipio_id', Municipio::where('entidad_id', $id)->pluck('id'))->pluck('id')`.
  - Sin `id` explícito en el request, usan el municipio/entidad del tenant activo (`TenantContext::get()->municipio` / `->municipio->entidad`).
- **`App\Http\Controllers\TerritorioController`** (nuevo; no crece más `MapaController`, que ya es grande):
  - `resumen(Request $request)` — `GET /api/territorio/resumen?nivel=municipio|entidad&id=` — resuelve `seccionIds` (aplicando el mismo filtro de brigadista que `MapaController::cobertura()`: si el viewer `esBrigadista()`, solo sus secciones asignadas), llama a ambas Actions, y agrega `cobertura_cartografia: { municipios_cargados, municipios_totales }` cuando `nivel=entidad` (`municipios_totales` = `Municipio::where('entidad_id', $id)->count()`; `municipios_cargados` = los que tienen al menos una `Seccion`) para que el front no interprete un agregado parcial como completo.
  - `geojson(Request $request)` — `GET /api/territorio/geojson?nivel=municipio|entidad` — reusa el patrón exacto de `MapaController::cobertura()` (`ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0001))`) pero sobre `municipios.geom` (nivel municipio: un solo Feature) o `entidades.geom` → **un Feature por cada municipio de la entidad** (nivel entidad: la geometría visual sigue siendo por municipio, no se funde en un solo polígono de estado, para que el mapa pueda seguir mostrando el borde de cada municipio incluso si solo uno tiene datos).
  - Rutas dentro del grupo `tenant` existente, mismo criterio de acceso que `/api/cobertura.geojson` (lectura abierta a cualquier rol autenticado del tenant).
- **Frontend (`resources/js/pages/Mapa.vue`)**: selector "Nivel: Sección | Municipio | Estado", **ortogonal** a los modos de color existentes (cobertura/competitividad/etc. siguen aplicando, solo cambia la granularidad). Al elegir Municipio o Estado:
  - El mapa consume `territorio.geojson` en vez de `mapa.cobertura`; el click en un polígono ya no abre la ficha de sección individual (`Seccion.vue`), sino un panel con el resumen agregado de `territorio.resumen` (mismo panel lateral reusado, con los campos disponibles; los que no aplican a nivel agregado —p.ej. `nivel_oportunidad`, `grupo_dominante`, ficha de brigadistas asignados— simplemente no se muestran).
  - Aviso visible cuando `municipios_cargados < municipios_totales` (ej. "Datos completos de 1 de 20 municipios de Sinaloa; el resto no tiene cartografía/estadística cargada").

### No incluye
- Nivel "país": no hay tabla `Pais` ni caso de uso con un solo tenant en México (YAGNI). Se abre en spec futura si el cliente confirma expansión.
- Cobertura operativa multi-municipio: `Tenant.municipio_id` sigue siendo una FK singular; la cobertura fuera del municipio propio del tenant es siempre 0 y no se pretende mostrar como "cobertura de campaña a nivel estado" (ver nota de negocio abajo).
- Recalcular/mostrar `nivel_oportunidad`, `grupo_dominante`, `grupo_mayor_abstencion`, `potencial_movilizacion`, `tipo_composicion_edad`, `recomendacion` a nivel agregado: son etiquetas cualitativas que vienen ya calculadas por sección desde el CSV externo (`ImportarEstadisticasCommand`), no se derivan de una suma. El agregado solo expone `indice_oportunidad` (numérico, sí agregable) y `grupos_edad` (conteos, sí agregables).
- `Prioridades.vue`/ranking a nivel municipio/estado (el ranking sigue siendo por sección; agregarlo a otros niveles no tiene el mismo sentido de "elige dónde tocar puertas hoy").
- Cache/tabla materializada de agregados (`estadisticas_seccion`/`cobertura_seccion` son chicas, cientos de filas por municipio — agregar on-the-fly es instantáneo; se revisita si se cargan varios estados completos).

## Nota de negocio (ya confirmada, no bloquea)
Cada tenant opera un solo municipio (`tenants.municipio_id`). El agregado a nivel estado es **contexto electoral 2024** (dato público, comparar "mi municipio vs. el resto del estado" en la elección pasada), NO una cobertura operativa multi-municipio. Ya validado, procede con esta interpretación.

## Criterios de aceptación (tests)
1. Dado 3 secciones con `total_votos` 100/150/80 y `lista_nominal_2024` 300/400/250, cuando se agrega, entonces `participacion_pct` = `330/950*100` (no el promedio simple de los 3 `participacion_pct` individuales).
2. Dado 2 secciones con `indice_oportunidad` 80/20 y `lista_nominal_2024` 900/100, cuando se agrega, entonces el índice ponderado es `(80*900 + 20*100)/1000 = 74`, no `50` (promedio simple).
3. Dado `votos_partidos` de 2 secciones con las mismas claves de opción de boleta, cuando se agregan, entonces cada clave suma correctamente y el resultado alimentado a `CalcularCompetitividadSeccion`/`DesglosarVotosSeccion` da el mismo resultado que si esas secciones fueran una sola sección con los votos ya sumados (test de equivalencia contra las Actions existentes, sin duplicar su lógica).
4. Dado un conjunto de secciones donde ninguna tiene `EstadisticaSeccion`, `AgregarEstadisticaTerritorial` devuelve `secciones_incluidas = 0` y los demás campos en `null`, sin lanzar excepción.
5. Dado `cobertura_seccion` con `capturados`/`meta` 10/20 y 5/10 en dos secciones, `AgregarCoberturaTerritorial` devuelve `cobertura = 15/30 = 0.5` (recalculada desde los totales), no el promedio de `0.5` y `0.5` que coincide aquí mismo pero fallaría con metas distintas — usar un tercer caso con metas desiguales para probar que NO es un promedio de ratios.
6. Dado `GET /api/territorio/resumen?nivel=municipio`, sin `id`, resuelve al municipio del tenant activo y sus totales coinciden con la suma manual de sus secciones (feature test con datos sembrados conocidos).
7. Dado `GET /api/territorio/resumen?nivel=entidad`, con un estado donde solo 1 de 3 municipios tiene secciones cargadas, `cobertura_cartografia` reporta `{municipios_cargados: 1, municipios_totales: 3}`.
8. Dado un viewer con rol `brigadista` y zonas asignadas, `GET /api/territorio/resumen?nivel=municipio` solo agrega sus secciones asignadas (mismo criterio que `MapaController::cobertura()`); un viewer coordinador/admin agrega todas.
9. Dado `GET /api/territorio/geojson?nivel=entidad`, la `FeatureCollection` trae un `Feature` por cada municipio de la entidad (no un polígono fundido de estado), con geometría simplificada igual que `cobertura()`.
10. Dos tenants con partidos distintos configurados (F2) sobre el mismo municipio: `GET /api/territorio/resumen?nivel=municipio` devuelve `competitividad.estatus_slug` coherente con la perspectiva de cada tenant (sin fuga entre tenants, mismo criterio que el criterio 9 de `competitividad-seccion.spec.md`).

## Notas de implementación
- `AgregarEstadisticaTerritorial` y `AgregarCoberturaTerritorial` son Actions puras (reciben `Collection<int>` de IDs, no dependen de request/controller) para poder reusarlas directamente en F7 (`ZonaController::resumen`) sin ningún cambio — aunque F7 no se implementa en este worktree, diseña la firma pensando en esa reutilización.
- `AgregarCoberturaTerritorial` depende de `TenantContext` (igual que `RecalcularCoberturaSeccion`).
- El `votos_partidos` sumado se pasa a `CalcularCompetitividadSeccion::handle()`/`DesglosarVotosSeccion::handle()` exactamente como hoy los llama `MapaController::calcularCompetitividad()`/`calcularDesglose()` — LEE ESOS DOS MÉTODOS PRIVADOS en `app/Http/Controllers/MapaController.php` antes de escribir código, para copiar la firma exacta (parámetros: `partidoSiglas`, `coaliciones`, `categorias`, `modoCalculo`, `totalVotosSeccion`).
- Lee también `app/Models/EstadisticaSeccion.php`, `app/Models/CoberturaSeccion.php` (ojo: `CoberturaSeccion` NO soporta `save()`/`update()` de instancia — no es relevante aquí porque solo lees, no escribes, pero no la instancies para guardar), `app/Actions/Cobertura/RecalcularCoberturaSeccion.php`, `app/Support/Bloques.php`, `app/Actions/Estadisticas/CalcularCompetitividadSeccion.php`, `app/Actions/Estadisticas/DesglosarVotosSeccion.php`, `app/Models/Seccion.php`, `app/Models/Municipio.php` para firmas y relaciones exactas.
- `TerritorioController` resuelve `configuracionCompetitividad()` una sola vez por request (mismo patrón que `MapaController`) — puedes duplicar ese método privado en el nuevo controlador por ahora (no hay tiempo de refactor a compartido en este spec).
- `vendor/bin/pint --dirty --format agent` al cierre.

## Desviaciones respecto al texto original (implementación)

- **Firma de `AgregarEstadisticaTerritorial::handle()`**: el texto del spec la
  describe como `handle(Collection $seccionIds): array`, pero la propia spec
  exige que internamente llame a `CalcularCompetitividadSeccion::handle()` y
  `DesglosarVotosSeccion::handle()`, que requieren `partidoSiglas`,
  `coaliciones`, `categorias`, `modoCalculo` y `partidos` — configuración que
  no puede resolver una Action pura sin request/tenant. La firma final es
  `handle(Collection $seccionIds, ?string $partidoSiglas, Collection $coaliciones, array $categorias, string $modoCalculo, Collection $partidos): array`.
  Sigue siendo pura (recibe todo por parámetro, sin tocar `TenantContext` ni
  el request), cumpliendo el objetivo real de la nota de reutilización para F7.
- **Acceso a `/api/territorio/*`**: el spec dice "mismo criterio de acceso
  que `/api/cobertura.geojson` (lectura abierta a cualquier rol autenticado
  del tenant)", pero en el código actual `/api/cobertura.geojson` está
  restringido a `rol:coordinador,admin` (el brigadista recibe 403, ver
  `tests/Feature/MapaCobertura/RestriccionMapaTest.php`) — la nota del spec
  describe un estado más antiguo del código (Sprint 3) que ya no es el actual.
  El criterio de aceptación 8 exige explícitamente que un brigadista SÍ pueda
  golpear el endpoint y ver solo sus secciones asignadas, así que las rutas de
  `TerritorioController` se colocaron en el grupo `rol:brigadista,coordinador,admin`
  (visible a brigadista/coordinador/admin, no a enlace/anfitrión, que no se
  mencionan en ningún criterio de aceptación) en vez de replicar la
  restricción real de `/api/cobertura.geojson`.
- **Frontend**: al no haber `node_modules` en este worktree, no se pudo
  correr `npm run build`/`eslint`/`prettier` sobre `Mapa.vue`. Los cambios se
  revisaron manualmente (balance de `<section>`/`<template>`, tipos TS
  explícitos) pero no se verificaron con el compilador ni en navegador. Sí se
  generaron los stubs de rutas con `php artisan wayfinder:generate` (comando
  Artisan puro, sin necesidad de `npm`).
