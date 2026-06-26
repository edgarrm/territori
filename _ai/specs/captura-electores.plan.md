# Plan de Implementación — Captura de electores (Sprint 4)

> Acompaña a `specs/captura-electores.spec.md`. Laravel 13 + Vue 3/Inertia + PostgreSQL/PostGIS. Escribe en `electores` y dispara `ElectorCapturado`, que alimenta `cobertura_seccion` (leída por el mapa de Sprint 3). ADR-003 (captura unificada), ADR-004 (privacidad/cifrado/consentimiento).
> Reusa toda la tenancy de Sprint 2 (`BelongsToTenant`, `TenantScope`, `TenantContext`, `ResolveTenant`, `User::membershipEn`) y el cast espacial de Sprint 1 (`matanyadaev/laravel-eloquent-spatial`, ya en composer). No introducir paquetes nuevos.

## Orden de construcción (incremental, TDD)

### Bloque A — Esquema
1. Migración `avisos_privacidad`: `id`, `tenant_id`, `version` (varchar 20), `texto` (text), `vigente_desde` (timestamptz), timestamps; índice `(tenant_id, vigente_desde)`.
2. Migración `loterias`: `id`, `tenant_id`, `membership_id` (FK→memberships), `seccion_id` (FK→secciones), `abierta_en` (timestamptz), `cerrada_en` (timestamptz null), timestamps; índice `(tenant_id, membership_id, cerrada_en)` para hallar la sesión abierta.
3. Migración `electores`: per data-model. `telefono`/`domicilio` como `text` (guardan ciphertext del cast `encrypted`), `telefono_hash` varchar(64) null, `ubicacion` `geometry(point, 4326)` null + índice GIST, `consentimiento` boolean, `aviso_privacidad_id` FK→avisos_privacidad, `loteria_id` FK→loterias null, `evento_id` unsignedBigInteger **null sin FK** (eventos llega en Sprint 6). Índices: `(tenant_id, seccion_id)`, `(tenant_id, membership_id)`, `(tenant_id, telefono_hash)`. **Sin** índice único sobre el hash (dedup se maneja en la acción).

### Bloque B — Modelos + factories
4. `App\Models\AvisoPrivacidad` (`BelongsToTenant`), helper estático `vigente()` (último por `vigente_desde`).
5. `App\Models\Loteria` (`BelongsToTenant`): cast `abierta_en`/`cerrada_en` datetime; relaciones `membership()`, `seccion()`; scope `abiertas()`; método `cerrar()`.
6. `App\Models\Elector` (`BelongsToTenant`): casts `telefono`/`domicilio` → `encrypted`, `consentimiento` → boolean, `ubicacion` → `MatanYadaev\EloquentSpatial\Objects\Point` + `use HasSpatial`. Relaciones `seccion()`, `membership()`, `loteria()`, `avisoPrivacidad()`.
7. Factories: `AvisoPrivacidadFactory`, `LoteriaFactory`, `ElectorFactory` (con estado `consentido()`; teléfono fake MX de 10 dígitos).

> **Test de aislamiento aquí (criterio 6/11), antes de seguir.** Mismo teléfono en dos tenants → dos electores; `GET` cross-tenant → 404.

### Bloque C — Dedup + normalización de teléfono
8. `App\Support\Telefono`: `normalizar(string): ?string` (solo dígitos, últimos 10; null si <10) y `hash(string): string` (`hash_hmac('sha256', normalizado, config('app.key'))`).
9. Tests unitarios de normalización/hash: determinismo, mismo número con formato distinto → mismo hash, <10 dígitos → null. (Criterio 8.)

### Bloque D — Lotería (abrir / cerrar / activa)
10. Acciones `App\Actions\Loterias\AbrirLoteria` y `CerrarLoteria`. `AbrirLoteria`: si ya hay una abierta del brigadista, la devuelve (no crea segunda — criterio 9).
11. `LoteriaController`: `POST /api/loterias`, `POST /api/loterias/{loteria}/cerrar`, `GET /api/loterias/activa`. `membership_id` desde `User::membershipEn(tenant)`.
12. Tests: abrir/cerrar, "activa" devuelve la abierta, no se abren dos simultáneas (criterio 9).

### Bloque E — Captura (núcleo)
13. `App\Actions\Electores\CapturarElector::handle(array $datos)`:
    - Resolver `membership` del usuario en el tenant; sin membership → lanzar/abortar 403 (criterio 10).
    - Resolver `seccion_id`: `loteria` → de la lotería abierta; `individual` → `seccion_id` explícito, o `ST_Contains` desde `ubicacion` (limitado al municipio del tenant); sin resolución → 422.
    - Exigir `consentimiento === true` y `aviso_privacidad_id` del tenant; si no → 422, sin persistir (criterio 4).
    - `telefono_hash = Telefono::hash(telefono)`; normalizar < 10 → 422 (criterio 8).
    - Dedup: si existe elector con ese hash en el tenant → devolver existente marcado duplicado (criterio 5/6).
    - Crear `Elector`; `event(new ElectorCapturado($tenantId, $seccionId))`.
14. `ElectorController`: `POST /api/electores` (FormRequest con reglas condicionales por `modo_captura`), `GET /api/electores/{elector}`, `GET /api/secciones/{seccion}/electores` (paginado). Duplicado → 409 con `id` existente.
15. Tests de captura: criterios 1, 2, 3 (modos + ST_Contains), 4 (consentimiento), 5 (dedup 409), 10 (sin membership 403), 11 (cross-tenant 404), 12 (cifrado en reposo — leer columna cruda vía `DB::table` y verificar que no es texto claro).

