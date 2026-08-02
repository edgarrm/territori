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

## ⚠️ Checklist de hardening de producción

`.env.example` viene orientado a desarrollo (`APP_DEBUG=true`, `APP_ENV=local`, `SESSION_ENCRYPT=false`, `DB_CONNECTION=sqlite`). **Copiarlo tal cual a producción filtra stack traces con datos sensibles.**

La lista completa de variables de seguridad vive en un solo sitio: el bloque
**`# production variables`** al final de [`.env.example`](../.env.example). Está
comentado para que copiar el archivo siga dando un entorno de desarrollo que arranca;
al montar producción se copia ese bloque y se descomenta. Crece con cada fase de la
remediación de seguridad, así que consúltalo ahí en vez de duplicarlo aquí.

`AppServiceProvider::verificarConfiguracionDeProduccion()` aborta el arranque si en
producción `APP_DEBUG` está activo o falta `SESSION_SECURE_COOKIE`/`SESSION_ENCRYPT`.
Es deliberado: toda la política de seguridad (contraseñas de 12 con `uncompromised`,
`prohibitDestructiveCommands`) cuelga de `isProduction()`, así que un `APP_ENV` mal
puesto la degradaba en silencio.

- Forzar **HTTPS** (redirección + HSTS) a nivel de plataforma/servidor.
- `composer install --no-dev --optimize-autoloader` (excluye Boost y herramientas de dev).
- Cola persistente real (`QUEUE_CONNECTION=redis|database`) + worker (`queue:work`) para los jobs de cobertura.
- Rotación de `APP_KEY` solo con plan de migración: re-cifra `telefono`/`domicilio` (cast `encrypted`) **y** invalida los `telefono_hash` (HMAC con APP_KEY) → habría que recalcularlos.
- Mantener `min-release-age`/`ignore-scripts` del `.npmrc` y los lockfiles commiteados (ver §Seguridad de cadena de suministro).

## ⚠️ Exponer la instancia local por un túnel (ngrok)

Publicar el entorno de desarrollo en internet es lo más peligroso que se le puede hacer
a esta app, porque `laravel/dusk` y `laravel/boost` registran rutas **sin autenticación**
en cuanto `APP_ENV` no es `production`:

| Ruta | Riesgo |
|------|--------|
| `GET /_dusk/login/{userId}` | Autentica como cualquier usuario sin contraseña ni 2FA → acceso total al padrón |
| `GET /_dusk/logout`, `/_dusk/user` | Manipulación de sesión sin auth |
| `POST /_boost/browser-logs` | Sin auth **ni CSRF**: log injection y llenado de disco |

`App\Http\Middleware\BloqueaRutasDeDesarrollo` (stack global, primero) las cierra con 404:
`_dusk/*` solo responde en `testing`/`dusk`/`dusk.local`, y `_boost/*` solo cuando la app
está en `local` **y** el socket viene de loopback — se lee `REMOTE_ADDR` a propósito, sin
pasar por la resolución de proxies, porque `X-Forwarded-For` lo escribe el cliente.

Aun así, antes de levantar un túnel:

- `composer install --no-dev` para que los paquetes ni existan.
- Nunca con datos reales de personas: usa seeders de demo.
- Acota `TRUSTED_PROXIES`; con `'*'` cualquier cliente es un "proxy confiable" y puede
  rotar `X-Forwarded-For` para saltarse el rate limit de login.

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
