# Plan de Implementación — Interacciones + Agenda + Edición (Sprint 6)

> Acompaña a `specs/interacciones-agenda.spec.md`. Laravel 13 + Vue 3/Inertia + PostgreSQL. Construye sobre Sprint 4 (`electores`, `CapturarElector`, `Telefono`, `ElectorDuplicado`). No introducir paquetes nuevos.

## ⚠️ Trampas confirmadas (antes de codear)
1. **Resolución manual de modelos tenant-scoped** (`Elector`, `Interaccion`): `Model::query()->findOrFail($id)` en el controlador, NUNCA binding implícito (SubstituteBindings corre antes que ResolveTenant — Sprint 4). Rutas usan `{elector}`/`{interaccion}` como string.
2. **`membership_id` SIEMPRE del servidor**: `User::membershipEn(TenantContext::get())`. Nunca del request.
3. **`interacciones` sin `updated_at`**: data-model solo define `created_at`. Modelo con `const UPDATED_AT = null` (o `$timestamps=false` + `created_at` manual). Usar `created_at` automático configurando `const UPDATED_AT = null`.
4. **`atendido_en` no está en el data-model original**: lo agregamos (decisión validada). Actualizar `_ai/docs/data-model.md` al cerrar.
5. **Agenda por rol**: rol desde la membership activa, no `users`. brigadista → filtra `membership_id`; coordinador/admin → todo el tenant.
6. **Dedup en edición**: reusar `Telefono::hash` + `ElectorDuplicado`, excluyendo el propio elector (`where('id','!=',$elector->id)`).
7. **Tenant en tests**: `actingAs($user)->withSession(['tenant_id' => $tenant->id])`; helper `setupCampana` (patrón de `CapturaElectorTest`).

## Orden de construcción (incremental, TDD)

### Bloque A — Esquema + modelo + factory
1. Migración `interacciones`: `id`, `tenant_id` FK→tenants cascade, `elector_id` FK→electores cascade, `membership_id` FK→memberships, `tipo` string(12), `resultado` string(16) nullable, `nota` text nullable, `fecha` timestamptz, `proximo_seguimiento` date nullable, `atendido_en` timestamptz nullable, `timestamp('created_at')` (sin updated_at). Índices `(tenant_id,elector_id)`, `(tenant_id,membership_id,proximo_seguimiento)`.
2. `App\Models\Interaccion` con `BelongsToTenant`, `const UPDATED_AT = null`, casts (`fecha`/`atendido_en` datetime, `proximo_seguimiento` date), relaciones `elector()`/`membership()`, scope `pendientes()`.
3. `App\Models\Elector::interacciones()` hasMany ordenado desc por `fecha`.
4. `InteraccionFactory` (tenant/elector/membership por factory; `tipo=llamada`, `resultado=contesto`, `fecha=now`).

### Bloque B — Registrar interacción
5. `App\Actions\Interacciones\RegistrarInteraccion::handle(Elector, array $datos, Membership): Interaccion`. Reglas: nota⇒resultado null; fecha default now.
6. `App\Http\Requests\StoreInteraccionRequest`: `authorize` (membership en tenant, igual que StoreElectorRequest), `rules` (`tipo` in enum; `resultado` nullable in enum + regla nota⇒sin resultado; `nota` nullable; `fecha` nullable date; `proximo_seguimiento` nullable date).

### Bloque C — Edición de elector
7. `App\Actions\Electores\ActualizarElector::handle(Elector, array $datos): Elector`. Re-hash si cambia telefono; colisión con otro → `ElectorDuplicado`.
8. `App\Http\Requests\UpdateElectorRequest`: `authorize` (membership) + `rules` (nombre/telefono/domicilio/observaciones; telefono valida normalizar()).

### Bloque D — Controladores + rutas
9. `App\Http\Controllers\InteraccionController`: `indexPorElector`, `store`, `atendido`, `agenda`. Resolución manual; rol desde membership para agenda.
10. `ElectorController::update` (reusa ActualizarElector; captura ElectorDuplicado → 409) y `ElectorController::page` (Inertia ficha, opcional) — o página en controlador propio.
11. Rutas en `routes/web.php` dentro de `auth,verified` (cualquier rol con membership):
    - `GET /api/electores/{elector}/interacciones`
    - `POST /api/electores/{elector}/interacciones`
    - `PUT /api/interacciones/{interaccion}/atendido`
    - `GET /api/agenda` (+ `GET /agenda` Inertia)
    - `PUT /api/electores/{elector}`
    - `GET /electores/{elector}` (Inertia ficha)

### Bloque E — Frontend
12. `resources/js/pages/Agenda.vue`: lista de pendientes (nombre, sección, fecha seguimiento, canal), marcar atendido (optimista), link a ficha. Empty state. Mobile-first.
13. `resources/js/pages/Elector.vue`: ficha editable + timeline + form de nueva interacción (tipo, resultado condicional, nota, proximo_seguimiento).
14. Sidebar (`AppSidebar.vue`): entrada "Agenda" para todos los roles con membership.
15. Wayfinder: `php artisan wayfinder:generate` (o build) para rutas tipadas.
16. `DemoCapturasSeeder` (o nuevo): sembrar algunas interacciones con `proximo_seguimiento` (vencidos y futuros) para datos end-to-end.

## Tests (TDD — `tests/Feature/Interacciones/`)
- `RegistrarInteraccionTest.php`: criterios 1, 2, 3, 4, 12.
- `AgendaTest.php`: criterios 5, 6, 7, 11.
- `EditarElectorTest.php`: criterios 9, 10.
- `AislamientoInteraccionesTest.php`: criterio 8 (cross-tenant 404 en interacciones/agenda/edición).
- Reusar `setupCampana` (patrón `CapturaElectorTest`), `ElectorFactory`, `MembershipFactory`. Crear `InteraccionFactory`.

## Definición de Done
- Migración `interacciones` aplica en limpio (con `atendido_en`, sin updated_at).
- Timeline desc, agenda por rol con `atendido_en`, edición con re-hash/dedup, todo tenant-scoped y cross-tenant 404.
- `Agenda.vue` + `Elector.vue` funcionales; sidebar con Agenda; seed demo con interacciones.
- Suite verde (138 + nuevos), Larastan limpio, Pint limpio.
- `_ai/docs/data-model.md` actualizado con `atendido_en`; `_ai/CONTEXT.md` marca Sprint 6 completo.
