# Plan de Implementacion — Carga de Cartografia INE (Sprint 1)

> Acompana a `specs/import-cartografia.spec.md`. Enfoque de dos etapas:
> (1) preparacion local que genera GeoJSON 4326, (2) seeder PHP que lo ingiere a PostGIS.
> Produccion = solo PHP/Laravel/PostGIS. GDAL/Python solo en tu Mac, paso manual.

## Hallazgos de la inspeccion (datos reales)

- CRS origen: **EPSG:32613** (UTM zona 13N).
- Geometrias tipo **Polygon** (no MultiPolygon) -> promover a MultiPolygon al insertar (`ST_Multi`).
- 0 geometrias invalidas en el archivo de Sinaloa; validar igual por si otros estados traen errores.
- `ENTIDAD.shp`: 1 fila (clave 25, nombre SINALOA). `MUNICIPIO.shp`: 20 filas (entidad, municipio, nombre). `SECCION.shp`: 3855 filas (entidad, municipio, seccion, tipo, distrito_f, distrito_l; sin nombre).
- Mazatlan = `municipio` clave **12**, 530 secciones.

---

## ETAPA 1 — Preparacion local (manual, una vez por estado)

Objetivo: convertir el shapefile del INE a GeoJSON en EPSG:4326, listo para el seeder.
Corre en tu Mac. La herramienta es indiferente (es efimera). Dos caminos equivalentes:

### Opcion ogr2ogr (si tienes GDAL)
```bash
# secciones de un estado -> GeoJSON 4326
ogr2ogr -f GeoJSON -t_srs EPSG:4326 secciones.geojson SECCION.shp
ogr2ogr -f GeoJSON -t_srs EPSG:4326 municipios.geojson MUNICIPIO.shp
ogr2ogr -f GeoJSON -t_srs EPSG:4326 entidad.geojson ENTIDAD.shp
```

### Opcion geopandas (si prefieres Python, mismo resultado)
```python
import geopandas as gpd
for capa in ['ENTIDAD','MUNICIPIO','SECCION']:
    g = gpd.read_file(f'25/{capa}.shp').to_crs(epsg=4326)
    g.to_file(f'{capa.lower()}.geojson', driver='GeoJSON')
```

Salida: colocar los GeoJSON en `database/seeders/data/25-sinaloa/`. Versionar en git.

> Para Mazatlan ya existe el GeoJSON 4326 generado en el prototipo; sirve como
> primer dato semilla sin volver a correr GDAL. Para el estado completo, usar
> uno de los comandos de arriba.

> Nota de tamano: el GeoJSON de secciones de un estado completo puede ser grande.
> Si pesa demasiado para git, opciones: (a) versionar solo los municipios en uso,
> (b) git-lfs, (c) simplificar geometria con tolerancia ~5m en la etapa local.

---

## ETAPA 2 — Implementacion en Laravel (lo que se testea y va a prod)

### Migraciones (orden)
1. **Habilitar PostGIS**: `CREATE EXTENSION IF NOT EXISTS postgis;` (primera migracion).
2. `create_entidades_table` — id, clave (smallint unique), nombre, geom GEOMETRY(MultiPolygon,4326). Indice GiST.
3. `create_municipios_table` — id, entidad_id FK, clave (smallint), nombre, geom. Unique (entidad_id, clave). GiST.
4. `create_secciones_table` — id, municipio_id FK, numero (int), tipo (smallint), distrito_federal (smallint), distrito_local (smallint), lista_nominal (int null), geom. Unique (municipio_id, numero). GiST.

> Columnas geometricas y GiST via `DB::statement` en la migracion, o helper del
> paquete `matanyadaev/laravel-eloquent-spatial`.

### Modelos Eloquent (globales, sin BelongsToTenant)
- `Entidad` hasMany `Municipio`; `Municipio` belongsTo `Entidad`, hasMany `Seccion`; `Seccion` belongsTo `Municipio`.
- Cast de `geom` con el paquete espacial.

### Seeder: `CartografiaSeeder`

> Nota CRS: los GeoJSON declaran CRS84 (estandar GeoJSON, orden lng,lat). Es el mismo
> datum que EPSG:4326; `ST_GeomFromGeoJSON` entrega lng/lat correctamente, solo aplicar
> `ST_SetSRID(...,4326)`. No hay reproyeccion en el seeder.

1. Recibe el directorio del estado (ej. `database/seeders/data/25-sinaloa/`).
2. Lee `entidad.geojson`, `municipios.geojson`, `secciones.geojson` con `json_decode`.
3. Inserta en orden (entidad -> municipios -> secciones) para resolver FKs.
4. Por cada feature, upsert con SQL:
   - geom: `ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(:geom),4326))`.
   - entidades: `ON CONFLICT (clave) DO UPDATE`.
   - municipios: resolver `entidad_id` por la clave de entidad; `ON CONFLICT (entidad_id,clave)`.
   - secciones: resolver `municipio_id`; mapear `seccion->numero, distrito_f->distrito_federal, distrito_l->distrito_local`; `ON CONFLICT (municipio_id,numero)`.
5. Procesar secciones en **chunks** (transaccion por lote) para no agotar memoria.
6. Resumen por consola: conteos + validacion `ST_SRID=4326`.

### (Opcional) comando ergonomico
`territori:cargar-cartografia {estado}` que internamente llama al seeder con el directorio correcto. Azucar; el nucleo testeable es el seeder.

---

## Tests (TDD — escribir primero)

DB PostGIS de prueba. Usar un **fixture reducido**: un GeoJSON con la entidad, 1-2 municipios y un punado de secciones, para velocidad. Test "smoke" opcional con el dato real `@group slow`.

1. `test_habilita_postgis` — extension existe tras migrar.
2. `test_seeder_carga_entidad` — entidad con geom no nula, SRID 4326.
3. `test_seeder_carga_municipios` — N municipios, unique (entidad_id,clave) respetado.
4. `test_seeder_carga_secciones_mazatlan` — 530 secciones del municipio 12 (con el dato real) o N del fixture, con numero/tipo/distritos/geom.
5. `test_geom_srid_4326` — `ST_SRID(geom)=4326`.
6. `test_st_contains_punto_conocido` — punto dentro resuelve la seccion.
7. `test_polygon_se_guarda_como_multipolygon` — geometria uniforme.
8. `test_idempotencia` — correr el seeder dos veces deja el mismo conteo.

## Definicion de Done

- Migraciones aplican en limpio en entorno con PostGIS.
- `CartografiaSeeder` carga el GeoJSON semilla de Mazatlan: 530 secciones, geom 4326.
- Todos los tests verdes.
- Produccion NO requiere GDAL ni Python (verificado: el seeder es PHP puro).
- README/SETUP: documentar Etapa 1 (preparacion local) y como correr el seeder.
- ADR-002 referenciado; ninguna geometria fuera de 4326.

## Notas / riesgos

- El paso local manual es aceptable porque el import es raro (1 vez por estado).
- Si el GeoJSON de un estado pesa mucho en git, ver opciones de la Etapa 1 (lfs / por municipio / simplificar).
- Validez geometrica: aunque Sinaloa venia limpio, agregar validacion (`ST_IsValid`) en el seeder y reportar features invalidos en vez de fallar en silencio.
