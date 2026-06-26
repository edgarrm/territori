# Spec — Carga de Cartografía INE (preparación local + seeder)

- **Feature ID**: M2 (parte) / Sprint 1
- **Estado**: Listo para implementar
- **Depende de**: ADR-001, ADR-002, data-model (entidades/municipios/secciones)
- **Reemplaza**: enfoque previo de "comando artisan que orquesta ogr2ogr en el servidor"

## Objetivo

Poblar los catálogos globales `entidades`, `municipios` y `secciones` con geometrías en SRID 4326, mediante un flujo de **dos etapas**:
1. **Preparación local** (tu Mac, una vez por estado): convierte el shapefile del INE a un archivo intermedio limpio (GeoJSON reproyectado a 4326).
2. **Seeder de Laravel** (PHP puro): ingiere ese archivo intermedio y hace upsert a PostGIS.

**Producción nunca necesita GDAL ni Python.** Solo PHP/Laravel/PostGIS.

## Por qué este enfoque

- El import es una operación **rara** (una vez por estado, al dar de alta una zona nueva), no parte del runtime de la app ni disparada por usuarios. Es administración de datos.
- Mantiene producción simple: solo PHP/Laravel (preferencia del proyecto). La maquinaria geoespacial (GDAL/geopandas) vive solo en la máquina local, en un paso manual desechable.
- Patrón build-time vs run-time: se procesa el dato pesado una vez en local; se sirve el resultado liviano (GeoJSON) vía seeder versionado.
- Sin cartografía no hay mapa ni secciones que asignar: es la primera piedra de todo el sistema.

## Alcance

### Incluye
- **Etapa 1 — preparación local** (script desechable, NO parte del stack de prod): a partir del shapefile del INE de un estado, filtra/reproyecta a 4326 y exporta un GeoJSON por capa (entidad, municipios, secciones) o un GeoJSON combinado de secciones con los atributos necesarios. La herramienta local puede ser `ogr2ogr` o geopandas indistintamente (es efímero; importa el output, no el medio).
- **Etapa 2 — seeder PHP** (`CartografiaSeeder`): lee el/los GeoJSON desde `database/seeders/data/` e inserta con upsert a `entidades`, `municipios`, `secciones`. Reproyección ya hecha (el GeoJSON viene en 4326); el seeder NO reproyecta.
- Mapeo de atributos: `entidad`->clave, `municipio`->clave, `seccion`->numero, `tipo`, `distrito_f`->distrito_federal, `distrito_l`->distrito_local.
- Inserción de geometría: el GeoJSON trae la geometry; el seeder la inserta como `MultiPolygon` 4326 (promover Polygon->MultiPolygon con `ST_Multi`).
- Idempotente: upsert por claves únicas `(clave)`, `(entidad_id,clave)`, `(municipio_id,numero)`.

### No incluye
- GDAL/Python en producción.
- Lista nominal (columna queda NULL; se carga aparte).
- Simplificación de geometría para el front (se hace en consulta de servir el mapa, no aquí).
- UI: es operación de datos por seeder/artisan.

## Formato del archivo intermedio

GeoJSON en EPSG:4326 (lat/lng). Para secciones, cada Feature con properties:
`{ entidad, municipio, seccion, tipo, distrito_f, distrito_l }` y geometry Polygon/MultiPolygon.
Se guarda en `database/seeders/data/{estado}/` (ej. `25-sinaloa/secciones.geojson`). Versionado en git.

> El GeoJSON de Mazatlan ya generado en el prototipo sirve como **primer dato semilla** para pruebas (530 secciones del municipio 12, ya en 4326).

## Criterios de aceptación (tests del seeder)

1. Tras correr `CartografiaSeeder` con el GeoJSON semilla, `entidades` tiene la entidad esperada con geom no nula en SRID 4326.
2. `municipios` queda con sus filas, `(entidad_id, clave)` unico respetado, geom 4326.
3. `secciones` del municipio 12 (Mazatlan) tiene 530 filas con `numero`, `tipo`, distritos y geom 4326.
4. `ST_SRID(geom) = 4326` en una seccion.
5. `ST_Contains` con un punto conocido dentro de una seccion la resuelve.
6. Correr el seeder dos veces deja el mismo conteo (idempotencia por upsert).
7. Una geometry Polygon del GeoJSON queda almacenada como MultiPolygon (uniforme).

## Notas de implementacion

- **Etapa 1 (local)**: documentar el comando exacto en el plan. Reutilizable; produce GeoJSON 4326. No se testea en CI (es manual y local).
- **Etapa 2 (seeder)**: leer GeoJSON con `json_decode`; insertar geometria via SQL con `ST_Multi(ST_GeomFromGeoJSON(:geom))` y `ST_SetSRID(...,4326)` si el GeoJSON no fija CRS. Hacer en transaccion + chunk para no agotar memoria con muchos features.
- Validar al final: conteos por municipio y SRID, con salida por consola.
- El seeder es el camino de produccion; tambien se puede envolver en un comando `territori:cargar-cartografia {estado}` que llame al seeder para ergonomia, pero el nucleo es el seeder PHP.
- Referencia confirmada del prototipo: CRS origen EPSG:32613, Mazatlan = clave 12, 530 secciones, atributos `entidad, distrito_f, distrito_l, municipio, seccion, tipo`.
