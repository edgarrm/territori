# Spec — Interacciones (timeline) + Agenda de seguimientos + Edición de elector

- **Feature ID**: Sprint 6 — Interacciones + agenda
- **Estado**: En implementación (Sprint 6)
- **Depende de**: ADR-001 (multi-tenancy), ADR-004 (privacidad), `_ai/docs/data-model.md` (`interacciones`, `electores`), `_ai/docs/api-contract.md` (Interacciones, Electores PUT). Construye sobre Sprint 4 (`electores`, `CapturarElector`) y reusa el patrón anti-binding de Sprint 4 (resolver modelos tenant-scoped manual en el controlador).

## Objetivo
Cerrar el **lazo de seguimiento** que vuelve útil la captura: cada elector tiene un **timeline de interacciones** (`tipo` = canal, `resultado` = qué pasó, separados), un seguimiento puede agendarse (`proximo_seguimiento`), y una **agenda diaria** lista los seguimientos vencidos no atendidos. Además se permite **editar** los datos de un elector y su nota fija.

## Por qué
Sin seguimiento, la captura del Sprint 4 es una foto muerta. Las interacciones convierten a Territori en una herramienta de trabajo diario del brigadista (a quién llamo hoy) y dan al coordinador visibilidad del esfuerzo de contacto. Es el penúltimo sprint del plan de construcción.

## Decisiones validadas (este sprint)
1. **"Atendido" es explícito**: `interacciones.atendido_en` (timestamptz NULL). Un seguimiento se marca atendido vía endpoint dedicado, no se deriva de la existencia de interacciones más nuevas. Permite posponer/cerrar sin forzar otra interacción.
2. **Alcance de la agenda por rol**: el **brigadista** ve solo sus seguimientos (su `membership_id`); **coordinador/admin** ven **todos** los del tenant. La consulta filtra por rol de la membership activa.

## Alcance

### Incluye

**Migración**
- `interacciones` (per data-model): `tenant_id`, `elector_id` FK→electores, `membership_id` FK→memberships, `tipo` varchar(12) (`llamada|correo|visita|whatsapp|sms|nota`), `resultado` varchar(16) NULL (`contesto|no_contesto|buzon|correo_enviado|no_estaba|rechazo|compromiso`; NULL para `nota`), `nota` text NULL, `fecha` timestamptz, `proximo_seguimiento` date NULL, **`atendido_en` timestamptz NULL** (decisión 1; no está en el data-model original → actualizar data-model.md al cerrar el sprint), `created_at` timestamptz.
  - Índices: `(tenant_id, elector_id)` para el timeline; `(tenant_id, membership_id, proximo_seguimiento)` para la agenda.

**Modelos**
- `App\Models\Interaccion` con `BelongsToTenant`. `$table = 'interacciones'`. Casts: `fecha`/`atendido_en` → datetime, `proximo_seguimiento` → date. Relaciones `elector()`, `membership()`. Sin `updated_at` (la tabla solo tiene `created_at`; `public $timestamps = false` o `const UPDATED_AT = null`, seguir el patrón mínimo). Helper de scope `pendientes()` (= `proximo_seguimiento <= today AND atendido_en IS NULL`).
- `App\Models\Elector`: añadir relación `interacciones()` (hasMany, orden desc por `fecha`).

**Lógica de dominio**
- `App\Actions\Interacciones\RegistrarInteraccion`: recibe `Elector` + datos validados + `Membership` (resuelta del usuario en el tenant, NUNCA del request). Reglas:
  - `tipo=nota` ⇒ `resultado` debe ser NULL; cualquier otro `tipo` permite `resultado` (validado contra el enum, opcional).
  - `fecha` por defecto `now()` si no viene.
  - Crea la `Interaccion` con `atendido_en=null`.
- `App\Actions\Electores\ActualizarElector`: edita `nombre`, `telefono`, `domicilio`, `observaciones`. Si cambia `telefono`: re-normaliza con `App\Support\Telefono`, recalcula `telefono_hash`; si el nuevo hash choca con **otro** elector del tenant ⇒ `ElectorDuplicado` (409). No toca `seccion_id`/`membership_id`/`consentimiento`/`aviso_privacidad_id`.
- Marcar atendido: método en la acción o directo en el controlador (`atendido_en = now()`), idempotente (marcar dos veces no falla; conserva la primera marca o la refresca — conservar la primera).

**Endpoints** (todos tenant-scoped; resolución manual de modelos tenant-scoped — patrón Sprint 4)
- `GET /api/electores/{elector}/interacciones` — timeline, orden desc por `fecha`. (Cualquier rol con membership.)
- `POST /api/electores/{elector}/interacciones` — body `{ tipo, resultado?, nota?, fecha?, proximo_seguimiento? }` → 201 con la interacción creada. 422 si `tipo=nota` con `resultado`, o enums inválidos.
- `PUT /api/interacciones/{interaccion}/atendido` — marca `atendido_en=now()` → 200. (Saca el seguimiento de la agenda.)
- `GET /api/agenda` — seguimientos vencidos no atendidos (`proximo_seguimiento <= hoy AND atendido_en IS NULL`), orden asc por `proximo_seguimiento`. Brigadista → solo los suyos; coordinador/admin → todos los del tenant (decisión 2). Cada item incluye datos mínimos del elector (id, nombre, seccion_id) y de la interacción.
- `PUT /api/electores/{elector}` — edita datos/nota fija → 200 con la ficha; 409 si el nuevo teléfono colisiona con otro elector del tenant; 422 validación.

