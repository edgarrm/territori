# Datos semilla de cartografia — Sinaloa (25)

GeoJSON en EPSG:4326 (GeoJSON usa CRS84 = WGS84 en orden lng,lat; equivalente a 4326).
Generados en la etapa de preparacion local a partir del Marco Geografico Seccional del INE.

## Archivos
- `entidad.geojson` — 1 feature (Sinaloa, clave 25). props: entidad, nombre.
- `municipios.geojson` — 20 municipios de Sinaloa. props: entidad, municipio, nombre.
- `secciones.geojson` — 530 secciones de Mazatlan (municipio 12) como dato semilla inicial.
  props: entidad, municipio, seccion, tipo, distrito_f, distrito_l. geometry: Polygon.

## Notas para el seeder (CartografiaSeeder)
- Las geometrias son Polygon -> promover a MultiPolygon con ST_Multi al insertar.
- El GeoJSON declara CRS84; al insertar usar ST_SetSRID(...,4326) (CRS84 y 4326 son el mismo datum, distinto orden de ejes; ST_GeomFromGeoJSON ya entrega lng/lat -> 4326 correcto).
- Mapear: seccion->numero, distrito_f->distrito_federal, distrito_l->distrito_local.

## Como regenerar / agregar otro estado o municipio
Ver `_ai/specs/import-cartografia.plan.md`, Etapa 1 (preparacion local con ogr2ogr o geopandas).
Para agregar mas municipios de Sinaloa al seed, regenerar secciones.geojson sin el filtro municipio==12.
