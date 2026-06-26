# CONTEXT.md — Territori

> **Este archivo se carga al inicio de CADA sesión de Claude Code.** Es la fuente de verdad del proyecto. Si algo aquí contradice una instrucción suelta, gana este archivo (o se actualiza explícitamente).

---

## Qué es Territori

SaaS multi-tenant de **gestión territorial para campañas políticas** en México/LATAM. Cada campaña (tenant) elige un municipio, hereda sus secciones electorales del INE, y captura electores (contactos) georreferenciados por sección. El producto se vende por su **analítica territorial de cobertura en vivo** y su **gestión de brigadistas**.

**El dashboard central es un mapa** de secciones coloreado por cobertura (rojo = rezagada, verde = meta cumplida).

## Para quién

- **Brigadista**: captura en campo desde el celular, rápido. Rol `brigadista`.
- **Coordinador**: vive en el dashboard, reasigna esfuerzo. Rol `coordinador`.
- **Candidato/Dirección**: ve macro y proyección. (usa rol `coordinador` o `admin`).
- **Admin SaaS**: da de alta campañas. Rol `admin`.

---

## Stack

- **Backend**: Laravel 13 (PHP 8.3+) — starter kit oficial `laravel/vue-starter-kit`.
- **Auth**: **Laravel Fortify** (login, registro, reset, verificación email, 2FA, rate limiting). NO Jetstream (descontinuado). Fortify es solo backend; la UI es Vue/Inertia. Sobre Fortify montamos nuestro modelo users/memberships/tenant.
- **Frontend**: Vue 3 + Inertia.js v3 (SPA sin API REST separada para la app interna). Wayfinder para rutas tipadas.
- **DB**: PostgreSQL 16 + **PostGIS**. ⚠️ El starter kit viene con SQLite por defecto: hay que cambiar a pgsql en `.env` y `config/database.php` ANTES de migrar, o las migraciones PostGIS fallan.
- **Mapa**: Leaflet (GeoJSON servido por endpoint, geometría simplificada).
- **Calidad** (incluida en el starter kit): Pest (tests), Larastan/PHPStan (análisis estático), Pint (formato). Usar en el ciclo TDD.
- **Estilos**: (definir en Fase 03 — design tokens). Mobile-first responsive. Tailwind 4.
- **Colas**: jobs para agregados de cobertura (Redis/database queue).
- **Multi-tenancy**: por `tenant_id` (ADR-001), montado sobre el User de Fortify.

## Principios de arquitectura (ver _ai/adrs/)

1. **ADR-001 Multi-tenancy**: single-DB, `tenant_id` por columna, trait `BelongsToTenant` con global scope. `tenant_id` se resuelve del usuario autenticado, **NUNCA del request**. Catálogos INE (entidades/municipios/secciones) son **globales, sin tenant_id**.
2. **ADR-002 PostGIS**: geometrías en SRID 4326. Detección de sección por GPS con `ST_Contains`. Geometría servida al front siempre simplificada/cacheada.
3. **ADR-003 Captura unificada**: los 3 modos (lotería/individual/evento) escriben en `electores`, diferenciados por `modo_captura`. La cobertura del mapa se lee de la tabla derivada `cobertura_seccion`, **nunca** se agrega en vivo sobre `electores`.
4. **ADR-004 Privacidad**: datos personales bajo LFPDPPP. NO se captura intención de voto. Consentimiento obligatorio. Teléfono/domicilio cifrados; `telefono_hash` para dedup. Derechos ARCO soportados.
5. **ADR-005 White-label + facturación**: marca por tenant (`marca_nombre/logo/color/subdominio`), resolución de tenant por subdominio o tras login. Cobro por **brigadista activo** = membership con `rol=brigadista` y `activo=true`; `tenants.limite_brigadistas` por plan.

## Modelo de datos

Ver `_ai/docs/data-model.md` (autoridad). Entidad central: **`electores`** (tenant → sección → elector). Un elector tiene N **`interacciones`** (timeline; `tipo`=canal, `resultado`=qué pasó, separados). Metas en `metas_seccion` (manual o % de lista nominal). Agregados en `cobertura_seccion`.

