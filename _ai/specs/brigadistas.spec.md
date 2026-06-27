# Spec — Brigadistas: gestión, zonas, ratios y límite/facturación

- **Feature ID**: P4 (facturación por brigadista activo) · gestión de equipo · asignación de zonas (`brigadista_seccion`) · cierra el `brigadistas_activos:[]` pendiente del resumen del mapa (Sprint 3)
- **Estado**: Implementado (Sprint 5)
- **Depende de**: ADR-001 (multi-tenancy), ADR-005 (white-label + facturación por brigadista activo), `_ai/docs/data-model.md` (`memberships`, `brigadista_seccion`, `electores`), `_ai/docs/api-contract.md` (sección Brigadistas). Reusa lo de Sprint 2 (`memberships`, `Tenant::puedeActivarBrigadista`, `InvitarMiembro`, `Membership::activar/desactivar`) y Sprint 4 (`electores`, `membership_id`).

## Objetivo
Dar al **coordinador/admin** la gestión del equipo de campo: ver la lista de brigadistas con sus ratios de productividad/calidad/avance, darlos de alta, activarlos/desactivarlos (lo que impacta facturación), y **asignarles zonas** (secciones) para organizar el esfuerzo territorial. Además, poblar `brigadistas_activos` en el resumen de sección del mapa (hoy `[]`) y exponer el conteo de brigadistas activos vs. el límite del plan con bloqueo/upsell.

## Por qué
La cobertura del mapa (Sprint 3) y la captura (Sprint 4) ya viven, pero no hay forma de gestionar **quién** captura ni de medir su rendimiento. La asignación de zonas convierte el mapa rojo/verde en acción (reasignar brigadistas a secciones rezagadas), que es la promesa de venta del producto (analítica territorial + gestión de brigadistas). La facturación por brigadista activo (ADR-005) es el modelo de negocio; esta es la pantalla donde el coordinador siente el límite del plan.

## Decisiones tomadas (validadas antes de codear)
1. **Las zonas NO restringen la captura.** `brigadista_seccion` es organizativa/visual: define a quién se le asigna esfuerzo, alimenta ratios y la columna `brigadistas_activos` del mapa, pero la captura de Sprint 4 sigue libre (un brigadista puede capturar en cualquier sección del municipio). Se mantiene la regla de Sprint 4 sin cambios.
2. **`pct_completos` (calidad) = electores con teléfono válido.** Una captura cuenta como "completa" si tiene `telefono_hash` no nulo (teléfono normalizado a 10 dígitos MX). Alineado con el dedup existente; no penaliza el flujo rápido de lotería.
3. **Facturación: solo conteo + límite + bloqueo.** Se expone "brigadistas activos / límite del plan" y se respeta el bloqueo (`puedeActivarBrigadista`, ya existe) con mensaje de upsell. La **política exacta de cobro** (pico mensual vs. promedio vs. corte) y cualquier snapshot histórico quedan **fuera de alcance** (decisión de producto pendiente, ver CONTEXT.md).

## Alcance

### Incluye

**Migraciones**
- `brigadista_seccion` (pivote de asignación de zonas, per data-model): `tenant_id` (bigint), `membership_id` (FK→memberships), `seccion_id` (FK→secciones). **PK compuesta `(membership_id, seccion_id)`.** Índice `(tenant_id)` para el scope. Sin `id` autoincremental, sin timestamps (pivote puro). Como es tenant-scoped, lleva `tenant_id` para que el global scope filtre.

> ⚠️ **No crear un modelo Eloquent con `save()` sobre PK compuesta** (mismo bug que `CoberturaSeccion` en Sprint 3). La escritura se hace por `sync()`/`attach()`/`detach()` desde la relación `belongsToMany` del `Membership`, o por inserción explícita en transacción. Nunca `Model::save()` de instancia sobre esta tabla.

**Modelos**
- `App\Models\Membership`: añadir relación `secciones()` → `belongsToMany(Seccion, 'brigadista_seccion', 'membership_id', 'seccion_id')`. El `tenant_id` del pivote se setea explícito en `sync` con `withPivotValue`/payload (no se confía en el cliente). Helpers: `asignarSecciones(array $seccionIds)` (sync que valida que las secciones pertenezcan al municipio del tenant y setea `tenant_id`), `esBrigadista(): bool`.
- `App\Models\Seccion`: opcional relación inversa `brigadistas()` si se necesita en queries; añadir solo si un test la usa.

