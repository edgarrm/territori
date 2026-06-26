# SETUP.md — Arranque de Territori (Mac M3 · Herd + Homebrew)

> Secuencia completa para dejar el proyecto listo antes de implementar el Sprint 1.
> Stack: Laravel 13 + Fortify + Vue 3/Inertia + PostgreSQL 16 + PostGIS. Entorno: Herd (nativo) en macOS.
> Ejecuta los pasos en orden. Cada bloque asume que el anterior terminó sin error.

---

## 0. Prerrequisitos del sistema

Herd ya te da PHP 8.3, Composer, Node, npm. Falta la base de datos geoespacial (PostGIS); GDAL es opcional (solo preparacion local de cartografia).

```bash
# Verifica lo que ya tienes
php -v          # debe ser 8.2+ (ideal 8.3)
composer -V
node -v

# PostgreSQL + PostGIS (Homebrew)
brew install postgresql@16 postgis
brew services start postgresql@16

# GDAL — OPCIONAL. Solo para la "preparacion local" de cartografia nueva
# (convertir shapefiles del INE a GeoJSON). NO lo necesita produccion ni la app.
# Puedes omitirlo si usaras los GeoJSON semilla ya incluidos en el repo, o si
# prefieres hacer la conversion con geopandas/Docker. Ver _ai/specs/import-cartografia.plan.md
brew install gdal      # opcional
which ogr2ogr          # confirma si quedo (solo si lo instalaste)
```

> Si usas **Herd Pro**, puedes crear la base Postgres desde su UI en vez de Homebrew. Pero PostGIS se habilita igual con la extensión (paso 3).

---

## 1. El proyecto Laravel (ya creado con el starter kit Vue)

El proyecto ya existe: starter kit `laravel/vue-starter-kit` → **Laravel 13 + Fortify + Vue/Inertia** (NO Jetstream). Si tuvieras que recrearlo:

```bash
cd ~/Herd            # carpeta parkeada en Herd
laravel new territori    # elige: Vue starter kit, Pest para tests
cd territori
```

> Sobre "teams support" de Jetstream: **responder No / no instalarlo**. Territori usa
> su propio modelo tenant/membership (ADR-001), no el teams de Jetstream (descontinuado).
> Fortify (incluido) ya da login, registro, reset, verificación email y 2FA.

Con Herd, queda servido en `https://territori.test` automáticamente.

```bash
npm install
npm run dev      # deja Vite corriendo en otra terminal
```

---

## 2. ⚠️ Cambiar de SQLite a PostgreSQL ANTES de migrar

**Crítico**: el starter kit arranca con SQLite (verás `database/database.sqlite` y el script `post-create-project-cmd` que ya pudo correr migraciones en SQLite). Territori NECESITA PostgreSQL+PostGIS. Antes de cualquier migración nuestra:

```bash
# Crea la base Postgres (y una de pruebas)
createdb territori
createdb territori_testing

# Borra el SQLite del scaffold para no usarlo por error
rm -f database/database.sqlite
```

> Si el starter kit ya corrió sus migraciones base en SQLite, no pasa nada: las
> volveremos a correr en Postgres tras configurar el `.env` (paso 3).

---

## 3. Configurar `.env` para Postgres + PostGIS

Edita `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=territori
DB_USERNAME=<tu_usuario_mac>
DB_PASSWORD=
```

> **Tests en Postgres, no SQLite**: como PostGIS y las funciones espaciales (`ST_Contains`,
> `ST_GeomFromGeoJSON`) NO existen en SQLite, los tests DEBEN correr en PostgreSQL.
> Revisa `phpunit.xml`: si trae `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`,
> cámbialo a pgsql apuntando a `territori_testing`. Si no, los tests del Sprint 1 fallarán.

> Confirma el driver pgsql de PHP (Herd suele traerlo): `php -m | grep pdo_pgsql`.
> Si falta, da el error clásico "could not find driver".

La extensión PostGIS se habilita con una **migración** (no a mano), para que sea reproducible. La crearemos como primer paso del Sprint 1:

```php
// database/migrations/0001_01_01_000000_enable_postgis.php
public function up(): void { DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;'); }
public function down(): void { DB::statement('DROP EXTENSION IF EXISTS postgis;'); }
```

