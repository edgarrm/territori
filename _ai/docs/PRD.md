# PRD — Sistema de Gestión Territorial de Campaña (working name: **Territori**)

> Documento de planeación inicial (Fase 01–02 del proceso AI-First SDD).
> Stack objetivo: Laravel 13 + Fortify + Vue 3/Inertia.js + PostgreSQL (multi-tenant por `tenant_id`) + PostGIS.
> Estado: borrador para validación. Última edición: captura web responsive, SaaS multi-tenant, solo datos de contacto.

---

## 1. Problem Statement

Las campañas políticas en México (y LATAM) gestionan la recolección de datos de electores con herramientas improvisadas: hojas de Excel, formularios de Google, papel. Esto produce:

- **Cero visibilidad territorial en tiempo real**: nadie sabe qué secciones están cubiertas y cuáles abandonadas hasta que es tarde.
- **Datos sucios y duplicados**: sin validación ni deduplicación.
- **Brigadistas sin metas ni rendición de cuentas**: no se mide quién produce.
- **Riesgo legal**: manejo de datos personales sin aviso de privacidad ni control de acceso.

**Territori** centraliza la captura de electores georreferenciada por sección electoral del INE, con analítica de cobertura en vivo y gestión de brigadistas, en una plataforma SaaS donde cada campaña es un tenant aislado.

---

## 2. Personas + Jobs To Be Done

### Persona A — Brigadista de campo ("capturador")
- Perfil: voluntario o pagado, usa su celular, conectividad variable, poca paciencia para formularios largos.
- **JTBD**: "Cuando estoy tocando puertas en una colonia, quiero registrar a cada persona en segundos sin equivocarme de sección, para no perder el ritmo ni datos."

### Persona B — Coordinador territorial ("estratega")
- Perfil: maneja un equipo de brigadistas, responde al candidato, vive en el dashboard.
- **JTBD**: "Cuando reviso el avance, quiero ver en un mapa qué secciones van bien y cuáles necesitan refuerzo, para reasignar brigadistas donde más rinden."

### Persona C — Candidato / Dirección de campaña
- Perfil: visión macro, quiere números claros y proyección.
- **JTBD**: "Cuando planeo la recta final, quiero saber si vamos a alcanzar la meta de contactos y dónde estamos débiles, para decidir gasto y esfuerzo."

### Persona D — Administrador SaaS (tú)
- **JTBD**: "Quiero dar de alta una campaña nueva, asignarle su municipio y dejarla operando en minutos, para escalar a varios clientes sin trabajo manual."

---

## 3. OKRs del producto

**O1 — Captura veloz y confiable en campo**
- KR1: registrar un elector en modo Lotería en ≤ 15 segundos.
- KR2: ≥ 95% de registros con teléfono en formato válido.
- KR3: tasa de duplicados < 2%.

**O2 — Visibilidad territorial accionable**
- KR1: el coordinador identifica las 10 secciones más rezagadas en ≤ 1 clic.
- KR2: el mapa de cobertura refleja capturas con < 1 min de latencia.

**O3 — Producto SaaS escalable**
- KR1: alta de una campaña nueva (tenant + municipio + secciones) en < 10 min.
- KR2: aislamiento total de datos entre tenants (0 fugas en pruebas).

---

## 4. Modelo conceptual

### Eje central
```
Tenant (Campaña)
  └─ elige un Municipio (del catálogo INE por estado)
       └─ hereda sus Secciones electorales (polígonos + lista nominal)
            └─ contiene Electores (contactos capturados)
                 └─ tiene un historial de Interacciones (llamadas, correos, visitas…)
```

### Los 3 modos de captura (misma entidad Elector, distinto contexto)