### Bloque F — Evento → cobertura (cierra pendiente Sprint 3)
16. Evento `App\Events\ElectorCapturado` (`public int $tenantId`, `public int $seccionId`).
17. Job `App\Jobs\ActualizarCoberturaSeccion implements ShouldQueue`: `__construct(int $tenantId, int $seccionId)`; en `handle()` `TenantContext::set(Tenant::findOrFail($tenantId))`, recuenta `Elector::where('seccion_id', …)->count()`, lee meta/lista_nominal y `CoberturaSeccion::upsertParaSeccion(...)` con la fórmula compartida.
18. Listener que despacha el job al evento (o `ElectorCapturado` registrado en `EventServiceProvider`/auto-discovery con un listener encolado). Extraer la aritmética a un método compartido reusado por el comando de recálculo.
19. `RecalcularCoberturaCommand`: reemplazar `capturados = 0` por `Elector::where('seccion_id', $seccion->id)->count()` (tenant-scoped). Mantener idempotencia.
20. Tests: criterio 7 (capturar → cobertura refleja conteo; reprocesar job = mismo resultado, idempotente). Probar el job directo con `TenantContext` y vía `Event::fake()` que el evento se dispara al capturar.

### Bloque G — Aviso de privacidad (endpoint + seed)
21. `GET /api/avisos-privacidad/vigente` → `AvisoPrivacidad::vigente()` del tenant.
22. `DemoTenantSeeder` (o `AvisoPrivacidadSeeder` manual): crear un aviso vigente para el tenant demo, para captura end-to-end local.

### Bloque H — Rutas
23. `ResolveTenant` ya corre en el stack `web` global (verificado Sprint 2/3). Agrupar bajo `auth,verified` (cualquier rol con membership — la captura no usa `rol:`):
    - Lotería: `POST /api/loterias`, `POST /api/loterias/{loteria}/cerrar`, `GET /api/loterias/activa`.
    - Electores: `POST /api/electores`, `GET /api/electores/{elector}`, `GET /api/secciones/{seccion}/electores`.
    - Aviso: `GET /api/avisos-privacidad/vigente`.
    - Página: `GET /captura`.
24. Generar rutas tipadas con Wayfinder.

### Bloque I — Frontend Inertia/Vue
25. `resources/js/pages/Captura.vue` (mobile-first): toggle Lotería / Individual.
    - **Lotería**: selector de sección → `POST /api/loterias` → formulario rápido (nombre, teléfono, checkbox consentimiento) con contador en vivo; al montar, `GET /api/loterias/activa` retoma sesión; botón cerrar.
    - **Individual**: form completo (nombre, teléfono, domicilio, observaciones, GPS opcional vía `navigator.geolocation`, consentimiento).
    - Mostrar texto del aviso vigente (`GET /api/avisos-privacidad/vigente`); bloquear "Guardar" sin consentimiento.
    - Manejar 409 (duplicado) con aviso claro, 422 (validación) inline.

## Tests (TDD — por bloque)
- **Aislamiento/identidad (B/E)**: criterios 6, 10, 11.
- **Teléfono/dedup (C/E)**: criterios 5, 8.
- **Captura modos (E)**: criterios 1, 2, 3, 4.
- **Privacidad/cifrado (E)**: criterio 12.
- **Cobertura/evento (F)**: criterio 7 (+ idempotencia del job).
- **Lotería (D)**: criterio 9.
- Carpeta `tests/Feature/Captura/`. Usar fixture `tests/Fixtures/cartografia/99-test` para secciones con polígono real (necesario para `ST_Contains`).

## Decisiones y trampas a evitar
- **TenantContext en el job**: el job encolado NO hereda `currentTenant`. Setearlo desde `tenant_id` o el upsert escribe `tenant_id=null` y el scope filtra a cero. Cubrir con test del job directo.
- **`membership_id`, no `user_id`** en `electores`/`loterias` (CONTEXT). Resolver vía `User::membershipEn`. Sin membership → 403, nunca capturar huérfano.
- **Nunca `tenant_id` ni `rol` desde el request.**
- **Dedup en la acción, no constraint único** en DB: así se devuelve el existente con 409 en vez de romper con excepción de integridad.
- **Consentimiento obligatorio**: no persistir sin `consentimiento=true` + `aviso_privacidad_id` válido (ADR-004, CONTEXT).
- **No agregar en vivo** sobre `electores` para el mapa: el job mantiene `cobertura_seccion` (ADR-003). El endpoint del mapa de Sprint 3 no cambia.
- **SRID 4326** de punta a punta para `ubicacion` y `ST_Contains`. Reusar el cast `Point` del paquete espacial existente; no instalar nada nuevo (regla CONTEXT sobre dependencias).
- **`modo_captura` solo `loteria|individual`** este sprint; `evento_id` columna nullable sin FK.
- **Cifrado**: cast `encrypted` de Laravel sobre `telefono`/`domicilio`; el `telefono_hash` es separado (no descifrable) y es lo único indexado para dedup.

## Definición de Done
- Migraciones aplican en limpio: `avisos_privacidad`, `loterias`, `electores` con índices/cifrado.
- `Telefono` normaliza y hashea determinista; dedup por tenant funciona (409 con id existente).
- Captura Lotería e Individual persisten con `membership_id`, sección resuelta (explícita o GPS), consentimiento obligatorio.
- `ElectorCapturado` → `ActualizarCoberturaSeccion` deja `cobertura_seccion` con el conteo real, idempotente; `territori:recalcular-cobertura` cuenta electores reales.
- Endpoints de lotería, electores y aviso vigente, todos tenant-scoped; cross-tenant 404.
- Página `Captura.vue` mobile-first funcional para ambos modos, con aviso de privacidad y bloqueo por consentimiento.
- Suite verde: aislamiento, dedup, modos de captura, cifrado en reposo, idempotencia de cobertura, lotería única por brigadista. Suite completa sigue verde.
