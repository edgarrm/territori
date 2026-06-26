# Plan de Implementación — Mapa de Cobertura + Metas por Sección (Sprint 3)

> Acompaña a `specs/mapa-cobertura.spec.md`. Laravel 13 + Vue 3/Inertia + PostgreSQL/PostGIS. Lee de la tabla derivada `cobertura_seccion`, nunca agrega en vivo sobre `electores` (ADR-003). `electores` aún no existe (Sprint 4): el mapa arranca con cobertura en cero y metas configurables.
> Reusa la tenancy de Sprint 2: `BelongsToTenant`, `TenantScope`, `TenantContext`, `ResolveTenant`, `EnsureRol`. No reinventar.

## Orden de construcción (incremental, TDD)

### Bloque A — Esquema
1. Migración `metas_seccion`: `tenant_id`, `seccion_id` (FK→secciones), `meta_capturas` (int), `fuente_meta` (`manual`|`lista_nominal_pct`), `pct_lista_nominal` (numeric(5,2) null); unique `(tenant_id, seccion_id)`; índice `(tenant_id, seccion_id)`.
2. Migración `cobertura_seccion`: `tenant_id`, `seccion_id` (FK), `capturados` (int default 0), `meta` (int default 0), `cobertura` (numeric(6,4) default 0), `penetracion` (numeric(6,4) default 0), `actualizado_en` (timestamptz null); PK compuesta `(tenant_id, seccion_id)`.
3. Modelos `MetaSeccion` y `CoberturaSeccion`, ambos con `BelongsToTenant`. `CoberturaSeccion` con PK compuesta (sin `id`, `incrementing=false`, `primaryKey` no aplica directo → usar `HasCompositePrimaryKey` ad-hoc o queries por `(tenant_id, seccion_id)`). Relación `belongsTo(Seccion)` en ambos.

> **Test de aislamiento aquí (criterio 4), antes de seguir.** Dos tenants con metas distintas sobre la misma sección física no se mezclan. Mismo patrón que `BelongsToTenantTest`.

### Bloque B — Definir metas (lógica de dominio)
4. Acción `App\Actions\Metas\DefinirMetaSeccion`: input `(Seccion $seccion, string $fuenteMeta, ?int $metaCapturas, ?float $pct)`.
   - `manual` → `meta_capturas = $metaCapturas`.
   - `lista_nominal_pct` → `meta_capturas = (int) round($seccion->lista_nominal * $pct / 100)`.
   - Upsert en `metas_seccion` por `(tenant_id, seccion_id)`.
   - Refresca la fila de `cobertura_seccion`: `meta = meta_capturas`, recalcula `cobertura = capturados / meta` (cuidar división por cero → 0), `penetracion` sin cambio aquí.
5. Tests de la acción: criterios 2 (manual) y 3 (pct → round). Edge: `lista_nominal` null + pct → meta 0 o error claro.

### Bloque C — Recálculo completo (red de seguridad)
6. Comando `territori:recalcular-cobertura {tenant}`: para cada sección del municipio del tenant, upsert fila en `cobertura_seccion` con `capturados` (0 por ahora; cuando exista `electores`, COUNT scoped), `meta` desde `metas_seccion` (0 si no hay), recalcula ratios, set `actualizado_en`. Idempotente.
7. Test: criterio 5 (correr dos veces da idéntico resultado). Criterio 1 (sin metas → meta 0, cobertura 0, sin error).

### Bloque D — Endpoints de lectura (GeoJSON + resumen)
8. Controlador `MapaController`:
   - `cobertura()` → `GET /api/cobertura.geojson?modo=cobertura|penetracion|tipo`. Query: `secciones` LEFT JOIN `cobertura_seccion` (scoped al tenant), geom vía `ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, :tol))`. Ausencia de fila → capturados/meta/cobertura = 0. FeatureCollection con properties del contrato.
   - `resumenSeccion(Seccion $seccion)` → `GET /api/secciones/{seccion}/resumen`. Valores en cero si no hay fila (criterio 7). `brigadistas_activos: []` (Sprint 5), `ultimo_registro: null` (Sprint 4).
