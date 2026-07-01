# ADR-006 — Red ciudadana y rol enlace

- **Estado**: Aceptado
- **Fecha**: 2026-07-01
- **Contexto SDD**: Fase 04 — Architecture (feedback post-demo con cliente)

## Contexto

Tras la demo con el cliente surgió una nueva forma de captura: la **red ciudadana**. Una red agrupa registros que aporta un **enlace** (una persona de confianza de la campaña que reúne contactos de su círculo). El cliente pidió que:

- Cada red tenga un usuario responsable (el **enlace**).
- Exista un **rol nuevo `enlace`** cuyo acceso se limite a *sus* redes: agregar registros y ver los ya agregados, nada más.
- El enlace de una red pueda ser un usuario de **cualquier rol** (un coordinador o brigadista también puede ser enlace de una red, sin dejar de ser lo que ya era).

Hasta ahora los roles eran `admin | coordinador | brigadista` (ADR-004 §4) y los modos de captura `individual | loteria | evento` (ADR-003). El rol vive en `memberships.rol` (ADR-001); no hay paquete de permisos.

## Decisión

1. **Modo de captura `red_ciudadana`**: se suma a `individual | loteria | evento`. La columna `electores.modo_captura` se amplía a `varchar(20)` (`red_ciudadana` son 13 caracteres). Se agrega FK nullable `electores.red_ciudadana_id`, espejo de `loteria_id` / `evento_id`.

2. **Modelo `RedCiudadana`** (tabla `redes_ciudadanas`, tenant-scoped vía `BelongsToTenant`): `tenant_id`, `enlace_membership_id` (FK a `memberships`), `nombre`, `descripcion`, `activa`. La sección **no** es fija por red: cada registro resuelve su sección por `seccion_id` o GPS, como la captura individual.

3. **Rol `enlace` (restringido)**: nuevo valor válido de `memberships.rol` (la columna es `string(20)`, no requiere migración). Semántica:
   - Acceso **solo** a `redes-ciudadanas` (index) + capturar en sus redes + leer el aviso vigente. Todo lo demás (mapa, dashboard, brigadistas, metas, etc.) responde **403**.
   - Enforcement en `routes/web.php`: las rutas del dominio general se agrupan bajo `rol:brigadista,coordinador,admin` (excluye `enlace`); las rutas compartidas (`redes-ciudadanas`, `POST api/electores`, `avisos vigente`) quedan fuera de ese grupo.
   - En captura, el `StoreElectorRequest` restringe al rol `enlace` a `modo_captura = red_ciudadana`.
   - Tras login, el enlace aterriza en `redes-ciudadanas.index` (`Membership::rutaInicial()`), no en el dashboard.

4. **Enlace ≠ rol exclusivo**: el responsable de una red se designa por `redes_ciudadanas.enlace_membership_id`, que puede apuntar a una membership de *cualquier* rol. La creación de redes y la designación del enlace es acción de gestión (`rol:coordinador,admin`). `CapturarElector` valida que quien captura en una red sea su enlace **o** gestión.

5. **Visibilidad de PII**: el enlace ve la PII **completa** de *todos* los registros de sus redes (sin importar quién los capturó), extendiendo la regla de `ElectorController::puedeVerPii()` (ADR-004 §4). Como el endpoint de registros de una red solo lo alcanzan su enlace o gestión, esos registros se sirven sin enmascarar.

## Consecuencias

**Positivas**: nueva vía de captura sin tocar los modos existentes; el rol `enlace` tiene una superficie mínima (defensa en profundidad: middleware de ruta + regla de captura + visibilidad acotada); reutiliza los patrones ya existentes (`BelongsToTenant`, acciones, form requests, `presentar()`).

**Negativas / riesgos**:
- La lista de roles crece a `admin | coordinador | brigadista | enlace`. Cualquier lógica que enumere roles debe contemplar `enlace` (ver `Membership::esEnlace()` / `esGestion()`).
- El enforcement del acceso restringido depende de la organización de `routes/web.php`: rutas nuevas del dominio general deben colgar del grupo `rol:brigadista,coordinador,admin`, o quedarán accesibles al enlace por descuido.

## Relacionado
- Extiende ADR-001 (rol en `memberships`), ADR-003 (modos de captura) y ADR-004 §4 (visibilidad de PII por rol).
- Cambio hermano del mismo lote post-demo: campo `email` opcional en `electores` (PII cifrada con cast `encrypted`, enmascarada como teléfono/domicilio — coherente con ADR-004).