| Modo | Cómo funciona | Velocidad | Caso de uso |
|------|---------------|-----------|-------------|
| **Lotería** | El brigadista fija UNA sección al inicio de la sesión; todos los registros heredan esa sección. Campos mínimos. | Máxima | Casa por casa, mesa fija, llenado masivo en zona conocida |
| **Individual** | Captura un elector y se le asigna sección explícitamente (buscador, GPS o domicilio). Más campos. | Media | Contacto suelto, referido, dato de calidad |
| **Evento** | Se crea un Evento (mitin/reunión, fecha+lugar); los asistentes se ligan al evento. La sección varía por persona. | Media | Medir convocatoria, captar en concentraciones |

> Decisión de diseño: los tres flujos escriben en la **misma tabla `electores`**. Lo que cambia es la UI de captura y el `modo_captura` que queda registrado. La analítica es unificada.

---

## 5. Entidades (Data Model preliminar)

> Detalle completo de tipos y FKs en `data-model.md` durante Fase 04. Esto es el esqueleto.

- **tenants** — campaña. id, nombre, plan, estado, municipio_id seleccionado.
- **users** — usuarios del tenant. Roles: `admin`, `coordinador`, `brigadista`. (scoped por tenant_id)
- **municipios** — catálogo INE. clave_entidad, clave_municipio, nombre, geom. (global, no por tenant)
- **secciones** — catálogo INE. municipio_id, numero_seccion, tipo, distrito_f, distrito_l, lista_nominal (nullable), geom (polígono). (global)
- **metas_seccion** — POR TENANT. tenant_id, seccion_id, meta_capturas, fuente_meta (`manual` | `lista_nominal_pct`). Resuelve tu "ambas opciones".
- **brigadistas** — extiende users con rol brigadista: meta_diaria, zona_asignada (secciones), activo.
- **electores** — el contacto. tenant_id, seccion_id, brigadista_id, modo_captura (`loteria`|`individual`|`evento`), evento_id (nullable), nombre, telefono, domicilio, lat/lng (nullable), observaciones (nota fija/permanente del contacto), consentimiento (bool), created_at.
- **interacciones** — historial de contactos con un elector (timeline). tenant_id, elector_id, brigadista_id (quién hizo el contacto), tipo (`llamada`|`correo`|`visita`|`whatsapp`|`sms`|`nota`), resultado (`contesto`|`no_contesto`|`buzon`|`correo_enviado`|`no_estaba`|`rechazo`|`compromiso`|nullable para `nota`), nota (texto libre), fecha (cuándo ocurrió), proximo_seguimiento (date nullable, para recordatorios), created_at. Relación: un elector *tiene muchas* interacciones.
- **loterias** — sesión de captura masiva. tenant_id, brigadista_id, seccion_id, abierta_en, cerrada_en. (agrupa registros del modo Lotería)
- **eventos** — tenant_id, nombre, tipo, fecha, lugar, lat/lng, seccion_id (sede).
- **avisos_privacidad** — versión del aviso aceptado, para trazabilidad legal.

### Relaciones clave
- Un `elector` pertenece a exactamente una `seccion`, fue creado por un `brigadista`, en un `modo_captura`, opcionalmente dentro de un `evento` o una `loteria`.
- Un `elector` **tiene muchas** `interacciones` (uno-a-muchos). Distinción de diseño: `tipo` = el canal (cómo se contactó), `resultado` = qué pasó. Esa separación habilita medir efectividad por canal. Las **notas-evento** (con fecha) viven en `interacciones`; la **nota fija** (característica permanente: "líder de colonia", "no tocar antes de 10am") vive en `electores.observaciones`.
- `metas_seccion` permite que la meta venga de un número manual O de un % de la lista nominal (ej. "contactar al 30% del padrón de cada sección").

---

## 6. Ratios y analítica (el diferenciador)

> Esta es la prioridad #3 del usuario y el corazón del valor competitivo.

### Ratios por sección
- **Cobertura** = `capturados / meta_seccion` → pinta el mapa (rojo→verde). *El "baja vs alta afluencia" pedido.*
- **Penetración** = `capturados / lista_nominal` → % real sobre el universo oficial (cuando hay lista nominal).
- **Densidad de captura** = capturados / día en la sección → ritmo.

