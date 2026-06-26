# Spec — Auth, Multi-Tenancy y Alta de Campaña (Sprint 2)

- **Feature ID**: M1 + M2 (resto) / Sprint 2
- **Estado**: Listo para implementar
- **Depende de**: ADR-001 (tenancy + users/memberships), ADR-005 (white-label + facturación), Sprint 1 (catálogos cargados)

## Objetivo

Establecer la identidad de usuarios (personas físicas), la pertenencia a campañas vía `memberships` con rol por campaña, la resolución del tenant activo (por subdominio o selección tras login), el aislamiento automático de datos por `tenant_id`, y el alta de una campaña (tenant) eligiendo municipio y marca white-label.

## Por qué

Es el esqueleto de seguridad y multi-tenancy sobre el que cuelga TODO lo demás. Sin esto no hay aislamiento entre campañas (requisito legal y comercial) ni forma de que una persona opere en varias campañas. Concentra la complejidad de las decisiones de Fase 04.

## Alcance

### Incluye
- **Identidad**: modelo `User` global (el de Fortify/starter kit), email único, **sin tenant_id ni rol** (se le quita cualquier resto del scaffold). Fortify ya provee login, registro, reset, verificación email, 2FA — se reutilizan, no se reconstruyen.
- **Pertenencia**: `memberships` (tenant_id, user_id, rol, meta_diaria, activo, activado_en, desactivado_en); único (tenant_id, user_id).
- **Auth**: Fortify maneja login/logout/registro. NUESTRO trabajo es lo que pasa **después** de autenticar: resolver tenant activo:
  - Si entra por subdominio `{sub}.dominio` → tenant fijado por `tenants.subdominio`.
  - Si entra por dominio raíz y tiene 1 membership → ese tenant. Si tiene varias → selector de campaña.
  - El `tenant_id` activo se guarda en sesión.
  - Ajustar el `CreateNewUser` action de Fortify si el registro debe crear/ligar membership.
- **Scoping**: trait `BelongsToTenant` + `TenantScope` global que filtra por el tenant de sesión. Hook `creating` que asigna `tenant_id`. Helper `currentTenant()` / `currentMembership()`.
- **Autorización por rol**: el rol se lee de la membership activa. Middleware/Gate para `admin|coordinador|brigadista`.
- **Alta de campaña** (rol admin SaaS): crear tenant {nombre, municipio_id, plan, limite_brigadistas, marca_nombre, marca_logo_url, marca_color, subdominio}. Al crear, NO copia secciones (son catálogo global); solo guarda el `municipio_id`.
- **Invitar/crear miembros**: crear membership para un user (existente por email o nuevo) con rol y, si brigadista, meta_diaria. Validar `limite_brigadistas` al activar brigadistas.
- **White-label en UI**: layout lee marca del tenant activo (nombre, logo, color como CSS var); fallback "Territori".

### No incluye
- Captura de electores (Sprint 4).
- Mapa/cobertura (Sprint 3).
- Asignación de zonas y ratios de brigadista (Sprint 5).
- **Reconstruir** login/reset/2FA: ya los da Fortify; solo se configuran/ajustan.
- Política de cálculo de facturación (solo se registran activado_en/desactivado_en).

## Criterios de aceptación (tests)

### Aislamiento (lo más crítico — ADR-001)
1. Dos tenants A y B, cada uno con electores. Un coordinador de A consulta electores → solo ve los de A, nunca de B.
2. Una consulta directa a un modelo tenant-scoped sin tenant en sesión no devuelve datos de ningún tenant (o lanza excepción controlada), nunca todos.
3. Un request no puede inyectar `tenant_id` para leer/escribir en otro tenant (se ignora el del request; se usa el de sesión).
4. Al crear un registro tenant-scoped, su `tenant_id` se asigna automáticamente al tenant de sesión.

### Identidad y membership
5. Un mismo `user` (email único) puede tener membership en A como coordinador y en B como brigadista, con metas distintas.
6. Login por subdominio fija el tenant correcto antes de mostrar contenido.
7. Login por dominio raíz con varias memberships muestra selector; con una sola, entra directo.
8. El rol efectivo se lee de la membership activa, no de `users`.

### Autorización
9. Un brigadista no puede acceder a rutas de coordinador/admin (403).
10. Solo admin SaaS puede crear tenants.

### Alta de campaña y white-label
11. Crear campaña con municipio 12 (Mazatlán) deja el tenant con ese `municipio_id`; las secciones siguen siendo las globales (no se duplican).
12. `subdominio` es único; intentar repetir falla con mensaje claro.
13. El layout muestra `marca_nombre` y `marca_color` del tenant; sin marca, muestra "Territori".

### Facturación / límite
14. Activar un brigadista por encima de `limite_brigadistas` se bloquea con mensaje de upsell (no error genérico).
15. Activar/desactivar una membership registra `activado_en`/`desactivado_en`.

## Notas de implementación

- **TenantScope**: implementar como Global Scope de Eloquent; el tenant de sesión se resuelve en un middleware que corre tras auth y lo guarda en un singleton/contexto (`app()->instance('currentTenant', ...)`).
- **Resolución por subdominio**: middleware temprano que mira el host; si hay subdominio que matchea `tenants.subdominio`, lo fija (incluso pre-login para branding del login).
- **users vs auth**: el `User` autenticable es la persona; la autorización por rol NO va en el User sino en un check de membership. Evitar `$user->rol` (no existe); usar `currentMembership()->rol`.
- **Asignación tenant_id**: en el trait `BelongsToTenant`, hook `creating` que setea `tenant_id = currentTenant()->id` si no está. Prohibido fillable `tenant_id`.
- **Catálogo global**: `Entidad/Municipio/Seccion` NO llevan el trait. Test de regresión: que el scope no se aplique a ellos.
- **Tests de aislamiento**: son la red de seguridad central. Crear helper de test que monte 2 tenants con datos y verifique no-fuga en cada modelo tenant-scoped.
- **White-label assets**: logo en object storage; el color se inyecta como `--brand` en el layout raíz.

## Definición de Done

- Migraciones de `users` (ajuste a global), `memberships`, y campos white-label/límite en `tenants`.
- Login + resolución de tenant (subdominio y selector) funcionando.
- `BelongsToTenant` + `TenantScope` aplicados; suite de tests de aislamiento verde.
- Alta de campaña + alta de miembros + validación de límite de brigadistas.
- Layout con branding por tenant.
- CONTEXT.md sigue siendo fiel (sin contradicciones).
