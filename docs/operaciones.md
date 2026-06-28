# Operaciones — Territori

> Runbook para preparar entornos, cargar datos y mantener la app. Para el arranque local detallado y la sección de seguridad de cadena de suministro, ver [`_ai/SETUP.md`](../_ai/SETUP.md).

## Requisitos

- **PostgreSQL 16 + PostGIS** (las migraciones y los tests fallan sin la extensión espacial).
- **PHP 8.3+** — usar el PHP de Herd: `~/Library/Application\ Support/Herd/bin/php85`. El `php` del PATH puede ser 8.1 y no corre la app.
- Node + npm (Vite). En producción, build estático con `npm run build`.

## Base de datos

⚠️ **Cambiar de SQLite a PostgreSQL ANTES de migrar.** El starter kit arranca con SQLite; Territori necesita pgsql+PostGIS.

```dotenv
# .env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=territori
DB_USERNAME=<tu_usuario>
DB_PASSWORD=
```

```bash
createdb territori
createdb territori_testing          # tests corren en Postgres, no SQLite
rm -f database/database.sqlite      # evita usar el SQLite del scaffold por error
php artisan migrate                 # la 1ª migración habilita PostGIS
psql territori -c "SELECT postgis_version();"   # verificación
```

`phpunit.xml` debe apuntar `DB_CONNECTION=pgsql` / `DB_DATABASE=territori_testing` (no `:memory:`).

## Carga de cartografía INE

No hay comando artisan (decisión: la carga se invoca manualmente). El `CartografiaSeeder` hace upsert **idempotente** desde GeoJSON 4326 ya preparado.

```bash
php artisan tinker --execute "(new \Database\Seeders\CartografiaSeeder)->run('database/seeders/data/25-sinaloa');"
```

Datos semilla incluidos: Sinaloa (clave 25), 20 municipios, 530 secciones de Mazatlán en `database/seeders/data/25-sinaloa/`. La preparación local (shapefile→GeoJSON) requiere GDAL y NO se hace en producción.

## Seeders de demo (idempotentes, en orden)

```bash
php artisan db:seed --class=DemoTenantSeeder    # tenant demo + admin/coordinador/brigadista + aviso de privacidad vigente
php artisan db:seed --class=DemoCapturasSeeder  # ~9.8k electores en las 530 secciones, 5 brigadistas, metas,
                                                # ~120 interacciones (40 pendientes), 2 eventos, 1 solicitud ARCO
```

`DemoCapturasSeeder` limpia (`electores`/`interacciones`/`eventos`/`solicitudes_arco`) en su reset antes de re-sembrar. Distribuye la cobertura para cubrir los 5 buckets del mapa. La **penetración** sale 0 porque Mazatlán no trae `lista_nominal` del INE.

## Recalcular cobertura

La tabla derivada `cobertura_seccion` se actualiza por evento al capturar. Para un recálculo completo (idempotente, recuenta — no incrementa):

```bash
php artisan territori:recalcular-cobertura {tenant_id}
```

Reusa la Action `RecalcularCoberturaSeccion` (la misma del job).

## Colas / jobs

`ElectorCapturado` encola `ActualizarCoberturaSeccion`. Configurar un worker en entornos con cola persistente (Redis/database):

```bash
php artisan queue:work
```

El job fija el `TenantContext` desde su `tenant_id` (los jobs no heredan el tenant activo).

## Deploy

- Plataforma de referencia: **Laravel Cloud**.
- `composer install --no-dev` en producción (Boost es dev-only).
- `npm ci && npm run build` (assets de Vite); si no, error "Unable to locate file in Vite manifest".
- Migrar (`php artisan migrate --force`) y cargar cartografía del/los municipio(s) de cada campaña.
- Caches de framework: `php artisan config:cache route:cache view:cache` según entorno.

## Seguridad de cadena de suministro

- Versiones **exactas** (sin `^`/`~`). Commitear `package-lock.json` y `composer.lock`.
- `npm ci` / `composer install` (no `install`/`update`, que reescriben lockfiles).
- No desactivar `ignore-scripts` / `min-release-age` del `.npmrc`. No instalar paquetes recién publicados.
- Detalle completo en [`_ai/SETUP.md`](../_ai/SETUP.md) §8.

## Verificación rápida del entorno

```bash
php artisan about                                  # Laravel 13, PHP, driver pgsql
php -r "echo extension_loaded('pdo_pgsql')?'ok':'falta pdo_pgsql';"
psql territori -c "SELECT postgis_version();"
~/Library/Application\ Support/Herd/bin/php85 artisan test --compact   # 166 verdes
```
