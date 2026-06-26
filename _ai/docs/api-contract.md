# API / Endpoints — Territori

> App interna sobre Inertia.js: la mayoría son rutas web que devuelven páginas/props, no JSON REST. Aquí se documentan tanto las rutas Inertia como los endpoints de datos (JSON) que consume Leaflet y la captura. Todas las rutas tenant-scoped resuelven el tenant del usuario autenticado.

## Convenciones
- Auth requerida salvo login. Tenant implícito por sesión.
- Endpoints de datos para el mapa devuelven JSON/GeoJSON.
- Errores: 422 validación, 403 fuera de rol/tenant, 404 no encontrado.

---

## Auth
- `GET /login` · `POST /login` · `POST /logout`

## Campaña / setup (rol admin)
- `GET /campanas` — lista (solo admin SaaS, vista global)
- `POST /campanas` — crea tenant {nombre, municipio_id, plan}
- `POST /campanas/{tenant}/cartografia` — dispara carga de secciones del municipio elegido

## Mapa y cobertura (coordinador/admin)
- `GET /mapa` — página Inertia del dashboard
- `GET /api/cobertura.geojson?modo=cobertura|penetracion|tipo`
  → FeatureCollection. Cada feature: `{ geometry, properties: { seccion_id, numero, tipo, distrito_local, distrito_federal, capturados, meta, cobertura, penetracion, lista_nominal } }`.
  Lee de `cobertura_seccion` ⨝ `secciones` (geom simplificada). **No agrega en vivo.**
- `GET /api/secciones/{seccion}/resumen`
  → `{ numero, capturados, meta, cobertura, penetracion, brigadistas_activos:[], ultimo_registro }`

## Metas (coordinador/admin)
- `GET /metas` — vista de metas por sección
- `PUT /api/secciones/{seccion}/meta`
  body: `{ fuente_meta:"manual", meta_capturas:120 }` **o** `{ fuente_meta:"lista_nominal_pct", pct_lista_nominal:30 }`
  → recalcula `metas_seccion` y refresca `cobertura_seccion`.

## Electores — captura
- `GET /api/secciones/{seccion}/electores` — lista paginada (scoped)
- `POST /api/electores`
  body: `{ modo_captura, seccion_id?, loteria_id?, evento_id?, nombre, telefono, domicilio?, ubicacion?{lat,lng}, observaciones?, consentimiento:true, aviso_privacidad_id }`
  reglas: si `modo_captura=loteria` → `seccion_id` se hereda de la lotería abierta; si falta `seccion_id` y hay `ubicacion` → resolver por `ST_Contains`; rechazar si `consentimiento!=true`; dedup por `telefono_hash`.
  → 201 `{ id, seccion_id, ... }` · 409 si duplicado (con id existente)
- `GET /api/electores/{elector}` — ficha + observaciones
- `PUT /api/electores/{elector}` — editar datos / nota fija
- `DELETE /api/electores/{elector}` — borrado real (Cancelación ARCO), registra `solicitudes_arco`

## Lotería (sesión de captura masiva)
- `POST /api/loterias` — abre `{ seccion_id }` → `{ loteria_id }`
- `POST /api/loterias/{loteria}/cerrar`
- `GET /api/loterias/activa` — la sesión abierta del brigadista, si existe

## Interacciones (timeline)
- `GET /api/electores/{elector}/interacciones` — orden desc por fecha
- `POST /api/electores/{elector}/interacciones`
  body: `{ tipo, resultado?, nota?, fecha?, proximo_seguimiento? }`
  → 201
- `GET /api/agenda` — seguimientos del brigadista: interacciones con `proximo_seguimiento <= hoy` no atendidas, ordenadas. (Habilita la "agenda del día".)

## Brigadistas (coordinador/admin)
- `GET /brigadistas` — lista de memberships rol=brigadista + ratios (productividad, calidad, avance de meta)
- `POST /brigadistas` — alta: crea/encuentra `user` por email y crea `membership` {rol:brigadista, meta_diaria, activo}. Valida `limite_brigadistas` del plan.
- `PUT /api/brigadistas/{membership}/activo` — activa/desactiva (impacta facturación; registra activado_en/desactivado_en)
- `PUT /api/brigadistas/{membership}/zonas` — asigna secciones `{ seccion_ids:[] }`
- `GET /api/brigadistas/{membership}/ratios` — `{ capturas_dia, capturas_hora, pct_completos, avance_meta }`

## Eventos
- `GET /eventos` · `POST /eventos` `{ nombre, tipo, fecha, lugar, seccion_id?, ubicacion? }`
- `GET /api/eventos/{evento}/asistentes`

## Export (coordinador/admin)
- `GET /api/export/electores.csv?seccion_id=&desde=&hasta=` — respeta tenant + rol

## Privacidad
- `GET /api/avisos-privacidad/vigente` — versión actual para mostrar en captura
- `POST /api/solicitudes-arco` `{ elector_id?, tipo }`