**Lógica de dominio**
- `App\Actions\Brigadistas\AsignarZonas`: recibe `Membership` + `array $seccionIds`; valida que la membership sea del tenant activo y rol `brigadista`, que cada sección pertenezca al `municipio_id` del tenant (rechaza secciones de otro municipio → 422), y hace `sync` seteando `tenant_id`. Idempotente.
- `App\Support\Brigadistas\RatiosBrigadista` (o un método en una acción `CalcularRatios`): dado un `Membership` (rol brigadista) y opcional rango de fechas (default: hoy), calcula:
  - `capturas_dia`: `COUNT(electores)` del brigadista con `created_at::date = hoy` (tenant-scoped, por `membership_id`).
  - `capturas_total`: total histórico del brigadista.
  - `pct_completos`: `capturas con telefono_hash != null / capturas_total` (0 si no hay capturas). Calidad.
  - `avance_meta`: `capturas_dia / meta_diaria` (null si `meta_diaria` es null; no divide por cero).
  - `secciones_asignadas`: `COUNT(brigadista_seccion)` del brigadista.
  - Una sola query agregada por brigadista para la lista (evitar N+1: agregación `GROUP BY membership_id` sobre `electores`, no un query por fila).
- Reusar `App\Actions\Campana\InvitarMiembro` para el alta (ya valida límite). El alta de brigadista desde esta pantalla es `InvitarMiembro` con `rol=brigadista`.

**Endpoints** (todos tenant-scoped; gestión restringida a `rol:coordinador,admin` salvo nota)
- `GET /brigadistas` — página Inertia: lista de memberships `rol=brigadista` del tenant con ratios agregados (capturas_dia, capturas_total, pct_completos, avance_meta, secciones_asignadas, activo) + resumen de facturación `{ activos, limite, puede_activar }`.
- `POST /brigadistas` — alta: `{ email, name?, meta_diaria?, activo? }` → `InvitarMiembro` con `rol=brigadista`. Valida `limite_brigadistas`; si se excede al activar → 422 con mensaje de upsell.
- `PUT /api/brigadistas/{membership}/activo` — `{ activo: bool }`: activa (`Membership::activar`, respeta límite → 422 si excede) o desactiva (`desactivar`). Registra `activado_en`/`desactivado_en` (ya implementado en el modelo).
- `PUT /api/brigadistas/{membership}/zonas` — `{ seccion_ids: int[] }` → `AsignarZonas`. 422 si alguna sección no es del municipio del tenant.
- `GET /api/brigadistas/{membership}/ratios` — `{ capturas_dia, capturas_total, pct_completos, avance_meta, secciones_asignadas }` del brigadista.
- **Resumen del mapa**: en `MapaController::resumenSeccion`, poblar `brigadistas_activos` con los brigadistas **activos asignados a esa sección** (`brigadista_seccion` ⨝ `memberships` activos): `[{ membership_id, nombre, capturas_dia }]`. `ultimo_registro` sigue fuera de alcance (queda como está o se llena con el `created_at` del último elector de la sección — ver Notas).

> **Resolución de `{membership}` tenant-scoped**: ⚠️ `Membership` **NO** usa `BelongsToTenant` (es intencional: se consulta para resolver el tenant mismo, antes de que haya `TenantContext`). Por eso el aislamiento NO lo da un global scope — hay que **filtrar `tenant_id` explícito**. Resolver MANUALMENTE en el controlador: `Membership::query()->where('tenant_id', TenantContext::get()->id)->where('rol','brigadista')->findOrFail($id)`. Nunca por binding implícito (SubstituteBindings corre antes que ResolveTenant). Un `{membership}` de otro tenant → 404 por el `where('tenant_id')`. Lo mismo para `brigadista_seccion`: filtrar `tenant_id` a mano en todas las queries del pivote.