### Ratios por brigadista (prioridad #2)
- **Productividad** = capturas / día y capturas / hora activa.
- **Calidad del dato** = % registros completos (teléfono válido + domicilio).
- **Avance de meta** = capturas acumuladas / meta_diaria.

### Ratios de campaña (macro, prioridad #4 ata aquí)
- **Avance global** = total capturado / suma de metas.
- **Proyección** = ritmo actual × días restantes → ¿llega a meta? (semáforo)
- **Secciones desérticas** = lista de secciones con cobertura < umbral (reasignar).
- **Concentración** = índice de qué tan parejo está el esfuerzo territorial.

### Ratios de interacción / seguimiento (módulo de historial)
- **Intentos por contacto** = interacciones / elector → ¿cuántas veces hay que insistir para lograr contacto?
- **Tasa de contacto efectivo** = % de electores con al menos un resultado `contesto` o visita exitosa.
- **Seguimientos vencidos** = interacciones con `proximo_seguimiento < hoy` sin cerrar → la lista de pendientes del día por brigadista.
- **Canal más efectivo** = tasa de éxito por `tipo` (¿convierte más la llamada, la visita o WhatsApp?).
- **Electores fríos** = sin interacción en X días → lista para reactivar.

### Dashboard principal = el mapa
El mapa de secciones coloreado por **cobertura** es la pantalla central del coordinador. Clic en sección → panel con: capturados, meta, %, brigadistas activos ahí, último registro, botón "asignar refuerzo".

---

## 7. Feature List (MoSCoW)