**Frontend (Inertia + Vue, mobile-first)**
- `GET /agenda` — página `Agenda.vue`: lista de pendientes del día (nombre, sección, canal sugerido, fecha del seguimiento), botón "marcar atendido" (optimista) y acceso a la ficha del elector. Empty state cuando no hay pendientes.
- Ficha de elector con **timeline**: página `GET /electores/{elector}` (`Elector.vue`) que muestra datos del elector (editables: nombre/teléfono/domicilio/observaciones), el timeline de interacciones y un formulario para registrar una nueva interacción (selector de `tipo`, `resultado` condicional, nota, `proximo_seguimiento`). Tomar `territori_demo.html` como referencia visual de la ficha + timeline, no portar tal cual.
- Enlace a la ficha desde el panel/ficha de sección del mapa (la lista de electores de la sección ya existe vía `GET /api/secciones/{seccion}/electores`).
- Entrada de "Agenda" en el sidebar para roles con membership (todos), igual que Captura.

### No incluye
- **Eventos** (modo captura `evento` + tabla `eventos` + FK pendiente de `electores.evento_id`) → Sprint 7.
- **ARCO / borrado real** `DELETE /api/electores/{elector}` y `solicitudes_arco` → Sprint 7. (La edición SÍ entra aquí; el borrado no.)
- **Export CSV** → Sprint 7.
- Notificaciones/recordatorios push de la agenda (fuera de alcance).
- Adjuntos en interacciones, geolocalización de la interacción, o métricas de contacto en los ratios del brigadista (Sprint 5 no se modifica).

## Criterios de aceptación (tests)
1. Dado un elector del tenant, cuando se hace `POST /api/electores/{elector}/interacciones` con `tipo=llamada, resultado=contesto`, entonces se crea la interacción con `membership_id` del usuario autenticado (no del request), `fecha` poblada y aparece en el timeline.
2. Dado `tipo=nota` con un `resultado` presente, cuando se registra, entonces 422 (nota no lleva resultado).
3. Dado `tipo=llamada` sin `fecha`, cuando se registra, entonces `fecha` toma `now()`.
4. Dado `GET /api/electores/{elector}/interacciones`, entonces devuelve el timeline en orden **descendente** por `fecha`.
5. Dada una interacción con `proximo_seguimiento <= hoy` y `atendido_en=null` del brigadista, cuando el brigadista pide `GET /api/agenda`, entonces aparece; cuando la marca atendida (`PUT .../atendido`), entonces deja de aparecer.
6. Dado un seguimiento con `proximo_seguimiento` **futuro**, cuando se pide la agenda, entonces **no** aparece.
7. Dado un coordinador, cuando pide `GET /api/agenda`, entonces ve los seguimientos pendientes de **todos** los miembros del tenant; dado un brigadista, solo los **suyos** (decisión 2).
8. Dado un elector de **otro tenant**, cuando se intenta `POST .../interacciones`, `GET .../interacciones` o `PUT /api/electores/{id}`, entonces 404 (global scope; aislamiento patrón `BelongsToTenantTest`).
9. Dado `PUT /api/electores/{elector}` cambiando `nombre`/`observaciones`, entonces se persisten; el `telefono_hash` no cambia si el teléfono no cambió.
10. Dado `PUT /api/electores/{elector}` con un teléfono nuevo que normaliza al `telefono_hash` de **otro** elector del mismo tenant, entonces 409 (dedup); con un teléfono nuevo único, se actualiza `telefono` y `telefono_hash`.
11. Dado marcar atendida una interacción **dos veces**, entonces es idempotente (200, conserva la primera `atendido_en`).
12. Dado un usuario sin membership en el tenant activo, cuando intenta registrar una interacción, entonces 403.

## Notas de implementación
- **`membership_id` siempre del servidor**: reusar `User::membershipEn(TenantContext::get())` como en `ElectorController::store`. Nunca aceptar `membership_id` en el body.
- **Resolución de modelos tenant-scoped**: `Elector::query()->findOrFail($id)` e `Interaccion::query()->findOrFail($id)` en el controlador (corre tras `ResolveTenant`), nunca binding implícito por type-hint (regla Sprint 4). Las rutas usan `{elector}`/`{interaccion}` como string id.
- **Agenda por rol**: leer el rol de la membership activa (`User::membershipEn(...)->rol`), no de `users`. `brigadista` ⇒ `where('membership_id', $membership->id)`; `coordinador|admin` ⇒ sin ese filtro (el global scope ya acota al tenant).
- **`atendido_en` no está en el data-model original**: al cerrar el sprint, actualizar `_ai/docs/data-model.md` (tabla `interacciones`) para reflejar la columna nueva — coherencia spec/doc.
- **Sin `updated_at`**: la tabla `interacciones` solo define `created_at` (data-model). Configurar el modelo para no esperar `updated_at`.
- **Dedup en edición**: reusar `App\Support\Telefono` y la misma lógica de `CapturarElector` (lanzar `App\Exceptions\ElectorDuplicado`, el controlador responde 409). Excluir el propio elector del lookup de colisión.
- **Tests**: carpeta `tests/Feature/Interacciones/`. Crear `InteraccionFactory`; reusar `ElectorFactory`, `MembershipFactory`, `TenantFactory`. Probar aislamiento cross-tenant, agenda por rol, idempotencia de atendido, y la re-hash/dedup de la edición. Larastan limpio.
- **Frontend**: páginas Inertia nuevas (`Agenda.vue`, `Elector.vue`). Reusar el patrón de `Metas.vue`/`Brigadistas.vue` para formularios y tablas; mobile-first para la agenda (uso en campo).
