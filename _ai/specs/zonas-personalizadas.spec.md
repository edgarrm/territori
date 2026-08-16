# Spec — Zonas personalizadas (modelo + CRUD)

- **Feature ID**: F6 (iteración "Análisis territorial multinivel + encuestas multi-canal")
- **Estado**: Borrador
- **Depende de**: ADR-002 (PostGIS, SRID 4326), Sprint 1 (`_ai/specs/import-cartografia.spec.md`, patrón `CartografiaSeeder` de dos etapas), F5 `agregacion-territorial.spec.md` (la Action genérica de agregación que F7 va a reusar sobre las zonas definidas aquí)

## Objetivo
Permitir que un tenant defina sus propias zonas territoriales (polígonos) a cualquier nivel — ciudad, municipio, estado, país, continente, mundial, o completamente custom — independientes de la jerarquía electoral INE, ya sea importándolas masivamente desde límites administrativos públicos o dibujándolas/subiéndolas a mano.

## Por qué
Es el mecanismo genérico de "seccionamiento" pedido: replicar el patrón municipio→sección INE en cualquier escalón geográfico y para cualquier país, para poder segmentar zonas de interés y (en F7-F10) correrles el mismo tipo de análisis y encuestas que hoy solo existe por sección.

## Alcance

### Incluye
- Migración `zonas` (🏢 tenant-scoped): `tenant_id`, `nombre`, `clave` string nullable único por `(tenant_id, clave)`, `descripcion` nullable, `tipo` string libre (`ciudad`|`municipio`|`estado`|`pais`|`continente`|`mundial`|lo que el cliente invente — sin `enum`/`CHECK` en BD), `zona_padre_id` FK a `zonas.id` nullable `nullOnDelete` (auto-referencia para anidar cualquier jerarquía), `geom` `GEOMETRY(MultiPolygon,4326)` nullable + índice GiST (nullable porque un nivel intermedio como "mundial" o "continente" puede no tener polígono propio, solo agrupar zonas hijas), `fuente` (`dibujada`|`importada`), `activa` boolean default `true`, `creado_por_membership_id` FK a `memberships` nullable `nullOnDelete`, timestamps.
- Modelo `App\Models\Zona`: `use BelongsToTenant, HasSpatial, HasFactory` (mismo patrón que `Seccion`/`Municipio`), cast `geom => MultiPolygon::class`, relaciones `padre(): BelongsTo` y `hijas(): HasMany`.
- `App\Actions\Zonas\CrearZona`/`ActualizarZona`: reciben GeoJSON (RFC 7946), validan SRID 4326 y `ST_IsValid`; **solo GeoJSON en v1**, sin conversión de Shapefile/KML en runtime.
- `App\Http\Controllers\ZonaController`: `index` (Inertia, mapa + tabla), `store`, `update`, `destroy`, `geojson` (`GET /api/zonas.geojson`, reusa literalmente el patrón `ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0001))` de `MapaController::cobertura()`). Escritura con `rol:coordinador,admin` (mismo patrón que `metas`/`brigadistas`); lectura abierta a cualquier rol del tenant.
- Comando `territori:importar-zonas {archivo} --nivel= --tenant= [--padre-clave=]`: importa un GeoJSON `FeatureCollection` (cada `Feature.properties` trae al menos `nombre`, opcionalmente `clave`) haciendo upsert idempotente por `(tenant_id, clave)` — mismo espíritu que `CartografiaSeeder`. `--nivel` fija `tipo`; `--padre-clave` (opcional) resuelve `zona_padre_id` buscando una zona existente del tenant con esa `clave`, para importar por lotes jerárquicos (países primero, luego sus estados, etc.).
- Frontend: `resources/js/pages/Zonas.vue` (nueva) con **Leaflet.draw** (dependencia nueva, versión exacta pinneada, mismo criterio que Leaflet en Sprint 3) para dibujar a mano, subir un `.geojson` con preview antes de guardar, y tabla de zonas (editar/activar-desactivar/eliminar). Overlay opcional "Zonas" (checkbox) en `resources/js/pages/Mapa.vue`, superpuesto a las secciones sin reemplazarlas.

### No incluye
- Estadísticas/cobertura por zona (F7 — aquí solo se modela y gestiona el polígono).
- Encuestas ligadas a zona (F8-F10).
- Conversión de Shapefile/KML/otros formatos en runtime (solo GeoJSON; la preparación con `ogr2ogr`/`geopandas` es local, offline, igual que la cartografía INE).
- Restricción geográfica de una `Zona` al municipio del tenant — a diferencia de `Seccion`, se permite deliberadamente que una zona quede fuera (ver decisión abierta abajo).
- Descarga/automatización de la fuente de límites administrativos (geoBoundaries, Natural Earth) — el comando de importación asume que el operador ya preparó el GeoJSON localmente, igual que `CartografiaSeeder`.

## Decisión abierta a confirmar antes de codear
Fuente de datos para el import masivo por nivel: [geoBoundaries.org](https://www.geoboundaries.org) (licencia abierta, cobertura mundial, ADM0=país hasta ADM2/ADM3≈municipio/ciudad) es la recomendación por defecto; validar con el cliente si prefiere Natural Earth (mejor para país/continente) u otra fuente ya licenciada. Para México, la cartografía INE (`entidades`/`municipios`/`secciones`) ya existe — `Zona` es solo para lo que esa jerarquía no cubre (otros países, o subdivisiones propias dentro de México).

## Criterios de aceptación (tests)
1. Dado un coordinador con un GeoJSON válido de un polígono simple, cuando crea una zona `nombre="Colonia X"` `tipo="colonia"` con ese `geom`, entonces se guarda con `fuente=dibujada` y `activa=true`.
2. Dado un GeoJSON con geometría inválida (`ST_IsValid` falso) o SRID incorrecto, cuando se intenta crear la zona, la validación falla (422) sin persistir nada.
3. Dado dos zonas del mismo tenant con la misma `clave`, al intentar crear la segunda falla por la unicidad `(tenant_id, clave)`.
4. Dado un GeoJSON `FeatureCollection` con 5 features (ciudades) con `nombre`+`clave` en `properties`, al correr `territori:importar-zonas archivo.geojson --nivel=ciudad --tenant=1` se crean 5 `Zona` con `tipo=ciudad`; correrlo de nuevo sin cambios no duplica (idempotente, mismo resultado).
5. Dado un import posterior con `--padre-clave=`, al importar estados hijos de un país ya existente, `zona_padre_id` se resuelve correctamente por `clave`.
6. Dado un tenant cuyo municipio es Mazatlán, al crear una `Zona` con `geom` fuera de Sinaloa (otro país), se permite sin error — la zona no está restringida al municipio del tenant.
7. Dado `GET /api/zonas.geojson`, la respuesta trae una `FeatureCollection` con geometría simplificada, mismo formato que `cobertura()` de secciones.
8. Dado un viewer con rol `brigadista` (no coordinador/admin), al intentar `POST /zonas` recibe 403.
9. Dado una zona `tipo=mundial` sin `geom`, al crearla se permite `geom=null` sin error de validación.

## Notas de implementación
- Reusar el patrón `HasSpatial` + cast `MultiPolygon` ya usado en `Seccion`/`Municipio`/`Entidad` (`app/Models/Seccion.php`).
- El comando de importación reusa el espíritu idempotente de `CartografiaSeeder` (upsert, no duplica en corridas repetidas).
- `resources/js/pages/Zonas.vue` reusa la estructura de página de `Metas.vue`/`Brigadistas.vue` (tabla + panel de edición) donde aplique.
- `vendor/bin/pint --dirty --format agent` al cierre.