```bash
php artisan migrate:fresh   # re-corre TODO en Postgres (las base del starter kit + PostGIS)
```

> Usamos `migrate:fresh` aquí (no solo `migrate`) porque el starter kit pudo haber
> migrado en SQLite; esto reconstruye limpio sobre Postgres.

---

## 4. Dependencias del proyecto

> El starter kit YA incluye: Pest, Larastan/PHPStan, Pint, Pail, Sail, Inertia, Fortify,
> Wayfinder. No reinstales eso. Solo falta lo geoespacial y Boost.

```bash
# Paquete espacial para castear geometrías en Eloquent (ADR-002)
# Verifica que su versión soporte Laravel 13 antes de instalar (composer lo resolverá).
composer require matanyadaev/laravel-eloquent-spatial

# Laravel Boost — contexto Laravel para Claude Code / Antigravity (dev only)
composer require laravel/boost --dev
php artisan boost:install
# En el instalador de Boost: selecciona MCP server, guidelines, y tus agentes
# (Claude Code y/o Antigravity). Genera .mcp.json y archivos de guidelines.
```

> ⚠️ Compatibilidad: confirma que `laravel-eloquent-spatial` tenga release para Laravel 13.
> Si aún no, alternativas: usar SQL crudo (`DB::statement` con funciones PostGIS) para las
> columnas geométricas sin depender del paquete, o fijar la versión compatible. El ADR-002
> no exige ese paquete específico; es conveniencia para castear geometrías.

> Boost es solo dev: no se instala en producción. Le da a Claude Code acceso a tu
> esquema real, rutas, modelos y docs versionadas de Laravel 13 — menos alucinaciones.

---

## 5. Copiar la documentación AI-First al repo

Descomprime `territori_ai.zip` y coloca la carpeta `_ai/` en la raíz del proyecto:

```
territori/
├── _ai/                    ← CONTEXT.md, specs, adrs, docs (del zip)
├── app/
├── database/
└── ...
```

Así Claude Code y Boost conviven: `_ai/CONTEXT.md` es tu fuente de verdad de producto/arquitectura; Boost aporta el contexto vivo del código.

> Opcional: para que los agentes lean el contexto automáticamente, puedes añadir en
> `.ai/guidelines/` (de Boost) una nota que apunte a `_ai/CONTEXT.md`, o referenciarlo
> al inicio de cada sesión de Claude Code.

---

## 6. Inicializar git

```bash
git init
git add .
git commit -m "chore: configure Laravel 13 + Vue/Inertia + PostGIS + Boost + _ai docs"
```

Verifica que `.env` esté en `.gitignore` (Laravel ya lo trae). El `_ai/` SÍ se versiona.

---

## 7. Verificación final antes de implementar

```bash
php artisan about        # confirma Laravel 13, PHP 8.3, driver pgsql
php artisan migrate:status
php -r "echo extension_loaded('pdo_pgsql') ? 'pgsql OK' : 'falta pdo_pgsql';"
psql territori -c "SELECT postgis_version();"   # confirma PostGIS activo
# (GDAL/ogr2ogr solo si haras preparacion local de cartografia nueva)
```

Si todos pasan, estás listo para el **Sprint 1**: implementar
`_ai/specs/import-cartografia.spec.md` siguiendo `import-cartografia.plan.md`.

---

## Resumen del orden (TL;DR)

1. `brew install postgresql@16 postgis` (+ `gdal` opcional) + start postgres
2. Proyecto ya creado (vue-starter-kit, Laravel 13 + Fortify). Cambiar SQLite→Postgres
3. `createdb territori`
4. `.env` a pgsql + migración PostGIS
5. `composer require matanyadaev/laravel-eloquent-spatial` + Boost (`--dev` + `boost:install`)
6. Copiar `_ai/` al repo
7. `git init` + commit
8. Verificar (`postgis_version()`) → implementar Sprint 1

---

## Primer prompt sugerido para Claude Code

> "Lee `_ai/CONTEXT.md` y `_ai/specs/import-cartografia.spec.md` + `import-cartografia.plan.md`.
> Vamos a implementar el Sprint 1 con TDD: empieza escribiendo las migraciones
> (PostGIS, entidades, municipios, secciones) y los tests de `import-cartografia.plan.md`
> en rojo, antes del comando `territori:import-cartografia`. No implementes el comando
> todavía; muéstrame primero migraciones + tests para revisarlos."

