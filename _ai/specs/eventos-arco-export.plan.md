# Plan de Implementación — Eventos + ARCO + Export (Sprint 7)

> Acompaña a `specs/eventos-arco-export.spec.md`. Cierra el plan de 7 sprints. Reusa Sprint 4 (`CapturarElector`, `electores.evento_id`, `ElectorCapturado`, cobertura), Sprint 6 (`Elector.vue`/ficha). No introducir paquetes nuevos.

## ⚠️ Trampas confirmadas (antes de codear)
1. **`SoftDeletes` + `BelongsToTenant`** son dos global scopes que conviven. Dedup, listas y recobertura ya excluyen trasheados; usar `withTrashed()` solo para auditoría.
2. **FK `evento_id`**: la columna ya existe (Sprint 4) sin constraint; la migración solo **añade** la FK (`nullOnDelete`). El demo deja `evento_id=null`, sin huérfanos.
3. **Resolución manual** de `Elector`/`Evento` (`query()->findOrFail`) — regla anti-binding Sprint 4. Rutas con `{elector}`/`{evento}` string.
4. **Cancelación atómica**: scrub PII + `softDelete` + `solicitudes_arco` + recobertura en una transacción; recobertura reusa `RecalcularCoberturaSeccion`/`ActualizarCoberturaSeccion` (no inventar).
5. **Rol destructivo**: `DELETE` y export bajo `rol:coordinador,admin`. Eventos y `POST solicitudes-arco` abiertos a cualquier miembro.
6. **`membership_id` del servidor** en captura por evento (`User::membershipEn`).
7. **Tenant en tests**: `actingAs($user)->withSession(['tenant_id'=>$t->id])`; `setupCampana` (patrón `CapturaElectorTest`).

## Orden de construcción (incremental, TDD)

### Bloque A — Esquema + modelos
1. Migración `create_eventos_table` (tenant_id, nombre, tipo, fecha tz, lugar null, seccion_id FK null, ubicacion Point 4326 null, timestamps; índice `(tenant_id,fecha)`; GiST ubicacion).
2. Migración `create_solicitudes_arco_table` (tenant_id, elector_id FK null nullOnDelete, tipo, estado, solicitado_en, atendido_en null; índice `(tenant_id,estado)`).
3. Migración `add_softdeletes_and_evento_fk_to_electores` — `softDeletes()` + `foreign('evento_id')->references('id')->on('eventos')->nullOnDelete()`.
4. `App\Models\Evento` (`BelongsToTenant`, `HasSpatial`, casts, `seccion()`/`electores()`) + `EventoFactory`.
5. `App\Models\SolicitudArco` (`BelongsToTenant`, casts, `elector()`) + `SolicitudArcoFactory`.
6. `App\Models\Elector`: `use SoftDeletes;` + `evento()` belongsTo.

### Bloque B — Eventos + captura por evento
7. `App\Actions\Eventos\CrearEvento::handle(array $datos): Evento` (valida seccion del municipio → 422).
8. `CapturarElector`: añadir `resolverEvento()` y rama `modo='evento'` en `resolverSeccion` (hereda `evento.seccion_id`, fallback explícito/GPS); setear `evento_id`.
9. `StoreElectorRequest`: `modo_captura` `in:individual,loteria,evento`; `evento_id` `nullable|integer` (validar pertenencia en la acción, no exists abierto).
10. `EventoController` (`index` Inertia, `store`, `asistentes`); `StoreEventoRequest` (authorize=membership; rules nombre/tipo/fecha/lugar/seccion_id/ubicacion).
11. Tests `tests/Feature/Eventos/`: criterios 1,2,3,4,5,12.

### Bloque C — Privacidad / ARCO
12. `App\Actions\Privacidad\RegistrarSolicitudArco::handle(array $datos, Membership): SolicitudArco` (estado=pendiente).
13. `App\Actions\Privacidad\CancelarElector::handle(Elector): void` (transacción: scrub PII → null, `softDelete`, `solicitudes_arco` cancelacion/atendida, recobertura sección).
14. `ElectorController::destroy` (rol gate vía ruta) reusa `CancelarElector`; `SolicitudArcoController::store` reusa `RegistrarSolicitudArco`.
15. `StoreSolicitudArcoRequest` (authorize=membership; `tipo` in enum; `elector_id` nullable).
16. Tests `tests/Feature/Privacidad/`: criterios 6,7,8,9,10,12.

### Bloque D — Export CSV
17. `ExportController::electores` (`rol:coordinador,admin`): `response()->streamDownload()`, `Elector::query()` con filtros `seccion_id`/`desde`/`hasta` (`created_at`), `cursor()`; descifra PII; prefijo anti-injection; excluye cancelados (scope).
18. Tests `tests/Feature/Export/`: criterio 11 (contenido + descifrado + filtros + 403 brigadista + cancelados excluidos).

### Bloque E — Rutas
19. `routes/web.php` (dentro de `auth,verified`):
    - cualquier miembro: `GET /eventos`, `POST /eventos`, `GET /api/eventos/{evento}/asistentes`, `POST /api/solicitudes-arco`.
    - `rol:coordinador,admin`: `DELETE /api/electores/{elector}`, `GET /api/export/electores.csv`.
20. Wayfinder regenera en build.

### Bloque F — Frontend
21. `Eventos.vue` (lista + alta) + entrada sidebar "Eventos" (cualquier rol). Asistentes (panel o `Evento.vue`).
22. `Captura.vue`: tercer modo "Evento" (elegir evento → capturar heredando sección).
23. `Elector.vue` (Sprint 6): coordinador/admin → botón "Cancelar (ARCO)" con confirmación (`DELETE`); cualquier miembro → "Registrar solicitud ARCO" (`POST`).
24. Botón "Exportar CSV" (coordinador/admin) con filtros sección/fechas (en `Metas.vue` o `Brigadistas.vue`).
25. `DemoCapturasSeeder`: 1-2 eventos demo con asistentes + 1 solicitud ARCO pendiente; limpiar `eventos`/`solicitudes_arco` en el reset.

## Tests (TDD — resumen)
- **Eventos** (`tests/Feature/Eventos/`): captura por evento, asistentes, validación seccion, aislamiento. Crit. 1-5,12.
- **Privacidad** (`tests/Feature/Privacidad/`): cancelación soft+scrub+cobertura+rol, solicitud ARCO, re-captura, aislamiento. Crit. 6-10,12.
- **Export** (`tests/Feature/Export/`): contenido/descifrado/filtros/rol/cancelados. Crit. 11.
- Crear `EventoFactory`, `SolicitudArcoFactory`. Reusar `setupCampana`, `ElectorFactory`, `MembershipFactory`, fixtures `99-test`.

## Definición de Done
- Migraciones aplican en limpio (eventos, solicitudes_arco, softDeletes+FK evento_id).
- Captura `evento` funcional (hereda sección del evento; liga `evento_id`; cross-tenant seguro).
- Cancelación ARCO = soft-delete + scrub PII + `solicitudes_arco` + recobertura, solo coordinador/admin; cancelados fuera de listas/cobertura; re-captura del teléfono permitida.
- `POST /solicitudes-arco` registra pendientes (cualquier miembro).
- Export CSV con PII descifrada, filtros y rol; CSV-injection mitigada.
- Frontend: `Eventos.vue`, modo Evento en captura, botones ARCO en la ficha, export en gestión; sidebar con Eventos; seed demo.
- Suite verde (151 + nuevos), Larastan y Pint limpios.
- Docs actualizados: `_ai/docs/data-model.md` (deleted_at en electores), `_ai/CONTEXT.md` (Sprint 7 cerrado / plan completo).
