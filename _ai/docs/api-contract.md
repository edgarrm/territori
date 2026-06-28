# API / Endpoints — Territori

> App interna sobre **Inertia.js v3**: la mayoría son rutas web que devuelven páginas/props, no JSON REST. Aquí se documentan tanto las rutas Inertia (devuelven páginas Vue) como los endpoints de datos JSON/GeoJSON que consumen Leaflet y la captura. Todas las rutas tenant-scoped resuelven el tenant del **usuario autenticado** (sesión/subdominio), nunca del request.
>
> Sincronizado con `php artisan route:list --except-vendor` (44 rutas; excluye las de Fortify/settings del starter kit).

## Convenciones

- Auth requerida salvo login. Tenant implícito por sesión/subdominio (`ResolveTenant`).
- Rol exigido vía middleware `rol:` (lee la membresía activa). `gestión` = `coordinador` o `admin`.
- Modelos tenant-scoped se resuelven **manualmente** en el controlador (patrón anti-binding: `SubstituteBindings` corre antes que `ResolveTenant`).
- Errores: `422` validación (JSON) · redirect con errores de sesión (formularios Inertia que devuelven `back()`) · `403` fuera de rol/tenant · `404` no encontrado · `409` duplicado.

---

## Auth (Fortify)
- `GET /login` · `POST /login` · `POST /logout`
- Tras login, `App\Http\Responses\LoginResponse`: sin memberships → `campanas.sin-membership`; una → entra directo; varias → `campanas.seleccionar`.

## Campaña / tenant
- `GET /campanas/crear` — `campanas.crear` · form de alta self-service (catálogo entidad→municipio en cascada).
- `POST /campanas` — `campanas.store` · crea el tenant y la membresía admin del usuario. Body: `{ nombre, municipio_id, subdominio?, marca_nombre?, marca_color? }`. Guarda `tenant_id` en sesión → redirect a `dashboard`.
- `GET /campanas/seleccionar` · `POST /campanas/seleccionar` — `campanas.seleccionar[.store]` · elige campaña activa cuando hay varias.
- `GET /campanas/sin-membership` — `campanas.sin-membership` · usuario sin ninguna campaña.

> La carga de cartografía del municipio **no es un endpoint**: se ejecuta con el `CartografiaSeeder` (ver `docs/operaciones.md`).

## Mapa, cobertura y metas *(gestión)*
- `GET /mapa` — `mapa` · página Inertia del dashboard (pasa municipio/estado/totalSecciones).
- `GET /secciones/{seccion}` — `secciones.detalle` · página Inertia de detalle de sección.
- `GET /metas` — `metas` · vista de metas por sección.
- `GET /api/cobertura.geojson?modo=cobertura|penetracion|tipo` — `mapa.cobertura`
  → `FeatureCollection`; cada feature: `{ geometry, properties: { seccion_id, numero, tipo, distrito_local, distrito_federal, capturados, meta, cobertura, penetracion, lista_nominal } }`. Lee de `cobertura_seccion` ⨝ `secciones` (geom simplificada con `ST_SimplifyPreserveTopology`). **No agrega en vivo.**
- `GET /api/secciones/{seccion}/resumen` — `secciones.resumen`
  → `{ numero, tipo, lista_nominal, capturados, meta, cobertura, penetracion, distritos, brigadistas_activos:[], ultimo_registro }`. `brigadistas_activos` = activos **y** asignados a la sección.
- `PUT /api/secciones/{seccion}/meta` — `secciones.meta`
  body: `{ fuente_meta:"manual", meta_capturas:120 }` **o** `{ fuente_meta:"lista_nominal_pct", pct_lista_nominal:30 }` → recalcula `metas_seccion` y refresca `cobertura_seccion`.

## Captura de electores
- `GET /captura` — `captura` · página Inertia (lotería / individual / evento).
- `POST /api/electores` — `electores.store`
  body: `{ modo_captura:"loteria|individual|evento", seccion_id?, loteria_id?, evento_id?, nombre, telefono, domicilio?, ubicacion?{lat,lng}, observaciones?, consentimiento:true, aviso_privacidad_id }`.
  Reglas: `loteria`→sección heredada de la lotería abierta; `evento`→sección heredada del evento (fallback GPS); si falta `seccion_id` y hay `ubicacion`→`ST_Contains`; rechaza si `consentimiento!=true`; dedup por `telefono_hash`. `membership_id` se resuelve en el servidor.
  → `201 { id, seccion_id, ... }` · `409` duplicado (con id existente).