---

## 8. Seguridad de cadena de suministro (supply chain)

2026 ha tenido oleadas constantes de ataques a npm y Composer (familia Shai-Hulud/Miasma): paquetes que roban credenciales al instalarse y se autopropagan. Tu Mac de desarrollo tiene tokens de GitHub/AWS/npm, así que es un blanco. Estas medidas son baratas y cortan la mayoría de los vectores. El principio clave: **la mayoría de paquetes maliciosos se detectan y retiran en horas/días → no instales nada recién publicado.**

### 8.1 `.npmrc` del proyecto (crear en la raíz)

```ini
# Enfriamiento: rechaza paquetes publicados hace menos de 7 días.
# El ataque a Axios duró ~5h; 7 días lo habría bloqueado por completo.
min-release-age=604800

# No ejecutar scripts de ciclo de vida (preinstall/postinstall) por defecto.
# Es el vector #1. Se habilitan caso por caso si un paquete legítimo lo necesita.
ignore-scripts=true
```

> Con `ignore-scripts=true`, paquetes que compilan binarios pueden necesitar
> `npm rebuild <paquete>` manual tras instalar. Es el precio de seguridad correcto.

### 8.2 Reglas de instalación

- **Usa `npm ci`, NO `npm install`** en flujos normales. `npm install` puede reescribir el lockfile a una versión envenenada; `npm ci` respeta el lockfile existente. Solo usa `npm install <pkg>` para añadir una dependencia nueva conscientemente.
- **Fija versiones exactas** en `package.json`: quita `^` y `~`. Maneja upgrades a mano y revisados.
- **Commitea los lockfiles** (`package-lock.json`, `composer.lock`). Nunca en `.gitignore`.
- **Composer**: usa `composer install` (respeta `composer.lock`), evita `composer update` salvo upgrades deliberados. Considera `--no-scripts` en CI.

### 8.3 Recomendación fuerte: usar pnpm en vez de npm

Para un proyecto nuevo, pnpm da protección del lado del consumidor que el CLI de npm no tiene: bloquea scripts de ciclo de vida y pone en cuarentena releases nuevos **por defecto** (desde finales de 2025). Usa el mismo registro de npm. Si decides adoptarlo:

```bash
brew install pnpm
pnpm import          # convierte package-lock.json a pnpm-lock.yaml
# .npmrc: pnpm respeta min-release-age e ignore-scripts igual
```

> Trade-off: Vite/Laravel docs asumen npm en sus ejemplos; con pnpm cambias
> `npm run dev` por `pnpm dev`, etc. Si prefieres no cambiar de gestor, quédate
> en npm con el `.npmrc` de 8.1, que ya cubre lo esencial.

### 8.4 Higiene de credenciales (la máquina es el objetivo del payload)

- **MFA resistente a phishing** (passkey/llave física) en npm, GitHub y cloud.
- **Tokens con mínimo alcance** y de vida corta; nada de tokens "god mode" en el `.env` o en variables de entorno globales.
- Si sospechas de una instalación rara (procesos hijos inesperados, tráfico de red en `npm install`): **rota credenciales de inmediato** (tokens npm, GitHub, AWS, SSH) y limpia caché (`npm cache clean --force`).
- **Desconfía de nombres conocidos**: ha habido paquetes maliciosos suplantando incluso CLIs de seguridad (`@bitwarden/cli` falso). Verifica el paquete, el mantenedor y los downloads antes de añadirlo.
- Verifica nombres por typosquatting (`axois` vs `axios`) al añadir dependencias.

### 8.5 Específico de este proyecto

- Las dependencias que añadimos (`laravel/boost`, `matanyadaev/laravel-eloquent-spatial`) son de Composer (Packagist), no npm; aplica igual: versiones fijas + `composer.lock` commiteado + revisar antes de actualizar.
- `laravel/boost` es **dev-only**: nunca debe ir en builds de producción (`composer install --no-dev` en prod).
- GDAL/PostGIS vienen por Homebrew (no por gestores de paquetes de lenguaje), menor superficie de este tipo de ataque; mantén Homebrew actualizado igual.
