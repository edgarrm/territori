# Spec — Eventos + Privacidad/ARCO + Export CSV (Sprint 7)

- **Feature ID**: Sprint 7 — Eventos + ARCO + export. Cierra el plan de construcción.
- **Estado**: En implementación (Sprint 7)
- **Depende de**: ADR-001 (multi-tenancy), ADR-003 (captura unificada: cierra el modo `evento`), ADR-004 (privacidad/LFPDPPP: derechos ARCO), `_ai/docs/data-model.md` (`eventos`, `solicitudes_arco`, `electores.evento_id`), `_ai/docs/api-contract.md` (Eventos, Export, Privacidad). Construye sobre Sprint 4 (`CapturarElector`, `electores.evento_id` nullable sin FK, `ElectorCapturado`→cobertura) y Sprint 6 (`interacciones`).

## Objetivo
Cerrar las tres piezas que faltan del producto:
1. **Eventos** — tercer modo de captura (`modo_captura='evento'`) y la tabla `eventos`, cerrando el FK que `electores.evento_id` dejó nullable-sin-constraint en Sprint 4.
2. **Privacidad / ARCO** — registrar solicitudes ARCO (`solicitudes_arco`) y ejecutar la **Cancelación** como **baja lógica** (`SoftDeletes`) del elector, restringida a coordinador/admin.
3. **Export CSV** — descarga de electores filtrable, respetando tenant y rol.

## Por qué
Eventos completa los 3 modos de captura de ADR-003 (mitin/reunión donde se captura en bloque ligado a un evento). ARCO es obligación legal de ADR-004 (LFPDPPP). El export da portabilidad/operación a la coordinación. Con esto el plan de 7 sprints queda cerrado.

## Decisiones validadas (este sprint)
1. **Cancelación ARCO = baja lógica** con `SoftDeletes` (no borrado físico), **solo coordinador/admin**. Para cumplir ADR-004 además del soft-delete se **borra (scrub) la PII** del elector cancelado (`nombre`, `telefono`, `telefono_hash`, `domicilio`, `ubicacion`, `observaciones` → null), conservando la fila (con `deleted_at`) para auditoría junto al registro en `solicitudes_arco`. *(Si se prefiere soft-delete puro sin scrub, es un cambio menor.)*
2. **Eventos abiertos a cualquier miembro** (como la captura): listar/crear no requiere rol de gestión.

## Alcance

### Incluye

**Migraciones**
- `eventos`: `id`, `tenant_id` FK→tenants cascade, `nombre` varchar(160), `tipo` varchar(40), `fecha` timestamptz, `lugar` varchar(200) nullable, `seccion_id` FK→secciones nullable, `ubicacion` GEOMETRY(Point,4326) nullable, timestamps. Índice `(tenant_id, fecha)`; GiST sobre `ubicacion` si se puebla.
- `solicitudes_arco`: `id`, `tenant_id` FK→tenants cascade, `elector_id` FK→electores nullable `nullOnDelete`, `tipo` varchar(12) (`acceso|rectificacion|cancelacion|oposicion`), `estado` varchar(12) (`pendiente|atendida`), `solicitado_en` timestamptz, `atendido_en` timestamptz nullable. Índice `(tenant_id, estado)`.
- `electores`: agregar `softDeletes()` (`deleted_at` timestamptz nullable) **y** la **FK pendiente** `evento_id` → `eventos` (`nullOnDelete`). La columna `evento_id` ya existe (Sprint 4); esta migración solo añade el constraint.

**Modelos**
- `App\Models\Evento` con `BelongsToTenant` + `HasSpatial`. Cast `ubicacion`→Point, `fecha`→datetime. Relaciones `seccion()`, `electores()` (hasMany). Factory + estados.
- `App\Models\SolicitudArco` con `BelongsToTenant`. Casts `solicitado_en`/`atendido_en`→datetime. Relación `elector()`. Factory.
- `App\Models\Elector`: `use SoftDeletes;`, relación `evento()` (belongsTo). El global scope de SoftDeletes excluye trasheados por defecto (listas, cobertura, dedup ven solo vivos).