**Frontend (Inertia + Vue)**
- `GET /brigadistas` página `Brigadistas.vue` (coordinador/admin):
  - Tabla de brigadistas: nombre/email, activo (toggle), meta_diaria, capturas_dia, avance_meta (barra), pct_completos, # secciones asignadas.
  - Banner de facturación: "X / Y brigadistas activos" con estado (verde si hay cupo, ámbar/bloqueo si está al tope) y mensaje de upsell.
  - Acción "Agregar brigadista" (form: email, nombre, meta_diaria).
  - Toggle activar/desactivar por fila (PUT activo); si el toggle de activar choca con el límite, mostrar el mensaje 422 (upsell), no romper.
  - Asignación de zonas: modal/panel para elegir secciones del municipio (lista de secciones por número) y guardar (PUT zonas). Reusar el patrón de selección de `Metas.vue`.
- `DemoTenantSeeder`: asignar algunas secciones a los brigadistas demo (`brigadista_seccion`) para que la pantalla y el resumen del mapa muestren datos end-to-end.

### No incluye
- **Restricción de captura por zona** (decisión 1: zonas no restringen). La captura de Sprint 4 no cambia.
- **Snapshot/histórico de facturación** (pico mensual, cobro): solo conteo en vivo + bloqueo (decisión 3). Tabla de billing fuera de alcance.
- **Jerarquía de coordinadores** (ADR/CONTEXT: un solo nivel, sin `parent_id`).
- **Interacciones/timeline, agenda, export** (Sprint 6). Los ratios se calculan solo sobre `electores`, no sobre `interacciones` (que aún no existen). `pct_completos` es por completitud de datos del elector, no por calidad de contacto.
- **Edición/borrado de memberships** (`DELETE`): un brigadista se desactiva, no se borra (trazabilidad de cobro). Sin endpoint de borrado.
- **Reasignación masiva / sugerencias automáticas** de zonas (el mapa muestra rezago; la reasignación inteligente es producto futuro).

## Criterios de aceptación (tests)
1. Dado un coordinador, cuando hace `GET /brigadistas`, entonces ve solo las memberships `rol=brigadista` de **su** tenant (no coordinadores/admins, no de otros tenants) con sus ratios.
2. Dado un brigadista con N electores capturados hoy y meta_diaria M, cuando se calculan ratios, entonces `capturas_dia=N` y `avance_meta=N/M`; con `meta_diaria=null`, `avance_meta` es null (no divide por cero).
3. Dado un brigadista con K electores con `telefono_hash` y T totales, cuando se calculan ratios, entonces `pct_completos=K/T`; con T=0, `pct_completos=0` (sin división por cero).
4. Dado `PUT /api/brigadistas/{membership}/zonas` con `seccion_ids` válidas del municipio, cuando se asignan, entonces `brigadista_seccion` queda exactamente con esas secciones (sync: re-enviar una lista distinta reemplaza, no acumula) y cada fila lleva el `tenant_id` correcto.
5. Dado `seccion_ids` que incluye una sección de **otro municipio** (o de otro tenant), cuando se intenta asignar, entonces 422 y no se persiste ninguna asignación.
6. Dado un tenant con `limite_brigadistas=L` y L brigadistas activos, cuando se intenta activar otro (`PUT .../activo {activo:true}` o alta con `activo:true`), entonces 422 con mensaje de upsell y el brigadista queda inactivo; el conteo de activos sigue en L.
7. Dado un brigadista activo, cuando se hace `PUT .../activo {activo:false}`, entonces queda `activo=false`, `desactivado_en` seteado, y libera cupo (un siguiente activar tiene éxito).
8. Dado `GET /api/secciones/{seccion}/resumen` con brigadistas **activos asignados** a esa sección, entonces `brigadistas_activos` lista esos brigadistas (membership_id, nombre); un brigadista **inactivo** o **no asignado** a la sección no aparece.
9. Dado un brigadista de otro tenant, cuando un coordinador hace `PUT /api/brigadistas/{membership}/...`, entonces 404 (global scope; resolución manual).
10. Dado un usuario con rol `brigadista` (no coordinador/admin), cuando entra a `GET /brigadistas` o a los `PUT` de gestión, entonces 403 (middleware `rol:coordinador,admin`).
11. Dado el alta `POST /brigadistas {email, meta_diaria}`, cuando el email no existe, entonces se crea el `user` y su `membership` rol=brigadista en el tenant; si el email ya es user, se reusa (no duplica user) — patrón `InvitarMiembro`.
12. Dado dos tenants con el mismo brigadista asignado a secciones, cuando cada coordinador lista zonas/ratios, entonces solo ve las asignaciones de su tenant (aislamiento; el pivote lleva `tenant_id`).

