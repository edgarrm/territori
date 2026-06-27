# Plan de Implementación — Brigadistas (Sprint 5)

> Acompaña a `specs/brigadistas.spec.md`. Laravel 13 + Vue 3/Inertia + PostgreSQL/PostGIS. Gestión de equipo: zonas (`brigadista_seccion`), ratios sobre `electores`, límite/facturación por brigadista activo. Reusa Sprint 2 (`memberships`, `Tenant::puedeActivarBrigadista`, `InvitarMiembro`, `Membership::activar/desactivar`) y Sprint 4 (`electores`, `membership_id`). No introducir paquetes nuevos.

## ⚠️ Trampas confirmadas leyendo el código (antes de codear)
1. **`Membership` NO usa `BelongsToTenant`** (intencional: se consulta antes de resolver el tenant). El aislamiento NO viene de un global scope → **filtrar `tenant_id` explícito** en toda query de brigadistas y del pivote.
2. **`brigadista_seccion` tiene PK compuesta** → mismo bug que `CoberturaSeccion` (Sprint 3) si se usa `save()`. Manejar SOLO con `belongsToMany`/`sync` o `DB::table`. Sin modelo Eloquent con `save()`.
3. **Resolución manual de `{membership}`** (no binding implícito): SubstituteBindings corre antes que ResolveTenant (Sprint 4). `Membership::query()->where('tenant_id', ...)->findOrFail($id)`.
4. **Límite de brigadistas ya existe**: `Tenant::puedeActivarBrigadista`, `Membership::activar` (lanza `RuntimeException`), `InvitarMiembro`. Reusar; el controlador captura la excepción → 422 (patrón `ElectorDuplicado`→409 de Sprint 4).
5. **Tenant en tests** se setea por sesión: `actingAs($user)->withSession(['tenant_id' => $tenant->id])`. Helper `tenantConMiembro` ya existe en los tests de mapa; replicar.

## Orden de construcción (incremental, TDD)

### Bloque A — Esquema
1. Migración `brigadista_seccion`: `tenant_id` (unsignedBigInteger, index), `membership_id` (FK→memberships, cascade), `seccion_id` (FK→secciones, cascade). `primary(['membership_id','seccion_id'])`. Sin `id`, sin timestamps.

### Bloque B — Modelo/relación + zonas
2. `Membership::secciones()` → `belongsToMany(Seccion::class, 'brigadista_seccion', 'membership_id', 'seccion_id')->withPivot('tenant_id')`. Helper `esBrigadista(): bool` (`$this->rol === 'brigadista'`).
3. `App\Actions\Brigadistas\AsignarZonas::handle(Membership $m, array $seccionIds): void`:
   - Validar `$m->rol === 'brigadista'` y `$m->tenant_id === TenantContext::get()->id` (si no, abort/exception).
   - Validar que cada `seccion_id` pertenece al `municipio_id` del tenant: `Seccion::whereIn('id', $ids)->where('municipio_id', $tenant->municipio_id)->count() === count(array_unique($ids))`; si no → `ValidationException`/422.
   - `sync` con payload tenant_id: `$m->secciones()->sync(collect($ids)->mapWithKeys(fn($id) => [$id => ['tenant_id' => $tenant->id]]))`.
4. Tests (`tests/Feature/Brigadistas/AsignarZonasTest.php`): criterios 4 (sync reemplaza, no acumula; lleva tenant_id), 5 (sección de otro municipio/tenant → 422, no persiste), 12 (aislamiento del pivote entre tenants).

### Bloque C — Ratios
5. `App\Support\Brigadistas\RatiosBrigadista` (o `App\Actions\Brigadistas\CalcularRatios`):
   - `paraTenant(Tenant): Collection` → una query agregada sobre `electores` `GROUP BY membership_id` con `COUNT(*) as capturas_total`, `COUNT(*) FILTER (WHERE created_at::date = CURRENT_DATE) as capturas_dia`, `COUNT(*) FILTER (WHERE telefono_hash IS NOT NULL) as completos`. Filtrar `tenant_id` explícito (usar `DB::table('electores')` con `where('tenant_id', $tenant->id)`, o `Elector` con TenantContext seteado). Secciones asignadas: agregado aparte sobre `brigadista_seccion`.
   - `paraMembership(Membership): array` → `{ capturas_dia, capturas_total, pct_completos, avance_meta, secciones_asignadas }`. `pct_completos = capturas_total ? completos/capturas_total : 0`. `avance_meta = meta_diaria ? capturas_dia/meta_diaria : null`.
6. Tests (`tests/Feature/Brigadistas/RatiosTest.php`): criterios 2 (avance_meta con/sin meta_diaria; sin división por cero), 3 (pct_completos con T=0 → 0; con K/T). Usar `ElectorFactory` con `created_at` controlado (hoy vs ayer) y estados con/sin `telefono_hash`.

### Bloque D — Endpoints de gestión + facturación
7. `App\Http\Controllers\BrigadistaController`:
   - `index()` (Inertia `Brigadistas`): memberships rol=brigadista del tenant (filtrar `tenant_id`) + ratios (Bloque C) + `facturacion = { activos: $tenant->brigadistasActivosCount(), limite: $tenant->limite_brigadistas, puede_activar: $tenant->puedeActivarBrigadista() }`.
   - `store(StoreBrigadistaRequest)`: `InvitarMiembro` con `rol=brigadista`. Capturar `RuntimeException` de límite → 422 `{ message }`.
   - `activo(Request, string $membership)`: resolver manual (`Membership::query()->where('tenant_id',...)->where('rol','brigadista')->findOrFail`). `{activo:bool}` → `activar()` (captura RuntimeException → 422) o `desactivar()`.
   - `zonas(Request, string $membership)`: `{seccion_ids:int[]}` → `AsignarZonas`. ValidationException → 422.
   - `ratios(string $membership)`: resolver manual → `RatiosBrigadista::paraMembership`.