9. Tests: criterio 1 (geojson sin metas), criterio 7 (resumen sin fila → cero).

### Bloque E — Endpoint de escritura de meta
10. `PUT /api/secciones/{seccion}/meta` → valida body (`fuente_meta` + el campo correspondiente), invoca `DefinirMetaSeccion`. FormRequest con reglas condicionales.
11. Tests: criterio 6 (brigadista → 403 vía `rol:coordinador,admin`); happy path coordinador.

### Bloque F — Rutas
12. `ResolveTenant` ya corre en el stack `web` global (verificado en `bootstrap/app.php`); no hace falta grupo nuevo para tenant. Solo agrupar:
    - Lectura (cualquier rol del tenant, dentro de `auth,verified`): `GET /mapa`, `GET /api/cobertura.geojson`, `GET /api/secciones/{seccion}/resumen`.
    - Escritura (`rol:coordinador,admin`): `GET /metas`, `PUT /api/secciones/{seccion}/meta`.
13. Generar rutas tipadas con Wayfinder para el front.

### Bloque G — Frontend Inertia/Vue
14. Página `resources/js/pages/Mapa.vue`: Leaflet, carga `/api/cobertura.geojson`, colorea por `modo` (rojo→verde escala de `cobertura`), click en sección → fetch `/api/secciones/{seccion}/resumen` → panel lateral. Referencia visual: `territori_demo.html`.
15. Página `resources/js/pages/Metas.vue`: tabla de secciones con meta actual, form para `manual`/`lista_nominal_pct`, `PUT` vía Wayfinder. Estados vacío/loading.
16. Selector de `modo` (cobertura/penetración/tipo) en el mapa.

## Tests (TDD — por bloque)

- **Aislamiento (A)**: criterio 4. Helper `actingInTenant`.
- **Metas (B)**: criterios 2, 3.
- **Recálculo (C)**: criterios 1, 5.
- **Lectura (D)**: criterios 1, 7 (GeoJSON shape, resumen en cero).
- **Escritura/autorización (E/F)**: criterio 6.

## Decisiones y trampas a evitar

- **No** agregar `COUNT(*) GROUP BY seccion` sobre `electores` en el endpoint del mapa. Leer SIEMPRE de `cobertura_seccion` (ADR-003, regla CONTEXT).
- **No** servir geom cruda: simplificar con `ST_SimplifyPreserveTopology` en el query (sin columna materializada en este sprint). SRID 4326 de punta a punta.
- **No** olvidar `ResolveTenant` en el grupo de rutas: sin tenant activo el `TenantScope` devuelve cero, no error — bug silencioso.
- División por cero al calcular `cobertura`/`penetracion`: meta 0 o lista_nominal null → ratio 0.
- `cobertura_seccion` con PK compuesta: Eloquent no lo soporta nativo; usar upserts por `(tenant_id, seccion_id)` o queries explícitas, no `find($id)`.
- `brigadistas_activos` y `ultimo_registro` quedan como `[]`/`null` hasta Sprint 5/4; dejar el campo en el contrato pero sin lógica.

## Definición de Done

- Migraciones aplican en limpio; `metas_seccion` y `cobertura_seccion` con sus PK/índices.
- `DefinirMetaSeccion` resuelve manual y pct; refresca cobertura.
- `territori:recalcular-cobertura` idempotente, llena cobertura en cero sin electores.
- `GET /api/cobertura.geojson` devuelve FeatureCollection válido scoped por tenant, geom simplificada.
- Escritura de metas solo coordinador/admin (brigadista 403).
- Mapa Leaflet coloreado + panel de sección; vista de metas funcional.
- Suite verde: aislamiento, metas (manual/pct), idempotencia, autorización.
