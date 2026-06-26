# Spec — Mapa de cobertura + metas por sección

- **Feature ID**: M3 / M7
- **Estado**: Borrador
- **Depende de**: ADR-001 (multi-tenancy), ADR-002 (PostGIS), ADR-003 (captura y cobertura), `_ai/docs/data-model.md` (`metas_seccion`, `cobertura_seccion`), `_ai/docs/api-contract.md` (endpoints de mapa/metas)

## Objetivo
Mostrar el mapa de cobertura por sección (rojo→verde) y permitir definir metas por sección (manual o % de lista nominal), leyendo siempre de la tabla derivada `cobertura_seccion`, nunca agregando en vivo sobre `electores`.

## Por qué
Es el dashboard central del coordinador (OKR O2) y el "Pendiente inmediato" tras cerrar Sprint 2 (auth+tenancy). Sin esto no hay visibilidad territorial, que es el diferenciador del producto. Aún no hay capturas reales de `electores` (Sprint 4), así que esta fase entrega el mapa con cobertura en cero/metas configuradas, listo para poblarse cuando llegue la captura.

## Alcance

### Incluye
- Migraciones: `metas_seccion` (tenant_id, seccion_id, meta_capturas, fuente_meta, pct_lista_nominal; único `(tenant_id, seccion_id)`) y `cobertura_seccion` (tenant_id, seccion_id, capturados, meta, cobertura, penetracion, actualizado_en; PK `(tenant_id, seccion_id)`).
- Modelos Eloquent `MetaSeccion` y `CoberturaSeccion`, ambos con `BelongsToTenant`.
- Acción `App\Actions\Metas\DefinirMetaSeccion`: resuelve `meta_capturas` según `fuente_meta` (`manual` fija el valor; `lista_nominal_pct` calcula `round(secciones.lista_nominal * pct/100)`), persiste en `metas_seccion`, y refresca la fila correspondiente de `cobertura_seccion` (upsert: `meta` = nuevo valor, recalcula `cobertura`/`penetracion` con los `capturados` actuales).
- Comando artisan `territori:recalcular-cobertura {tenant}`: recálculo completo idempotente de `cobertura_seccion` para todas las secciones del tenant (red de seguridad descrita en ADR-003). Sin `electores` reales aún, `capturados` parte de 0.
- Endpoint `GET /api/cobertura.geojson?modo=cobertura|penetracion|tipo` → FeatureCollection (join `cobertura_seccion` ⨝ `secciones`, geom simplificada), scoped por tenant.
- Endpoint `GET /api/secciones/{seccion}/resumen` → `{ numero, capturados, meta, cobertura, penetracion, brigadistas_activos:[], ultimo_registro }`.
- Endpoint `PUT /api/secciones/{seccion}/meta` → invoca `DefinirMetaSeccion`.
- Página Inertia `GET /mapa`: mapa Leaflet coloreado por cobertura, click en sección → panel resumen.
- Página Inertia `GET /metas`: listado de secciones del municipio del tenant con su meta actual, formulario para fijar `manual` o `lista_nominal_pct`.

### No incluye
- Evento de dominio `ElectorCapturado` y su job incremental (depende de que exista captura de `electores`, Sprint 4). El recálculo en esta fase es solo vía comando manual/seeder.
- Captura de electores (Sprint 4).
- Asignación de zonas a brigadistas, `brigadistas_activos` puede devolver `[]` hasta Sprint 5 (no hay memberships con `rol=brigadista` asignadas a sección todavía — se deja el campo en el contrato pero sin lógica de asignación real).
- Proyección/secciones desérticas (Should, Sprint 6).

## Criterios de aceptación (tests)
1. Dado un tenant con secciones cargadas (cartografía Sprint 1) sin meta definida, cuando se consulta `GET /api/cobertura.geojson`, entonces cada feature trae `meta=0` (o null) y `cobertura=0`, sin error.
2. Dado un tenant, cuando se define una meta manual de 100 para una sección, entonces `metas_seccion.meta_capturas=100` y `cobertura_seccion.meta=100` se actualizan, y `cobertura_seccion.cobertura = capturados/100`.
3. Dado un tenant y una sección con `lista_nominal=2000`, cuando se define meta `fuente_meta=lista_nominal_pct, pct=30`, entonces `meta_capturas = round(2000*0.30) = 600`.
4. Dado dos tenants con secciones del mismo municipio, cuando cada uno define metas distintas para la "misma" sección física, entonces `metas_seccion` y `cobertura_seccion` no se mezclan entre tenants (test de aislamiento, igual patrón que `BelongsToTenantTest`).
5. Dado que se ejecuta `territori:recalcular-cobertura {tenant}` dos veces seguidas sin cambios de datos, entonces el resultado es idéntico (idempotencia).
6. Dado un usuario con rol `brigadista`, cuando intenta `PUT /api/secciones/{seccion}/meta`, entonces se rechaza (solo `coordinador`/`admin`).
7. Dado `GET /api/secciones/{seccion}/resumen` para una sección sin fila en `cobertura_seccion` aún, entonces responde con valores en cero en vez de error (la fila se crea con defaults al cargar cartografía o se trata como ausente=cero en el query).

## Notas de implementación
- `cobertura_seccion` se llena al primer `territori:recalcular-cobertura {tenant}` (una fila por sección del municipio del tenant, en cero), para que el PK `(tenant_id, seccion_id)` sea predecible. El query del GeoJSON trata la ausencia de fila como cero (LEFT JOIN), de modo que el mapa funcione aunque no se haya corrido el recálculo.
- **Geom simplificada (verificado: aún no existe).** `Seccion` solo castea `geom` crudo (MultiPolygon 4326); no hay columna ni query simplificado. Decisión Sprint 3: simplificar en el query con `ST_SimplifyPreserveTopology(geom, tolerancia)` y devolver GeoJSON con `ST_AsGeoJSON`, sin columna materializada. Si el payload pesa en producción, materializar `geom_simplified` en una iteración posterior (no en este sprint). Mantener SRID 4326 de punta a punta (ADR-002).
- **Resolución de tenant (verificado, corrige nota anterior): ya cubierto.** `ResolveTenant` está en el stack `web` global (`bootstrap/app.php`, `$middleware->web(append: [...])`), corre en cada request, no falta agregar grupo. Solo hay que envolver las rutas de escritura con `rol:coordinador,admin`.
- `EnsureRol` (middleware `rol:coordinador,admin`) protege escritura de metas (`PUT .../meta`, vista `/metas`); lectura del mapa (`/mapa`, `/api/cobertura.geojson`, `/api/secciones/{seccion}/resumen`) abierta a cualquier rol del tenant.
- Reusar la UX del mapa de `territori_demo.html` como referencia visual de la ficha de sección, no como código a portar.
