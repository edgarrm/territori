# Spec — Configuración de campaña + catálogo de partidos y coaliciones

- **Feature ID**: F1 (iteración post-demo jul-2026)
- **Estado**: Listo para implementar
- **Depende de**: ADR-001 (multi-tenancy), `_ai/docs/PRD-analisis-electoral-partido.md`, `_ai/docs/data-model.md`

## Objetivo
Dar a cada campaña (tenant) la configuración de su análisis electoral: partido propio, umbrales de clasificación y toggles de indicadores visibles, apoyado en un catálogo global de partidos y coaliciones por año.

## Por qué
Todo el análisis de competitividad (F2) y el desglose por bloques (F3) depende de saber *qué partido es la campaña* y *cómo se agrupan las opciones de boleta*. Hoy no existe: no hay catálogo de partidos, el tenant no tiene partido ni settings, y las coaliciones están hardcodeadas como strings. El cliente además pidió explícitamente poder ajustar umbrales (debatieron 50 vs 30) y anticipó coaliciones distintas en 2027.

## Alcance

### Incluye
- **Migración + modelo `Partido`** (tabla global `partidos`, sin tenant): `siglas` (string, unique), `nombre` (string), `color` (string hex). Seeder con los 9 partidos 2024: PAN, PRI, PRD, PT, PVEM, MC, PAS, MORENA, PES (colores institucionales aproximados).
- **Migración + modelo `Coalicion`** (tabla global `coaliciones`): `nombre` (string), `anio` (unsignedSmallInteger), `partidos` (jsonb, array de siglas). Único `(anio, nombre)`. Seeder 2024: "Fuerza y Corazón por México" `["PAN","PRI","PRD","PAS"]` y "Sigamos Haciendo Historia" `["MORENA","PVEM"]`.
- **Migración de `tenants`**: `partido_id` (FK nullable → `partidos`, `nullOnDelete`) y `settings` (jsonb, nullable). `Tenant` castea `settings` a array y expone accessor con defaults merged (método `configuracion()` o accessor), de modo que un tenant sin settings guardados se comporta con:
  ```json
  {
    "umbral_ganada_franca": 30,
    "umbral_alfa": 1000,
    "umbral_beta": 500,
    "indicadores": {
      "competitividad": true,
      "tipo_seccion": true,
      "indice_neutral": true,
      "oportunidad": true
    }
  }
  ```
- **Servicio de bloques** `App\Support\Bloques` (o clase equivalente en `app/Actions/Estadisticas/`): dada la lista de coaliciones de un año y el mapa `votos_partidos`, resuelve:
  - opción de boleta → bloque: separar el key por `_` en siglas; la opción pertenece a la coalición cuyo array `partidos` contiene **todas** sus siglas; si ninguna coalición la contiene, el partido individual es su propio bloque; keys `CAND_IND1..3` → bloque "Independientes".
  - bloque de un partido dado (para F2): coalición que contiene la sigla, o el partido solo.
  - Salida: bloques con nombre, siglas miembro, total de votos y detalle de opciones. Reutilizado por F2 y F3.
- **Pantalla "Campaña" en settings** (solo rol `admin` del tenant):
  - `GET /settings/campana` → Inertia `settings/Campana.vue`; `PATCH /settings/campana` guarda.
  - Controller `App\Http\Controllers\Settings\CampanaController` + Form Request (`partido_id` exists:partidos nullable; umbrales integer min:1, `umbral_alfa > umbral_beta`; toggles boolean).
  - UI dentro de `layouts/settings/Layout.vue` (nueva entrada de nav "Campaña", visible solo admin): select de partido (siglas + nombre + swatch de color), inputs numéricos de los 3 umbrales, switches de los 4 indicadores. Patrón de `Profile.vue` (useForm + PATCH).
- **Props compartidas**: exponer en `HandleInertiaRequests` (o donde ya se comparte `marca`) la configuración efectiva del tenant (`partido`, `umbrales`, `indicadores`) para que Mapa/Prioridades/Seccion la consuman sin fetch extra.

### No incluye
- CRUD de partidos/coaliciones en UI (solo seeders; alta de coaliciones 2027 será otro seeder o comando).
- Aplicar la configuración en mapa/vistas (eso es F2/F3; aquí solo se persiste y expone).
- Validación de que el partido configurado exista en `votos_partidos` de las secciones (F2 maneja "sin datos").

## Criterios de aceptación (tests)
1. Dado el seeder ejecutado, cuando se consultan `partidos` y `coaliciones`, entonces existen los 9 partidos y las 2 coaliciones 2024 con sus siglas correctas.
2. Dado un tenant sin `settings` guardados, cuando se lee su configuración efectiva, entonces devuelve los defaults (umbral 30, alfa 1000, beta 500, 4 indicadores en true) y `partido = null`.
3. Dado un admin del tenant, cuando hace `PATCH /settings/campana` con `partido_id` de MORENA y `umbral_ganada_franca = 50`, entonces se persiste y la configuración efectiva refleja ambos valores (el resto sigue en default).
4. Dado un usuario con rol `coordinador` o `brigadista`, cuando intenta `GET` o `PATCH /settings/campana`, entonces se rechaza (403).
5. Dado un `PATCH` con `umbral_beta >= umbral_alfa` o umbrales no positivos, entonces falla la validación.
6. Dado el servicio de bloques con las coaliciones 2024, cuando se agrupa un `votos_partidos` de ejemplo, entonces: `PAN_PRI` y `PAN_PRI_PRD_PAS` caen en "Fuerza y Corazón"; `PVEM_MORENA` y `MORENA` en "Sigamos Haciendo Historia"; `MC` queda como bloque propio; `CAND_IND1` en "Independientes"; y la suma de todos los bloques = suma de todas las opciones.
7. Dado el partido MORENA, cuando se pide su bloque 2024, entonces devuelve "Sigamos Haciendo Historia" con siglas {MORENA, PVEM}; dado MC, devuelve bloque de solo MC.
8. Dado dos tenants, cuando uno cambia su configuración, entonces la del otro no cambia (aislamiento).

## Notas de implementación
- `php artisan make:model Partido -mfs` / `Coalicion -mfs`; los seeders van referenciados desde `DatabaseSeeder` y deben ser idempotentes (`updateOrCreate` por siglas / por `(anio, nombre)`), porque producción ya tiene datos.
- `coaliciones.partidos` como array de siglas (no pivot): las siglas son el identificador natural en `votos_partidos` y el volumen es mínimo. Si algún día se necesita integridad referencial fuerte, migrar a pivot será trivial.
- Guardar en `tenants.settings` **solo las claves que difieren** no es necesario: guardar el objeto completo del form es más simple; los defaults se aplican al leer con `array_replace_recursive(DEFAULTS, $settings ?? [])`.
- La entrada de nav en `layouts/settings/Layout.vue` se condiciona al rol admin (el layout ya recibe `page.props.auth`); proteger también la ruta con el middleware `rol:admin` existente.
- Ejecutar `vendor/bin/pint --dirty --format agent` al cierre.
