# Spec — Captura de electores (Lotería + Individual)

- **Feature ID**: M1 / M2 (captura) · S1 (dedup) · cierra el `ElectorCapturado` pendiente de Sprint 3
- **Estado**: Implementado (Sprint 4)
- **Depende de**: ADR-001 (multi-tenancy), ADR-002 (PostGIS), ADR-003 (captura unificada y cobertura derivada), ADR-004 (privacidad/cifrado/consentimiento), `_ai/docs/data-model.md` (`electores`, `loterias`, `avisos_privacidad`, `cobertura_seccion`), `_ai/docs/api-contract.md` (captura, lotería, avisos)

## Objetivo
Permitir que un miembro de la campaña capture electores georreferenciados en dos modos (**Lotería** = sesión masiva con sección fija; **Individual** = uno a uno), con validación de teléfono, dedup por `telefono_hash`, consentimiento obligatorio bajo LFPDPPP, escribiendo en `electores` y disparando `ElectorCapturado`, que actualiza incrementalmente `cobertura_seccion` (lo que el mapa de Sprint 3 ya lee).

## Por qué
Es el corazón del producto: sin captura no hay datos, y el mapa de cobertura de Sprint 3 está vivo pero en cero. Sprint 4 lo llena. El modo Lotería es el flujo de campo rápido del brigadista (móvil); el Individual cubre la captura puntual. Cierra el lazo `ElectorCapturado → cobertura_seccion` que Sprint 3 dejó pendiente a propósito.

## Alcance

### Incluye

**Migraciones**
- `avisos_privacidad` (`tenant_id`, `version`, `texto`, `vigente_desde`; índice `(tenant_id, vigente_desde)`). Mínimo viable para satisfacer el FK obligatorio de `electores` y el endpoint "vigente".
- `loterias` (`tenant_id`, `membership_id`, `seccion_id`, `abierta_en`, `cerrada_en` NULL). Índice parcial/compuesto para encontrar la sesión abierta del brigadista: `(tenant_id, membership_id, cerrada_en)`.
- `electores` (entidad central, per data-model): `tenant_id`, `seccion_id`, `membership_id`, `modo_captura`, `loteria_id` NULL, `evento_id` NULL *(columna nullable sin FK por ahora; la tabla `eventos` llega en Sprint 6, el FK se añade entonces)*, `nombre`, `telefono` (encrypted), `telefono_hash` (varchar 64, indexado), `domicilio` (encrypted, NULL), `ubicacion` GEOMETRY(Point,4326) NULL, `observaciones` NULL, `consentimiento` bool, `aviso_privacidad_id` FK, timestamps. Índices: `(tenant_id, seccion_id)`, `(tenant_id, membership_id)`, `(tenant_id, telefono_hash)`.

**Modelos**
- `App\Models\Elector` con `BelongsToTenant`. Casts: `telefono` y `domicilio` → `encrypted`; `consentimiento` → `boolean`; `ubicacion` → cast geográfico Point (mismo patrón que `Seccion::geom`, ver Notas). Relaciones: `seccion()`, `membership()`, `loteria()`, `avisoPrivacidad()`.
- `App\Models\Loteria` con `BelongsToTenant`. `cerrada_en` cast datetime; scope/método `activaDe(Membership)` o `abierta()`; helper `cerrar()`.
- `App\Models\AvisoPrivacidad` con `BelongsToTenant`. Helper `AvisoPrivacidad::vigente()` (último por `vigente_desde`).

**Lógica de dominio**
- `App\Support\Telefono` (o helper estático en la acción): normaliza el teléfono (solo dígitos, últimos 10 para MX) y produce `telefono_hash = hash_hmac('sha256', normalizado, config('app.key'))`. Determinista → dedup estable. Si la normalización da < 10 dígitos, validación falla (no se genera hash basura).
- `App\Actions\Loterias\AbrirLoteria` / `CerrarLoteria`: abre una sesión por `(membership, seccion)`; rechaza abrir una segunda si ya hay una abierta del mismo brigadista (devuelve la existente o 422 — ver criterio 9).
- `App\Actions\Electores\CapturarElector`: orquesta una captura.
  1. Resuelve `membership_id` = membership del usuario autenticado en el tenant activo (`User::membershipEn`). Nunca del request.
  2. Resuelve `seccion_id`: modo `loteria` → heredada de la lotería abierta indicada; modo `individual` → `seccion_id` explícito, o si falta y hay `ubicacion`, resolver por `ST_Contains` sobre `secciones.geom`.
  3. Exige `consentimiento === true` y `aviso_privacidad_id` válido del tenant; si no, rechaza (no persiste).
  4. Calcula `telefono_hash`; si ya existe un elector con ese hash en el tenant → **no duplica**, devuelve el existente marcado como duplicado (la capa HTTP responde 409 con el id existente).
  5. Crea el `Elector` (con `modo_captura`, `loteria_id` si aplica). Dispara `ElectorCapturado($tenantId, $seccionId)`.

