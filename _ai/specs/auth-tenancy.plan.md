# Plan de Implementación — Auth, Multi-Tenancy y Alta de Campaña (Sprint 2)

> Acompaña a `specs/auth-tenancy.spec.md`. Laravel 13 + Fortify + Vue 3/Inertia + PostgreSQL. Auth base la da Fortify; aquí montamos tenancy encima.
> Aquí vive la complejidad nueva de Fase 04 (users/memberships, resolución de tenant, white-label). Construir con cuidado y muchos tests de aislamiento.

## Orden de construcción (incremental, TDD)

### Bloque A — Esquema de identidad y tenancy
1. Migración: ajustar `users` a global (quitar tenant_id/rol si vinieran de un scaffold; email único global).
2. Migración: `memberships` (tenant_id, user_id, rol, meta_diaria, activo, activado_en, desactivado_en; unique (tenant_id,user_id); índices).
3. Migración: agregar a `tenants` → `limite_brigadistas`, `marca_nombre`, `marca_logo_url`, `marca_color`, `subdominio` (unique).
4. Modelos: `User` (Authenticatable, global), `Membership` (belongsTo Tenant, User), `Tenant` (hasMany Membership; belongsTo Municipio).

### Bloque B — Scoping multi-tenant (núcleo)
5. `TenantContext` (singleton): guarda el tenant activo de la request. Setter/getter `currentTenant()`.
6. Trait `BelongsToTenant`: global scope `WHERE tenant_id = currentTenant()->id` + hook `creating` que asigna `tenant_id`. Guarda contra `tenant_id` en `$fillable`.
7. Middleware `ResolveTenant`: corre tras auth; fija el tenant en `TenantContext`. Lanza/redirige si el usuario no tiene membership activa en ese tenant.
8. Helper `currentMembership()`: la membership del user autenticado en el tenant activo (con su rol).

> **Tests de aislamiento aquí, antes de seguir.** Montar 2 tenants, verificar no-fuga en lectura y escritura, ignorar tenant_id de request, asignación automática. Estos tests son la red de seguridad de todo el producto.

### Bloque C — Resolución de tenant (subdominio + selector)
9. Middleware `ResolveTenantFromSubdomain`: temprano (antes de auth) mira el host; si matchea `tenants.subdominio`, fija candidato de tenant para branding del login.
10. Flujo post-login: 0 memberships → mensaje; 1 → entra directo; varias y sin subdominio → pantalla selector de campaña (Inertia).
11. Guardar tenant activo en sesión; `ResolveTenant` lo lee.

### Bloque D — Autorización por rol
12. Gate/middleware `rol:coordinador`, `rol:admin`, etc., leyendo de `currentMembership()->rol`. Nunca de `$user`.
13. Rutas agrupadas por rol. Brigadista bloqueado de vistas de coordinador/admin (403).

### Bloque E — Alta de campaña y miembros
14. Controlador/acción `CrearCampana` (rol admin SaaS): valida municipio_id existe (catálogo global), subdominio único, crea tenant + marca. No copia secciones.
15. `InvitarMiembro`: encuentra/crea `user` por email, crea `membership` {rol, meta_diaria?}. Al activar brigadista, valida `limite_brigadistas` → bloqueo/upsell.
16. `ActivarMembership`/`DesactivarMembership`: setean `activo` + timestamps.

### Bloque F — White-label en UI
17. Compartir vía Inertia (`HandleInertiaRequests`) la marca del tenant activo: `{ nombre, logo_url, color }` con fallback "Territori"/azul.
18. Layout raíz Vue: inyecta `color` como CSS var `--brand`, muestra logo/nombre. Pantalla de login usa la marca resuelta por subdominio.

## Tests (TDD — por bloque)

- **Aislamiento (Bloque B)**: los 4 criterios 1-4 del spec. Helper `actingInTenant($tenant, $user)`.
- **Identidad/membership**: criterios 5-8 (user en 2 campañas con roles distintos; rol desde membership).
- **Resolución**: criterios 6-7 (subdominio fija; selector vs directo).
- **Autorización**: 9-10.
- **Alta campaña/white-label**: 11-13.
- **Límite/facturación**: 14-15.

## Decisiones y trampas a evitar

- **No** poner `rol` en `users`. El error clásico es `$user->rol`; aquí no existe. Usar `currentMembership()->rol`. Documentado en CONTEXT.
- **No** confiar en `tenant_id` del request en ningún punto.
- **No** aplicar `BelongsToTenant` a catálogos globales (Entidad/Municipio/Seccion). Test de regresión que confirme que un query a Municipio no se filtra por tenant.
- El selector de campaña y el subdominio pueden coexistir; el subdominio gana si está presente.
- Sembrar (seeder) un tenant demo + un admin para desarrollo, reutilizando los catálogos cargados en Sprint 1.

## Definición de Done

- Migraciones aplican en limpio; seeders crean tenant demo + usuarios de cada rol.
- Suite de aislamiento verde (no-fuga entre tenants comprobada en cada modelo tenant-scoped).
- Login + subdominio + selector funcionando; rol leído de membership.
- Alta de campaña con municipio + white-label; límite de brigadistas validado al activar.
- Layout con branding por tenant.
- Una persona puede operar en 2 campañas con roles distintos (test verde).