**Identidad y pertenencia** (decidido): `users` = persona física (global, email único, sin tenant_id ni rol). `memberships` = pivote `(tenant_id, user_id, rol, meta_diaria, activo)`; una persona puede estar en varias campañas con distinto rol. `electores`/`loterias`/`interacciones` referencian `membership_id`, no `user_id`. Sin jerarquía de coordinadores (un solo nivel).

---

## Reglas para Claude Code en este repo

### Siempre
- **Spec primero**: ninguna feature se implementa sin su `_ai/specs/{feature}.spec.md`. Si no existe, créalo y pídelo validar antes de codear.
- Todo modelo de datos de campaña usa el trait `BelongsToTenant`. Si creas un modelo nuevo tenant-scoped y lo olvidas, es un bug.
- Toda query que toque `electores`/`interacciones` debe respetar el tenant scope. Prohibido `withoutGlobalScopes()` salvo en jobs de sistema documentados.
- Tests primero (TDD): el ciclo es Rojo → Verde → Refactor.
- Validación de teléfono y dedup por `telefono_hash` al crear electores.
- Consentimiento obligatorio: no se persiste un elector sin `consentimiento=true` y `aviso_privacidad_id`.

### Nunca
- Nunca aceptar `tenant_id` desde el cliente/request.
- Nunca leer el rol desde `users`: el rol vive en `memberships` y es por campaña.
- Nunca ligar una captura a `user_id` directo: usar `membership_id` (ata la persona al tenant correcto).
- Nunca ingerir nombres del padrón del INE (solo cartografía + conteo agregado de lista nominal).
- Nunca agregar `COUNT(*) GROUP BY seccion` sobre `electores` en el endpoint del mapa (usar `cobertura_seccion`).
- Nunca añadir campos de intención de voto o afiliación (fuera de alcance, riesgo legal).
- Nunca mezclar SRIDs; todo es 4326 de punta a punta.
- Nunca activar un brigadista por encima de `tenants.limite_brigadistas` sin manejar el caso (bloqueo/upsell).
- Nunca añadir una dependencia con rango `^`/`~`: fijar versión exacta. Usar `npm ci`/`composer install` (no `install`/`update` que reescriben lockfiles). No desactivar `ignore-scripts`/`min-release-age` del `.npmrc` (ver SETUP.md §8, seguridad de cadena de suministro).

### Convenciones
- Migraciones: una por tabla, con índices compuestos `(tenant_id, ...)` donde aplique.
- Eventos de dominio: `ElectorCapturado` dispara el job de actualización de `cobertura_seccion`.
- Comandos artisan de dominio prefijados `territori:` (ej. `territori:import-cartografia`, `territori:recalcular-cobertura`).

---

## Estado actual del proyecto

- **Fase**: 04 (Architecture) terminando → siguiente 05/06 (SDD + Implementation).
- **Hecho**: PRD validado, ADRs 001-004, data-model, api-contract, demo de mapa (prototipo Leaflet con datos simulados — referencia visual, NO es el código final).
- **Pendiente inmediato (Sprint 1)**: seeder de cartografía INE (GeoJSON pre-procesado → PostGIS). GeoJSON semilla de Mazatlán ya incluido en `database/seeders/data/25-sinaloa/`.
- **Preguntas abiertas RESUELTAS**: lista nominal = global (en `secciones`); brigadista multi-campaña = sí (users + memberships); jerarquía coordinadores = no (un nivel); white-label = sí (desde inicio, ADR-005); cobro = por brigadista activo (ADR-005). Único pendiente de producto: política exacta de cobro (pico mensual vs promedio vs corte).

## Orden de construcción (sprints)

1. Cartografía INE: preparación local (shapefile→GeoJSON 4326) + `CartografiaSeeder` PHP a PostGIS. Prod sin GDAL/Python.
2. Auth + identidad (`users`) + multi-tenancy (`memberships`, resolución de tenant) + alta de campaña (municipio + marca white-label).
3. Mapa de cobertura (lee `cobertura_seccion`) + metas por sección.
4. Captura Lotería + Individual + validación/dedup + consentimiento.
5. Brigadistas (memberships) + asignación de zonas + ratios + límite/facturación por activo.
6. Interacciones (timeline) + agenda de seguimientos + export + aviso de privacidad.

## Referencia de la demo

El prototipo en `territori_demo.html` muestra la UX objetivo del mapa, la ficha de sección, la ficha de elector y el timeline de interacciones. Sirve como **referencia de diseño e interacción**, no como código a portar tal cual.