**Evento + actualización de cobertura (cierra pendiente Sprint 3)**
- Evento `App\Events\ElectorCapturado` (lleva `tenant_id`, `seccion_id`).
- Listener encolado / job `App\Jobs\ActualizarCoberturaSeccion` (queue, ADR-003): recibe `tenant_id` + `seccion_id`, fija `TenantContext`, **recuenta** `electores` de esa sección (idempotente, no `+1` ciego) y hace `CoberturaSeccion::upsertParaSeccion` con `capturados`, recomputando `cobertura = capturados/meta` y `penetracion = capturados/lista_nominal`. Recontar una sola sección es barato y evita drift por reintentos/carreras.
- `RecalcularCoberturaCommand`: reemplazar el `capturados = 0` hardcodeado por el conteo real `Elector::where('seccion_id', …)->count()` (tenant-scoped). Sigue siendo la red de seguridad idempotente de ADR-003.

**Endpoints** (todos tenant-scoped; captura abierta a cualquier rol miembro del tenant salvo donde se note)
- `POST /api/loterias` `{ seccion_id }` → `{ loteria_id }`
- `POST /api/loterias/{loteria}/cerrar`
- `GET /api/loterias/activa` → sesión abierta del brigadista o `null`
- `POST /api/electores` (body per api-contract) → 201 `{ id, seccion_id, … }` · 409 duplicado · 422 validación
- `GET /api/electores/{elector}` → ficha (scoped)
- `GET /api/secciones/{seccion}/electores` → lista paginada (scoped)
- `GET /api/avisos-privacidad/vigente` → aviso vigente del tenant

**Frontend (Inertia + Vue, mobile-first)**
- `GET /captura` página con dos modos:
  - **Lotería**: elegir sección → abrir sesión → formulario ultra-rápido (nombre, teléfono, checkbox consentimiento) con contador en vivo de capturados de la sesión; botón cerrar sesión. Al reabrir la página, retoma la lotería activa.
  - **Individual**: formulario completo (nombre, teléfono, domicilio opcional, observaciones, GPS opcional, consentimiento). Si no se da sección y sí GPS, el back la resuelve.
  - Mostrar el texto del aviso de privacidad vigente y bloquear "Guardar" sin consentimiento.
- Seeder de apoyo: `DemoTenantSeeder` (o uno nuevo) crea un `AvisoPrivacidad` vigente para el tenant demo, para que la captura funcione end-to-end en local.

### No incluye
- **Modo `evento`** de captura y la tabla `eventos` (Sprint 6). `modo_captura` solo acepta `loteria|individual` por ahora; `evento_id` queda como columna nullable sin FK.
- **Interacciones / timeline** y agenda de seguimientos (Sprint 6).
- **Edición/borrado** de electores (`PUT`/`DELETE`), `solicitudes_arco` y derechos ARCO de cancelación (Sprint 6, privacidad).
- **Export CSV** (Sprint 6).
- **Asignación de zonas a brigadistas** y ratios (Sprint 5). La captura no valida que el brigadista tenga la sección asignada todavía.
- Cifrado a nivel base de datos / KMS externo: se usa el cast `encrypted` de Laravel con `APP_KEY` (suficiente para ADR-004 en esta fase; rotación de llaves fuera de alcance).