8. `App\Http\Requests\StoreBrigadistaRequest`: `email` required email, `name` nullable, `meta_diaria` nullable int min:0, `activo` boolean.
9. Tests (`tests/Feature/Brigadistas/GestionBrigadistasTest.php`): criterios 1 (index solo brigadistas del tenant), 6 (activar al tope → 422, sigue inactivo), 7 (desactivar libera cupo), 9 (membership de otro tenant → 404), 10 (rol brigadista → 403 en gestión), 11 (alta crea/reusa user).

### Bloque E — `brigadistas_activos` en el mapa
10. `MapaController::resumenSeccion`: poblar `brigadistas_activos` = `DB::table('brigadista_seccion')` ⨝ `memberships` (activo=true, rol=brigadista) ⨝ `users`, where `seccion_id` y `tenant_id` → `[{ membership_id, nombre }]`. (Opcional `capturas_dia`; mínimo viable `{membership_id, nombre}`.) `ultimo_registro`: si es barato, `Elector::where('seccion_id',...)->latest()->value('created_at')`; si no, dejar null.
11. Tests (extender `tests/Feature/MapaCobertura/` o nuevo `tests/Feature/Brigadistas/ResumenMapaTest.php`): criterio 8 (activo+asignado aparece; inactivo o no-asignado no aparece). Ajustar el assert existente en `MapaCoberturaEndpointsTest` que espera `brigadistas_activos: []` si rompe (ahora puede traer datos cuando hay asignaciones; el caso sin asignaciones sigue `[]`).

### Bloque F — Rutas
12. `routes/web.php`, dentro de `auth,verified`, grupo `rol:coordinador,admin`:
    - `GET /brigadistas` → `index`
    - `POST /brigadistas` → `store`
    - `PUT /api/brigadistas/{membership}/activo` → `activo`
    - `PUT /api/brigadistas/{membership}/zonas` → `zonas`
    - `GET /api/brigadistas/{membership}/ratios` → `ratios`
13. Generar rutas tipadas con Wayfinder (`php artisan wayfinder:generate` o vía build).

### Bloque G — Frontend Inertia/Vue
14. `resources/js/pages/Brigadistas.vue` (coordinador/admin, desktop-first):
    - Banner facturación: `activos / limite` (verde con cupo, ámbar al tope), mensaje upsell.
    - Tabla: nombre/email, toggle activo, meta_diaria, capturas_dia, avance_meta (barra), pct_completos, # secciones. Toggle activar maneja 422 (upsell) sin romper.
    - Form "Agregar brigadista" (email, nombre, meta_diaria) → `POST /brigadistas`.
    - Modal/panel de zonas: lista de secciones del municipio (reusar patrón de `Metas.vue`), guarda `PUT .../zonas`.
15. `DemoTenantSeeder`: asignar algunas secciones a los brigadistas demo (`brigadista_seccion`) para datos end-to-end.

## Tests (TDD — por bloque)
- **Zonas (B)**: criterios 4, 5, 12. `tests/Feature/Brigadistas/AsignarZonasTest.php`.
- **Ratios (C)**: criterios 2, 3. `tests/Feature/Brigadistas/RatiosTest.php`.
- **Gestión/facturación (D)**: criterios 1, 6, 7, 9, 10, 11. `tests/Feature/Brigadistas/GestionBrigadistasTest.php`.
- **Resumen mapa (E)**: criterio 8. Extiende `MapaCobertura/` o nuevo archivo.
- Reusar `tenantConMiembro` (patrón existente), `MembershipFactory` (`brigadista()`/`coordinador()`), `ElectorFactory`, `SeccionFactory`, `UserFactory`. Para ratios, `created_at` controlado.

## Decisiones y trampas a evitar (resumen)
- **`tenant_id` explícito** en todas las queries de `memberships`/`brigadista_seccion` (Membership no tiene global scope).
- **Nunca `save()` sobre `brigadista_seccion`** (PK compuesta): solo `sync`/`DB::table`.
- **Resolución manual de `{membership}`** con `where('tenant_id')` + `findOrFail` → 404 cross-tenant.
- **Reusar la mecánica de límite** (`puedeActivarBrigadista`/`activar`/`InvitarMiembro`); controlador captura `RuntimeException` → 422.
- **Zonas NO restringen captura** (decisión validada): no tocar `CapturarElector`.
- **`pct_completos` = telefono_hash != null** (decisión validada).
- **Facturación solo conteo+límite+bloqueo**: sin tabla de snapshot histórico (decisión validada).
- **Ratios sin N+1**: agregación `GROUP BY membership_id`, no query por fila.
- **`brigadistas_activos`**: solo activos Y asignados a la sección; inactivo o no-asignado no aparece.

## Definición de Done
- Migración `brigadista_seccion` aplica en limpio (PK compuesta, con `tenant_id`).
- `Membership::secciones()` + `AsignarZonas` (valida municipio, sync con tenant_id, idempotente).
- Ratios correctos sin división por cero, en una query agregada por tenant.
- Endpoints `GET/POST /brigadistas`, `PUT activo`, `PUT zonas`, `GET ratios`, todos `rol:coordinador,admin`, tenant-scoped, cross-tenant 404, límite → 422 con upsell.
- `brigadistas_activos` poblado en el resumen del mapa (activos+asignados).
- `Brigadistas.vue` funcional: tabla con ratios, toggle activo, banner de límite, asignación de zonas. Seed demo con zonas.
- Suite verde: zonas, ratios, gestión/facturación, aislamiento cross-tenant, autorización por rol, resumen del mapa. Suite completa sigue verde (123 + nuevos).
