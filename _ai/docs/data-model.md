# Data Model — Territori

> Fase 04. PostgreSQL 16 + PostGIS. Multi-tenant single-DB (ADR-001). SRID 4326.
> Convención: `snake_case`, PKs `id` bigint identity, timestamps `created_at`/`updated_at`.
> Leyenda: 🌐 = catálogo global (sin tenant_id) · 🏢 = tenant-scoped (lleva tenant_id + BelongsToTenant)

---

## Catálogos globales (cartografía INE)

### 🌐 entidades
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| clave | smallint | clave INEGI/INE (25 = Sinaloa) |
| nombre | varchar(80) | |
| geom | GEOMETRY(MultiPolygon,4326) | GiST index |

### 🌐 municipios
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| entidad_id | bigint FK→entidades | |
| clave | smallint | clave de municipio dentro de la entidad |
| nombre | varchar(120) | |
| geom | GEOMETRY(MultiPolygon,4326) | GiST index |
| Único | (entidad_id, clave) | |

### 🌐 secciones
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| municipio_id | bigint FK→municipios | indexado |
| numero | integer | número de sección (ej. 2540) |
| tipo | smallint | 1=urbana, 2=no urbana, 3=mixta |
| distrito_federal | smallint | |
| distrito_local | smallint | |
| lista_nominal | integer NULL | conteo agregado público; NULL si no se cargó |
| geom | GEOMETRY(MultiPolygon,4326) | GiST index |
| Único | (municipio_id, numero) | |

> La geometría servida al frontend se simplifica en consulta (`ST_SimplifyPreserveTopology`) o se cachea por municipio. La full se conserva para `ST_Contains`.

---

## Identidad y campaña