### Must (MVP — sin esto no hay producto)
- M1. Auth + multi-tenancy con aislamiento por `tenant_id`.
- M2. Alta de campaña: elegir estado → municipio → cargar secciones INE.
- M3. Mapa de cobertura interactivo (evolución de la demo actual).
- M4. Captura modo **Lotería** (la más rápida, prioridad #1).
- M5. Captura modo **Individual**.
- M6. CRUD de electores con validación de teléfono y dedup básica.
- M7. Metas por sección (manual y por % de lista nominal).
- M8. Gestión de brigadistas + metas + ratios de productividad.
- M9. Aviso de privacidad + registro de consentimiento.
- M10. **Historial de interacciones** por elector (timeline: llamada/correo/visita/WhatsApp/nota, con tipo+resultado+fecha) + nota fija (`observaciones`).

### Should (hace al producto competitivo)
- S1. Captura modo **Evento**.
- S2. Dashboard de proyección y secciones desérticas.
- S3. Asignación de zonas (secciones) a brigadistas.
- S4. Export CSV/Excel por sección o campaña.
- S5. Detección de sección por GPS al capturar.
- S6. **Recordatorios de seguimiento**: agenda diaria de `proximo_seguimiento` por brigadista + alertas de seguimientos vencidos.

### Could (diferenciadores premium)
- C1. Histórico de resultados electorales por sección (prioridad #4).
- C2. App "modo offline" real (PWA con cola de sincronización).
- C3. Roles granulares y auditoría de accesos.
- C4. Importación de listas existentes (migración desde Excel del cliente).

### Won't (explícitamente fuera de alcance, por ahora)
- W1. **Captura de intención de voto / afiliación** (decisión del usuario: solo contacto). Evita datos sensibles del art. 9 LFPDPPP.
- W2. Uso de datos oficiales del Padrón Electoral del INE (acceso restringido legalmente).
- W3. Mensajería masiva / robocalls (otro producto, otra regulación).

---

## 8. Consideraciones legales (México) — no omitir

> No es asesoría legal; es un mapa de lo que el diseño debe contemplar. Conviene validar con un abogado especializado en protección de datos y materia electoral.

- **LFPDPPP**: aunque solo se capturen datos de contacto (nombre, teléfono, domicilio), son **datos personales**. Requieren: aviso de privacidad, consentimiento del titular, finalidad declarada, y medidas de seguridad. El sistema ya contempla `consentimiento` y `avisos_privacidad`.
- **Al excluir intención de voto/afiliación** (W1), se evita el régimen reforzado de **datos sensibles** (art. 9). Buena decisión para el MVP.
- **Padrón del INE**: la lista nominal con nombres NO es de uso libre. Lo que sí es público y libre es la **cartografía** (polígonos, números de sección) y, en agregado, el **número** de la lista nominal por sección (estadística censal seccional). Usar solo el conteo agregado para metas es seguro; capturar nombres del padrón directamente, no.
- **Seguridad**: cifrado en reposo de datos personales, control de acceso por rol, y borrado/portabilidad a solicitud del titular (derechos ARCO).
- **Trazabilidad**: registrar qué brigadista capturó qué y con qué versión de aviso de privacidad.

---

## 9. Arquitectura preliminar (para Fase 04)

- **Multi-tenancy**: single-database con `tenant_id` scoping (global scopes de Eloquent), igual que tu CRM LATAM. Catálogos INE (municipios/secciones) son globales y compartidos; los datos de campaña son por tenant.
- **PostGIS**: extensión de Postgres para almacenar y consultar geometrías. Permite "¿en qué sección cae este punto GPS?" con un query espacial (`ST_Contains`).
- **Mapa**: Leaflet en el front (ya probado en la demo), GeoJSON servido por endpoint con la cobertura precalculada o materializada.
- **Captura rápida**: endpoints ligeros, optimistic UI, validación en cliente. PWA installable para que el brigadista la tenga como "app".
- **Carga de catálogos**: pipeline (comando artisan) que ingiere los shapefiles del INE por estado → tabla `secciones` con geom. Ya tenemos el procesamiento en Python como referencia; se porta a un seeder/importador.

---

## 10. Roadmap de sprints (alineado a tu proceso)

| Sprint | Entregable | Fase SDD |
|--------|-----------|----------|
| **0** | Este PRD validado + repo + `_ai/` + CONTEXT.md | 01–02 |
| **1** | Importador de shapefiles INE → Postgres/PostGIS + catálogo municipios | 04 |
| **2** | Auth + multi-tenancy + alta de campaña (elige municipio) | 04–06 |
| **3** | Mapa de cobertura con datos reales + metas por sección | 05–06 |
| **4** | Captura Lotería + Individual + validación/dedup | 05–06 |
| **5** | Brigadistas + metas + ratios de productividad | 05–06 |
| **6** | Dashboard de proyección + export + aviso de privacidad | 05–06 |
| **7+** | Should/Could features según tracción | continuo |

---

## 11. Preguntas abiertas — RESUELTAS (Fase 04)

1. **Lista nominal**: catálogo **global** en `secciones.lista_nominal` (dato público del INE, igual para todas las campañas). Las metas siguen por tenant en `metas_seccion`. ✔
2. **Brigadista multi-campaña**: **Sí**. Se separa `users` (persona física, global) de `memberships` (pertenencia + rol por campaña). Capturas referencian `membership_id`. ✔
3. **Jerarquía de coordinadores**: **No**. Un solo nivel (coordinador→brigadistas). Sin `parent_id`. ✔
4. **White-label**: **Sí, desde el inicio**. Campos `marca_*` y `subdominio` en `tenants`. Ver ADR-005. ✔
5. **Modelo de cobro**: **Por brigadista activo** (`membership` con `rol=brigadista` y `activo=true`). `tenants.limite_brigadistas` por plan. Ver ADR-005. ✔

**Único pendiente (regla de negocio, no de esquema)**: política exacta de cobro por brigadista — ¿pico de activos del mes, promedio, o conteo al corte? El esquema ya soporta cualquiera vía `activado_en`/`desactivado_en`.

---

*Siguiente paso recomendado: validar este PRD, responder las preguntas abiertas, y pasar a Fase 04 (Architecture): ADRs de multi-tenancy y PostGIS + CONTEXT.md + data-model.md detallado.*