## Criterios de aceptación (tests)
1. Dado un brigadista con lotería abierta en la sección X, cuando captura un elector en modo `loteria`, entonces el elector queda con `seccion_id`=X, `membership_id` del brigadista, `loteria_id` de la sesión y `modo_captura='loteria'`.
2. Dado modo `individual` con `seccion_id` explícito y datos válidos, cuando se captura, entonces se persiste con ese `seccion_id` y `modo_captura='individual'`.
3. Dado modo `individual` sin `seccion_id` pero con `ubicacion` dentro de la geometría de una sección, cuando se captura, entonces `seccion_id` se resuelve por `ST_Contains`.
4. Dado `consentimiento=false` (o ausente), cuando se intenta capturar, entonces se rechaza (422) y **no** se crea ningún elector.
5. Dado un teléfono que normaliza a un `telefono_hash` ya presente en el tenant, cuando se captura de nuevo, entonces no se duplica y la respuesta es 409 con el `id` existente.
6. Dado el **mismo** teléfono físico en **dos tenants** distintos, cuando cada uno captura, entonces ambos electores existen (el dedup es por tenant; aislamiento, patrón `BelongsToTenantTest`).
7. Dado que se captura un elector, cuando se procesa `ElectorCapturado`, entonces `cobertura_seccion.capturados` de esa sección refleja el conteo real y `cobertura`/`penetracion` se recomputan; reprocesar el evento deja el mismo resultado (idempotencia).
8. Dado `telefono` que normaliza a menos de 10 dígitos, cuando se captura, entonces validación falla (422) y no se genera hash basura.
9. Dado un brigadista con una lotería ya abierta, cuando intenta abrir otra, entonces se le devuelve/usa la existente (no se crean dos sesiones abiertas simultáneas para el mismo brigadista).
10. Dado un usuario sin membership en el tenant activo, cuando intenta capturar, entonces 403 (no hay `membership_id` que asignar).
11. Dado `GET /api/electores/{elector}` de otro tenant, entonces 404 (global scope).
12. Dado `telefono`/`domicilio` capturados, cuando se leen de la base sin el cast, entonces el valor está cifrado en reposo (ADR-004); con el modelo, se descifra transparente.

## Notas de implementación
- **Cast de `ubicacion` (Point 4326).** Verificar cómo `Seccion` castea `geom` (MultiPolygon) y reusar el mismo mecanismo/paquete para `Point`. Si `Seccion` usa un cast custom o expresiones raw (`ST_GeomFromText`/`ST_AsText`), seguir idéntico patrón — mantener SRID 4326 de punta a punta (ADR-002). No introducir un paquete PostGIS nuevo sin aprobación; preferir el patrón ya existente en el repo.
- **TenantContext en el job.** Los jobs encolados no heredan el `currentTenant` del request. `ActualizarCoberturaSeccion` debe recibir `tenant_id` y hacer `TenantContext::set(Tenant::find($tenantId))` antes de tocar modelos con `BelongsToTenant`. Sin esto, el global scope filtra a cero y el upsert escribe `tenant_id=null`. Cubrir con test corriendo el job directo.
- **`membership_id` y el rol de captura.** Reusar `User::membershipEn(tenant)` (igual que `EnsureRol`). La captura está abierta a cualquier rol con membership activa (brigadista/coordinador/admin); si no hay membership → 403. No se restringe por `rol:` en estos endpoints.
- **Dedup determinista.** `telefono_hash` con HMAC-SHA256 sobre el normalizado usando `APP_KEY`. No guardar el teléfono en claro para dedup; el `telefono` cifrado y el `telefono_hash` son columnas separadas (data-model). El índice `(tenant_id, telefono_hash)` soporta el lookup; **no** poner único a nivel DB (el dedup se maneja en la acción para devolver el existente con 409, no romper con excepción de constraint).
- **PostGIS `ST_Contains`.** Para resolver sección por GPS: `ST_Contains(secciones.geom, ST_SetSRID(ST_MakePoint(lng, lat), 4326))`, limitado a las secciones del municipio del tenant. Si ningún polígono contiene el punto → 422 ("fuera del municipio").
- **Cobertura incremental vs recálculo.** El job recuenta la sección afectada (idempotente). El comando `territori:recalcular-cobertura` sigue siendo el barrido completo. Ambos comparten la fórmula; considerar extraer un método compartido (`CoberturaSeccion::recalcularSeccion` o un pequeño servicio) para no duplicar la aritmética.
- **Frontend.** Reusar la UX de captura/ficha de `territori_demo.html` como referencia visual, no portar tal cual. Lotería = pantalla de captura rápida con foco en el campo teléfono y feedback inmediato (contador). Mobile-first (brigadista en campo).
- **Tests.** Carpeta `tests/Feature/Captura/`. Reusar factories existentes (`MembershipFactory`, `SeccionFactory`/cartografía de prueba `99-test`, `TenantFactory`). Crear `ElectorFactory`, `LoteriaFactory`, `AvisoPrivacidadFactory`. Para `ElectorCapturado`, probar tanto el disparo (Event::fake) como el efecto del job (ejecución directa). Geometría: usar el fixture de prueba `tests/Fixtures/cartografia/99-test` para tener secciones con polígono real y poder probar `ST_Contains`.