### 🏢 tenants
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | (esta tabla NO se auto-filtra; es la raíz) |
| nombre | varchar(160) | nombre de la campaña |
| municipio_id | bigint FK→municipios | municipio elegido para la campaña |
| plan | varchar(40) | plan SaaS |
| estado | varchar(20) | `activo\|suspendido\|prueba` |
| limite_brigadistas | integer NULL | tope de brigadistas activos según plan (P4); NULL = sin tope |
| **marca_nombre** | varchar(120) NULL | nombre visible (white-label, P3); fallback "Territori" |
| **marca_logo_url** | varchar NULL | logo del cliente |
| **marca_color** | varchar(7) NULL | color primario hex (ej. #1d4ed8) |
| **subdominio** | varchar(63) NULL | único; para resolver tenant por URL (white-label) |
| created_at / updated_at | timestamptz | |

> Campos `marca_*` y `subdominio` habilitan white-label desde el inicio (P3). Ver ADR-005.

### 🌐 users (identidad de la persona — NO tenant-scoped)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | varchar(160) | |
| email | varchar(190) | **único global** |
| telefono | varchar NULL | contacto de la persona |
| password | varchar | hash |
| created_at / updated_at | timestamptz | |

> `users` es la **persona física**. No lleva `tenant_id` ni `rol`: una misma persona puede participar en varias campañas (P2). Su pertenencia y rol viven en `memberships`.

### 🏢 memberships (persona ⨯ campaña — pivote con rol)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK→tenants | indexado |
| user_id | bigint FK→users | indexado |
| rol | varchar(20) | `admin\|coordinador\|brigadista` (rol EN esta campaña) |
| meta_diaria | integer NULL | solo si rol=brigadista; meta en ESTA campaña |
| activo | boolean | default true; **un brigadista `activo` cuenta para facturación** (P4) |
| activado_en | timestamptz | última activación (trazabilidad de cobro) |
| desactivado_en | timestamptz NULL | |
| created_at / updated_at | timestamptz | |
| Único | (tenant_id, user_id) | una membresía por persona-campaña |

> El aislamiento multi-tenant se resuelve verificando que el usuario autenticado tiene una `membership` activa en el `tenant_id` actual. El `rol` se lee de la membership, no del user. La facturación por brigadista activo = `COUNT(memberships WHERE rol='brigadista' AND activo=true)` por tenant.
>
> **No hay jerarquía de coordinadores** (P2b): un solo nivel. No existe `parent_id`. Si en el futuro se requiere, se añade entonces.

### 🏢 brigadista_seccion (asignación de zonas)
| Columna | Tipo | Notas |
|---|---|---|
| tenant_id | bigint | |
| membership_id | bigint FK→memberships | brigadista (membresía, no user directo) |
| seccion_id | bigint FK→secciones | |
| PK | (membership_id, seccion_id) | |

---

## Metas

### 🏢 metas_seccion
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint | |
| seccion_id | bigint FK→secciones | |
| meta_capturas | integer | meta efectiva (resuelta) |
| fuente_meta | varchar(20) | `manual` \| `lista_nominal_pct` |
| pct_lista_nominal | numeric(5,2) NULL | si fuente = lista_nominal_pct (ej. 30.00) |
| Único | (tenant_id, seccion_id) | |

> Resuelve "ambas opciones": si `fuente_meta=manual`, `meta_capturas` se fija a mano; si `lista_nominal_pct`, se calcula `meta_capturas = round(secciones.lista_nominal * pct/100)` y se recalcula si cambia la lista nominal.

---

## Captura de electores

### 🏢 loterias (sesión de captura masiva)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint | |
| membership_id | bigint FK→memberships | brigadista (membresía) que la abrió |
| seccion_id | bigint FK→secciones | sección fija de la sesión |
| abierta_en | timestamptz | |
| cerrada_en | timestamptz NULL | NULL = sesión activa |

### 🏢 eventos
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint | |
| nombre | varchar(160) | |
| tipo | varchar(40) | mitin, reunión, etc. |
| fecha | timestamptz | |
| lugar | varchar(200) | |
| seccion_id | bigint FK→secciones NULL | sección sede |
| ubicacion | GEOMETRY(Point,4326) NULL | |

### 🏢 electores ⟵ entidad central
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint | indexado, compuesto (tenant_id,seccion_id) |
| seccion_id | bigint FK→secciones | |
| membership_id | bigint FK→memberships | brigadista (membresía) que capturó; garantiza pertenencia al tenant |
| modo_captura | varchar(12) | `loteria\|individual\|evento` |
| loteria_id | bigint FK→loterias NULL | |
| evento_id | bigint FK→eventos NULL | |
| nombre | varchar(160) | |
| telefono | varchar (encrypted) | cifrado en reposo (ADR-004) |
| telefono_hash | varchar(64) NULL | hash determinista normalizado, para dedup; indexado |
| domicilio | varchar (encrypted) NULL | |
| ubicacion | GEOMETRY(Point,4326) NULL | GPS de captura |
| observaciones | text NULL | nota fija / característica permanente |
| consentimiento | boolean | obligatorio true para guardar |
| aviso_privacidad_id | bigint FK→avisos_privacidad | versión aceptada |
| created_at / updated_at | timestamptz | |
| deleted_at | timestamptz NULL | baja lógica (SoftDeletes); Cancelación ARCO la fija y scrubbea la PII; Sprint 7 |

Índices: `(tenant_id, seccion_id)`, `(tenant_id, membership_id)`, `(tenant_id, telefono_hash)`.

### 🏢 interacciones (historial / timeline)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint | |
| elector_id | bigint FK→electores | indexado (tenant_id,elector_id) |
| membership_id | bigint FK→memberships | quién hizo el contacto (membresía) |
| tipo | varchar(12) | `llamada\|correo\|visita\|whatsapp\|sms\|nota` |
| resultado | varchar(16) NULL | `contesto\|no_contesto\|buzon\|correo_enviado\|no_estaba\|rechazo\|compromiso`; NULL para `nota` |
| nota | text NULL | texto libre del evento |
| fecha | timestamptz | cuándo ocurrió (≠ created_at) |
| proximo_seguimiento | date NULL | habilita agenda de pendientes |
| atendido_en | timestamptz NULL | marca el seguimiento como atendido (lo saca de la agenda); Sprint 6 |
| created_at | timestamptz | auditoría (sin updated_at) |

Índices: `(tenant_id, elector_id)`, `(tenant_id, membership_id, proximo_seguimiento)` para la agenda diaria.

---

## Privacidad y auditoría

### 🏢 avisos_privacidad
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint | |
| version | varchar(20) | |
| texto | text | |
| vigente_desde | timestamptz | |

### 🏢 solicitudes_arco
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint | |
| elector_id | bigint NULL | (NULL si ya se borró) |
| tipo | varchar(12) | `acceso\|rectificacion\|cancelacion\|oposicion` |
| estado | varchar(12) | `pendiente\|atendida` |
| solicitado_en / atendido_en | timestamptz | |

---

## Agregados derivados (ADR-003)

### 🏢 cobertura_seccion (tabla materializada / mantenida por jobs)
| Columna | Tipo | Notas |
|---|---|---|
| tenant_id | bigint | |
| seccion_id | bigint FK→secciones | |
| capturados | integer | COUNT de electores en la sección |
| meta | integer | copia de metas_seccion.meta_capturas |
| cobertura | numeric(6,4) | capturados / meta |
| penetracion | numeric(6,4) | capturados / lista_nominal |
| actualizado_en | timestamptz | |
| PK | (tenant_id, seccion_id) | |

> El mapa lee de aquí (join con geom simplificada de `secciones`). Se actualiza por evento `ElectorCapturado` (incremental) + recálculo periódico idempotente.

---

## Diagrama de relaciones (texto)

```
entidades 1─N municipios 1─N secciones ─┐
                                         │ (catálogo global, compartido)
users (persona, global) ─N───┐
                             │ N─M vía memberships (rol por campaña)
tenants 1─N memberships ─────┘
   │            │
   │            ├─ 1─N electores ─1 secciones
   │            │       ├─ N─1 loterias ─1 seccion
   │            │       ├─ N─1 eventos
   │            │       └─ 1─N interacciones
   │            └─ N─M secciones (brigadista_seccion)
   ├─ 1─N metas_seccion ─1 seccion
   ├─ 1─N avisos_privacidad
   ├─ 1─N solicitudes_arco
   └─ 1─N cobertura_seccion ─1 seccion (derivada)

Nota: electores/loterias/interacciones referencian membership_id (no user_id),
lo que ata cada captura a una persona EN una campaña con su rol.
```