**Lógica de dominio**
- `App\Actions\Electores\CapturarElector`: soportar `modo_captura='evento'`. Resolver evento explícito (`evento_id` del request, debe ser del tenant); **sección heredada del evento** (`evento.seccion_id`); si el evento no tiene sección, caer al `seccion_id` explícito o GPS (igual que individual). Setear `evento_id` en el elector. (Mismo patrón que `resolverLoteria`.)
- `App\Actions\Eventos\CrearEvento`: valida que `seccion_id` (si viene) pertenezca al municipio del tenant (→422); crea el evento en el tenant activo.
- `App\Actions\Privacidad\RegistrarSolicitudArco`: crea `solicitudes_arco` `estado=pendiente`, `solicitado_en=now()`. `elector_id` opcional (puede no estar ligada a un elector concreto). No borra nada (es el registro de la petición a atender).
- `App\Actions\Privacidad\CancelarElector` (la Cancelación ejecutada): en una transacción → **scrub PII** + `softDelete()` del elector, registra `solicitudes_arco` `tipo=cancelacion estado=atendida atendido_en=now elector_id=<id>`, y **recalcula la cobertura** de la sección (el conteo baja). Idempotente: cancelar uno ya cancelado no rompe (404 por el scope, o no-op).
- Recobertura tras cancelación: reutilizar la acción compartida `App\Actions\Cobertura\RecalcularCoberturaSeccion` (o despachar el job `ActualizarCoberturaSeccion`) para esa sección. El recount ya excluye trasheados por el scope.
- `App\Actions\Export\ExportarElectoresCsv` (o lógica en el controlador con `StreamedResponse`): genera CSV de electores del tenant, filtros `seccion_id`, `desde`, `hasta` (sobre `created_at`). Columnas: `id, nombre, telefono, domicilio, seccion, modo_captura, consentimiento, capturado_en`. **Descifra** `telefono`/`domicilio` (roles autorizados, ADR-004). Excluye cancelados (soft-deleted).

**Endpoints**
- `GET /eventos` (Inertia, cualquier miembro) — lista de eventos del tenant.
- `POST /eventos` (cualquier miembro) — crea evento → 201/redirect.
- `GET /api/eventos/{evento}/asistentes` — electores con ese `evento_id` (scoped, resolución manual).
- `POST /api/electores` — ahora acepta `modo_captura=evento` (con `evento_id`).
- `DELETE /api/electores/{elector}` (**rol:coordinador,admin**) — Cancelación ARCO (baja lógica + scrub) → 204/200.
- `POST /api/solicitudes-arco` (cualquier miembro) — `{ elector_id?, tipo }` → 201, registra solicitud pendiente.
- `GET /api/export/electores.csv?seccion_id=&desde=&hasta=` (**rol:coordinador,admin**) — descarga CSV.

**Frontend (Inertia + Vue)**
- `Eventos.vue` (`GET /eventos`): lista de eventos (nombre, tipo, fecha, lugar, sección) + formulario de alta. Entrada "Eventos" en el sidebar (cualquier rol).
- `Captura.vue`: **tercer modo "Evento"** — elegir un evento del tenant y capturar heredando su sección (UX paralela a Lotería). 
- Vista/sección de **asistentes** de un evento (puede ser un panel dentro de `Eventos.vue` o `Evento.vue`).
- **Ficha de elector** (`Elector.vue`, Sprint 6): para coordinador/admin, botón **"Cancelar (ARCO)"** con confirmación → `DELETE`; para cualquier miembro, acción **"Registrar solicitud ARCO"** (acceso/rectificación/oposición/cancelación) → `POST /api/solicitudes-arco`.
- **Botón "Exportar CSV"** (coordinador/admin) en una vista de gestión (p.ej. `Metas.vue`/`Brigadistas.vue` o `Mapa`), con filtros de sección/fechas.
- `DemoCapturasSeeder` (o seeder de apoyo): sembrar 1-2 eventos demo con algunos asistentes y alguna `solicitud_arco` pendiente, para datos end-to-end.

### No incluye
- Borrado **físico** / purga definitiva (se eligió baja lógica; una purga periódica de trasheados queda fuera de alcance).
- Flujo de atención de solicitudes ARCO no-cancelación (acceso/rectificación/oposición) más allá de **registrarlas** `pendiente` (la operación manual de atenderlas se modela pero su UI de bandeja queda fuera de alcance este sprint).
- Export en formatos extra (XLSX/PDF) o export de interacciones; solo CSV de electores.
- Notificaciones, RSVP o aforo de eventos; un evento es solo el contenedor de captura + metadatos.
- Rotación de llaves / KMS (igual que Sprint 4).