## Notas de implementación
- **Pivote tenant-scoped sin modelo.** `brigadista_seccion` se maneja vía la relación `belongsToMany` de `Membership`. Para que cada fila lleve `tenant_id`, usar `sync` con payload: `$membership->secciones()->sync(collect($ids)->mapWithKeys(fn($id) => [$id => ['tenant_id' => $tenantId]]))`. Declarar `->withPivot('tenant_id')` en la relación. No crear `App\Models\BrigadistaSeccion` con `save()` (PK compuesta → mismo bug que `CoberturaSeccion`). Si se necesita query directa, usar `DB::table('brigadista_seccion')` con `where('tenant_id', ...)` explícito.
- **Validación de municipio en zonas.** Las `seccion_ids` deben pertenecer al `municipio_id` del tenant. Verificar con `Seccion::whereIn('id', $ids)->where('municipio_id', $tenant->municipio_id)->count() === count(array_unique($ids))`; si no cuadra → 422. Esto también ataja secciones de otro municipio/tenant.
- **Ratios sin N+1.** Para la lista de `GET /brigadistas`, calcular los agregados con UNA query: `electores` `GROUP BY membership_id` con `COUNT(*)`, `COUNT(*) FILTER (WHERE created_at::date = today)`, `COUNT(*) FILTER (WHERE telefono_hash IS NOT NULL)` (Postgres soporta `FILTER`). Mapear por `membership_id` en memoria. Secciones asignadas: `GROUP BY membership_id` sobre `brigadista_seccion`. Respetar tenant scope (los modelos lo aplican; si se usa `DB::table`, filtrar `tenant_id` a mano).
- **`brigadistas_activos` en el resumen del mapa.** Query: `brigadista_seccion` ⨝ `memberships` (activo=true, rol=brigadista) ⨝ `users` (nombre), filtrado por `seccion_id` y `tenant_id`. Opcionalmente añadir `capturas_dia` por brigadista en esa sección (puede diferir al alcance si complica; mínimo viable = `{membership_id, nombre}`). Actualizar el contrato del endpoint en `api-contract.md` si cambia la forma.
- **`ultimo_registro`.** Hoy es `null`. Si es barato, llenarlo con el `created_at` del último `Elector` de la sección (tenant-scoped). Si añade complejidad, dejar `null` y anotarlo (no es objetivo del sprint).
- **Reuso de `InvitarMiembro` y `Membership::activar`.** Ya validan `puedeActivarBrigadista`. El controlador captura el `RuntimeException` de límite y responde 422 con el mensaje (igual que el patrón de `ElectorDuplicado` → 409 en Sprint 4). No duplicar la lógica de límite.
- **Frontend.** `Brigadistas.vue` mobile-friendly pero pensado para coordinador (puede ser desktop-first, a diferencia de captura). Reusar componentes UI existentes y el patrón de tabla de `Metas.vue`. La selección de secciones para zonas reusa la lista de secciones del municipio (mismo origen que `Metas.vue`).
- **Tests.** Carpeta `tests/Feature/Brigadistas/`. Reusar `TenantFactory`, `MembershipFactory`, `UserFactory`, `ElectorFactory`, `SeccionFactory`/fixture `99-test`. Cubrir: ratios (con/sin meta, pct_completos con T=0), zonas (sync reemplaza, municipio ajeno → 422), límite/facturación (activar al tope → 422, desactivar libera), aislamiento cross-tenant del pivote, autorización por rol, y el `brigadistas_activos` del resumen del mapa (extiende `tests/Feature/MapaCobertura/`). Para los ratios usar `ElectorFactory` con `created_at` controlado (hoy vs ayer).