- `GET /api/electores/{elector}` — `electores.show` · ficha + observaciones (JSON).
- `GET /electores/{elector}` — `electores.page` · página Inertia: ficha editable + timeline de interacciones.
- `PUT /api/electores/{elector}` — `electores.update` · edita `nombre/telefono/domicilio/observaciones` (re-hashea si cambia el teléfono; `409` si colisiona con otro elector).
- `DELETE /api/electores/{elector}` — `electores.destroy` *(gestión)* · **cancelación ARCO**: baja lógica + scrub de PII + registra `solicitudes_arco` + recobertura.
- `GET /api/secciones/{seccion}/electores` — `secciones.electores` · lista paginada (scoped).

## Loterías (sesión de captura masiva)
- `POST /api/loterias` — `loterias.store` · abre sesión (una abierta por brigadista; reabrir devuelve la existente).
- `GET /api/loterias/activa` — `loterias.activa` · sesión abierta del brigadista actual.
- `POST /api/loterias/{loteria}/cerrar` — `loterias.cerrar`.

## Avisos de privacidad
- `GET /api/avisos-privacidad/vigente` — `avisos.vigente` · aviso vigente del tenant (para el flujo de consentimiento).

## Interacciones y agenda
- `GET /api/electores/{elector}/interacciones` — `interacciones.index` · timeline desc por `fecha`.
- `POST /api/electores/{elector}/interacciones` — `interacciones.store`
  body: `{ tipo:"llamada|correo|visita|whatsapp|sms|nota", resultado?, nota?, fecha?, proximo_seguimiento? }`. `tipo=nota` ⇒ `resultado` null; `fecha` default `now()`. `membership_id` del servidor.
- `PUT /api/interacciones/{interaccion}/atendido` — `interacciones.atendido` · marca `atendido_en` (idempotente; lo saca de la agenda).
- `GET /agenda` — `agenda` · página Inertia de seguimientos pendientes.
- `GET /api/agenda` — `agenda.data` · pendientes (`proximo_seguimiento <= hoy AND atendido_en IS NULL`). **Por rol**: brigadista ve solo los suyos; gestión ve todos los del tenant.

## Brigadistas *(gestión)*
- `GET /brigadistas` — `brigadistas` · página Inertia: brigadistas + ratios + `facturacion {activos, limite, puede_activar}`.
- `POST /brigadistas` — `brigadistas.store` · invita brigadista (reusa `InvitarMiembro`; al tope → `422` con upsell).
- `PUT /api/brigadistas/{membership}/activo` — `brigadistas.activo` · activar/desactivar (activar al tope → `422`).
- `GET /api/brigadistas/{membership}/ratios` — `brigadistas.ratios` · `capturas_dia/total`, `pct_completos`, `avance_meta`, `secciones_asignadas`.
- `PUT /api/brigadistas/{membership}/zonas` — `brigadistas.zonas` · asigna secciones (valida que sean del municipio → `422`; `sync` idempotente). Las zonas NO restringen la captura.

## Eventos
- `GET /eventos` — `eventos` · página Inertia (alta + lista con `asistentes_count`). Abierto a cualquier miembro.
- `POST /eventos` — `eventos.store` · body: `{ nombre, tipo, fecha, lugar, seccion_id?, ubicacion? }`. Devuelve `back()` (validación → redirect con errores de sesión, **no** 422).
- `GET /api/eventos/{evento}/asistentes` — `eventos.asistentes` · `{ evento, asistentes:[{id, nombre, seccion_id, capturado_en}] }`.

## Privacidad / ARCO
- `POST /api/solicitudes-arco` — `solicitudes-arco.store` · cualquier miembro. Body: `{ tipo:"acceso|rectificacion|cancelacion|oposicion", elector_id? }` → `201 { id, tipo, estado:"pendiente", elector_id }`.

## Export *(gestión)*
- `GET /api/export/electores.csv?seccion_id=&desde=&hasta=` — `export.electores` · `streamDownload` CSV; PII descifrada; excluye cancelados (soft-deleted); mitigación anti-CSV-injection. Filtros `desde/hasta` sobre `created_at`.