## Criterios de aceptación (tests)
1. Dado un evento del tenant con `seccion_id` X, cuando se captura en `modo_captura='evento'` con ese `evento_id`, entonces el elector queda con `evento_id`, `seccion_id=X` y `modo_captura='evento'`.
2. Dado un evento **sin** sección, cuando se captura en modo evento con `seccion_id` explícito (o GPS), entonces la sección se resuelve por ese camino y el `evento_id` se liga igual.
3. Dado un `evento_id` de **otro tenant**, cuando se intenta capturar contra él, entonces falla (no se liga un evento ajeno).
4. Dado `POST /eventos` con `seccion_id` de otro municipio, entonces 422 y no se crea.
5. Dado `GET /api/eventos/{evento}/asistentes`, entonces devuelve solo los electores de ese evento en el tenant; evento de otro tenant → 404.
6. Dado un coordinador y un elector, cuando hace `DELETE /api/electores/{id}`, entonces el elector queda **soft-deleted** (`deleted_at` no null), su PII queda **scrubbeada** (nombre/telefono/telefono_hash/domicilio null), se crea `solicitudes_arco` `tipo=cancelacion estado=atendida`, y deja de aparecer en listas/cobertura (el `capturados` de la sección baja).
7. Dado un **brigadista**, cuando intenta `DELETE /api/electores/{id}`, entonces 403 (solo coordinador/admin).
8. Dado un elector cancelado (soft-deleted), cuando se consulta `GET /api/electores/{id}` o la lista de la sección, entonces no aparece (404/excluido por el scope).
9. Dado `POST /api/solicitudes-arco {tipo:'acceso', elector_id}`, entonces se crea una solicitud `estado=pendiente` y **no** se borra ni modifica el elector.
10. Dado un elector cancelado, cuando se re-captura el **mismo teléfono**, entonces se permite (el dedup por `telefono_hash` ve solo vivos; el hash del cancelado fue scrubbeado).
11. Dado coordinador, cuando pide `GET /api/export/electores.csv`, entonces recibe un CSV con encabezados y filas del tenant, con `telefono` **descifrado**; filtros `seccion_id`/`desde`/`hasta` acotan; cancelados excluidos. Brigadista → 403.
12. Aislamiento cross-tenant en `eventos`, `solicitudes_arco`, asistentes y export (patrón `BelongsToTenant`).

## Notas de implementación
- **FK pendiente `evento_id`**: la columna existe desde Sprint 4 sin constraint. La migración de Sprint 7 añade la FK (`nullOnDelete`). Confirmar que no hay datos demo con `evento_id` huérfano antes (el demo siempre lo deja null).
- **SoftDeletes + BelongsToTenant**: ambos son global scopes y conviven. Las queries de dedup (`CapturarElector`), listas y `RecalcularCoberturaSeccion` heredan la exclusión de trasheados automáticamente. Para tocar trasheados (auditoría) usar `withTrashed()` explícito.
- **Scrub + recobertura en transacción**: `CancelarElector` debe ser atómica. Tras el `softDelete`, recalcular la sección (recount excluye trasheados). El evento de dominio puede reutilizar `ActualizarCoberturaSeccion`/`RecalcularCoberturaSeccion` (no inventar otro).
- **Resolución manual de modelos tenant-scoped** (`Elector`, `Evento`): `Model::query()->findOrFail($id)` en el controlador (regla anti-binding de Sprint 4). Las rutas usan `{elector}`/`{evento}` como string.
- **`membership_id` del servidor** en captura por evento (igual que loteria/individual): `User::membershipEn`, nunca del request.
- **Export streaming**: usar `response()->streamDownload()` / `StreamedResponse` para no cargar todo en memoria; iterar con `cursor()` (scoped). Descifrado transparente al leer por el modelo `Elector` (cast `encrypted`); cuidar no incluir PII de cancelados.
- **CSV seguro**: prefijar celdas que empiecen con `= + - @` para evitar CSV injection en Excel; encabezados en español.
- **`modo_captura`**: es varchar sin constraint DB; solo cambia la validación de `StoreElectorRequest` (`in:individual,loteria,evento`) y `CapturarElector`.
- **Tests**: carpeta `tests/Feature/Eventos/` y `tests/Feature/Privacidad/` (o `tests/Feature/Arco/`). Crear `EventoFactory`, `SolicitudArcoFactory`. Reusar `setupCampana`/fixtures `99-test`, `ElectorFactory`, `MembershipFactory`. Cubrir: captura por evento, asistentes, cancelación (soft+scrub+cobertura+rol), solicitud ARCO, export (contenido + rol + filtros), aislamiento, y re-captura tras cancelación. Larastan y Pint limpios.
