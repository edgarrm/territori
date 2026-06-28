# Territori

SaaS multi-tenant de **gestión territorial para campañas políticas** (México / LATAM). Cada campaña (tenant) elige un municipio, hereda sus secciones electorales del INE y captura electores (contactos) georreferenciados por sección. El producto se vende por su **analítica territorial de cobertura en vivo** (un mapa de secciones coloreado rojo→verde según avance vs. meta) y su **gestión de brigadistas**.

> El dashboard central es un **mapa de cobertura**. La captura ocurre en campo desde el celular. La analítica se lee de una tabla agregada, nunca en vivo sobre los electores.

## Stack

| Capa | Tecnología |
|---|---|
| Backend | Laravel 13 (PHP 8.3+), starter kit `laravel/vue-starter-kit` |
| Auth | Laravel Fortify (login, registro, reset, verificación email, 2FA) |
| Frontend | Vue 3 + Inertia.js v3 (SPA), Wayfinder (rutas tipadas), Tailwind 4 |
| DB | PostgreSQL 16 + **PostGIS** (geometrías SRID 4326) |
| Mapa | Leaflet (GeoJSON con geometría simplificada) |
| Calidad | PHPUnit, Larastan/PHPStan, Pint |

## Quickstart local (macOS + Herd)

> Guía completa y notas de seguridad de cadena de suministro en [`_ai/SETUP.md`](_ai/SETUP.md).

```bash
# 1. Base de datos geoespacial
brew install postgresql@16 postgis
brew services start postgresql@16
createdb territori
createdb territori_testing

# 2. Dependencias (versiones fijas; respeta los lockfiles)
composer install
npm ci

# 3. Entorno: .env con PostgreSQL (NO SQLite) ANTES de migrar
#    DB_CONNECTION=pgsql / DB_DATABASE=territori / DB_USERNAME=<tu_usuario_mac>
cp .env.example .env   # si no existe
php artisan key:generate

# 4. Migrar (la primera migración habilita la extensión PostGIS)
php artisan migrate

# 5. Datos: cartografía INE + demo (ver docs/operaciones.md)
php artisan tinker --execute "(new \Database\Seeders\CartografiaSeeder)->run('database/seeders/data/25-sinaloa');"
php artisan db:seed --class=DemoTenantSeeder
php artisan db:seed --class=DemoCapturasSeeder

# 6. Levantar la app (Vite + servidor)
composer run dev
```

Con Herd la app queda servida en `https://territori.test`.

> ⚠️ **PHP**: el `php` del PATH puede ser 8.1 y **no corre la app**. Usa el PHP de Herd:
> `~/Library/Application\ Support/Herd/bin/php85 artisan ...`

## Tests

```bash
~/Library/Application\ Support/Herd/bin/php85 artisan test --compact
```

Los tests **deben** correr en PostgreSQL (PostGIS no existe en SQLite). Suite actual: **166 tests verdes**, Larastan y Pint limpios.

```bash
vendor/bin/pint            # formato
vendor/bin/phpstan analyse # análisis estático
```

## Documentación

| Para quién | Documento |
|---|---|
| **Devs — empezar aquí** | [`_ai/CONTEXT.md`](_ai/CONTEXT.md) (fuente de verdad de producto + arquitectura) |
| Devs — arquitectura | [`docs/arquitectura.md`](docs/arquitectura.md) |
| Devs — operaciones / deploy | [`docs/operaciones.md`](docs/operaciones.md) |
| Devs — API / rutas | [`_ai/docs/api-contract.md`](_ai/docs/api-contract.md) |
| Devs — decisiones | [`_ai/adrs/`](_ai/adrs/) · modelo de datos en [`_ai/docs/data-model.md`](_ai/docs/data-model.md) |
| **Usuarios finales** | [`docs/usuarios/`](docs/usuarios/) (guías por rol + onboarding) |

## Roles

- **Brigadista** — captura en campo desde el celular.
- **Coordinador** — vive en el dashboard del mapa; reasigna esfuerzo, define metas, gestiona brigadistas.
- **Admin** — da de alta campañas y administra la facturación.

## Privacidad

Datos personales bajo **LFPDPPP**. NO se captura intención de voto ni afiliación. Consentimiento obligatorio para guardar un elector. Teléfono y domicilio cifrados en reposo. Derechos **ARCO** soportados (ver [`docs/usuarios/privacidad-arco.md`](docs/usuarios/privacidad-arco.md)).
