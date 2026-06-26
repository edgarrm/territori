# ADR-002 — Datos Geoespaciales con PostGIS

- **Estado**: Aceptado
- **Fecha**: 2026-06-26
- **Contexto SDD**: Fase 04 — Architecture

## Contexto

El sistema gira alrededor de polígonos de secciones electorales y puntos (domicilios, eventos, GPS de captura). Necesitamos:
- Almacenar los polígonos de las secciones del INE.
- Responder "¿en qué sección cae este punto GPS?" al capturar un elector (detección automática de sección, feature S5).
- Servir GeoJSON al frontend Leaflet de forma eficiente.
- Calcular centroides, áreas y vecindad de secciones.

## Decisión

Usar la extensión **PostGIS** sobre PostgreSQL.

- Columna `geom` de tipo `GEOMETRY(MultiPolygon, 4326)` en `secciones` y `municipios`. SRID 4326 (WGS84, lat/lng) para compatibilidad directa con Leaflet/GeoJSON.
- Columna `ubicacion` de tipo `GEOMETRY(Point, 4326)` en `electores` y `eventos` (nullable).
- Índices espaciales **GiST** en todas las columnas de geometría.
- Detección de sección por punto: `SELECT id FROM secciones WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(:lng,:lat),4326))`.
- Importación de shapefiles del INE vía un comando artisan (`territori:import-cartografia {estado}`) que usa `ogr2ogr` o un parser con reproyección a 4326.

En Laravel usaremos un paquete de soporte espacial (ej. `matanyadaev/laravel-eloquent-spatial` o equivalente vigente) para castear geometrías a objetos y evitar SQL crudo en los casos comunes.

## Consecuencias

**Positivas**: consultas espaciales nativas y rápidas; un solo motor (Postgres) para datos y geometría; GeoJSON se genera con `ST_AsGeoJSON`.

**Negativas / riesgos**:
- PostGIS debe estar instalado en el entorno (Docker: imagen `postgis/postgis`). Documentar en setup.
- Las geometrías completas del INE son pesadas. Mitigación: servir al frontend una versión **simplificada** (`ST_SimplifyPreserveTopology`, tolerancia ~5 m) y cachear el GeoJSON por municipio. La geometría full se queda en BD para queries precisos.
- El SRID debe ser consistente (4326) en toda la cadena; mezclar SRIDs es fuente típica de bugs.

## Nota de rendimiento

El GeoJSON de cobertura que consume el mapa NO debe recalcularse en cada request. Estrategia: una **vista materializada** o tabla `cobertura_seccion` (tenant_id, seccion_id, capturados, cobertura, penetracion) refrescada por evento de captura o en intervalo corto. El mapa lee de ahí, no agrega sobre `electores` en vivo. (Ver ADR-003 y data-model.)
