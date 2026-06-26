# ADR-001 — Estrategia de Multi-Tenancy

- **Estado**: Aceptado
- **Fecha**: 2026-06-26
- **Contexto SDD**: Fase 04 — Architecture

## Contexto

Territori es un SaaS donde cada campaña política es un cliente (tenant) que paga. Los datos de electores de una campaña **jamás** deben ser visibles para otra: es un requisito legal (LFPDPPP) y comercial crítico. Necesitamos decidir el modelo de aislamiento de datos.

Opciones evaluadas:
1. **Base de datos por tenant** — máximo aislamiento, pero operacionalmente caro (migraciones × N, backups × N, conexión dinámica). Sobredimensionado para el volumen esperado.
2. **Schema de Postgres por tenant** — aislamiento fuerte, pero complejidad de routing y migraciones por schema.
3. **Single-database con columna `tenant_id`** — un solo esquema, scoping por columna en cada query. Simple de operar, requiere disciplina para no filtrar datos.

## Decisión

Adoptamos **single-database con `tenant_id`** (opción 3), igual que el CRM LATAM existente, aplicando aislamiento en tres capas:

1. **Global Scope de Eloquent**: un `TenantScope` que inyecta automáticamente `WHERE tenant_id = ?` en TODOS los modelos que usen el trait `BelongsToTenant`. El `tenant_id` se resuelve del contexto de sesión (ver más abajo), nunca de un parámetro de request.
2. **Asignación automática**: al crear cualquier registro tenant-scoped, un `creating` hook setea el `tenant_id` actual. Nunca se acepta `tenant_id` del cliente.
3. **Foreign keys + índices**: toda tabla de datos de campaña lleva `tenant_id` indexado y compuesto con las FKs frecuentes (ej. `(tenant_id, seccion_id)`).

### Identidad vs. pertenencia (decisión P2 — brigadista multi-campaña)

Una persona puede participar en **varias campañas**. Por eso separamos:

- **`users`** = la persona física. Identidad y credenciales (email único global). **NO** lleva `tenant_id` ni `rol`. Es un catálogo global.
- **`memberships`** = pivote `(tenant_id, user_id, rol, meta_diaria, activo)`. Representa "esta persona participa en esta campaña con este rol". El `rol` y la `meta_diaria` son **de la membresía**, no de la persona: alguien puede ser coordinador en A y brigadista en B.

**Resolución del tenant activo**: al autenticarse, si el usuario tiene membresías en varios tenants, elige/confirma con cuál opera (o se resuelve por subdominio white-label, ver ADR-005). El `tenant_id` activo vive en la sesión. El `TenantScope` lo usa. El acceso se autoriza verificando que existe una `membership` **activa** del usuario en ese tenant; el rol efectivo se lee de esa membership.

**Referencias de captura**: `electores`, `loterias` e `interacciones` referencian `membership_id` (no `user_id` directo). Esto garantiza que quien captura pertenece al tenant y preserva el rol con el que actuó.

**Sin jerarquía de coordinadores** (decisión P2b): un solo nivel coordinador→brigadistas. No hay `parent_id`. Se reconsiderará solo si un cliente lo exige.

**Catálogos globales** (no tenant-scoped): `municipios`, `secciones`, `entidades`, `distritos`. Son cartografía del INE compartida por todos. NO llevan `tenant_id`.

## Consecuencias

**Positivas**: operación simple (una BD, una migración), costo bajo, queries directos. Reutiliza el patrón ya probado en el CRM.

**Negativas / riesgos**: una query que olvide el scope filtra datos entre tenants. Mitigación:
- El trait `BelongsToTenant` es obligatorio; revisión en code review.
- Tests automáticos de aislamiento: crear 2 tenants, verificar que A nunca ve datos de B.
- Prohibido usar `withoutGlobalScopes()` salvo en jobs de sistema explícitamente documentados.

**Regla para la lista nominal**: el conteo agregado por sección (estadística pública del INE) vive en el catálogo global `secciones.lista_nominal`. Las **metas** por campaña viven en `metas_seccion` (tenant-scoped). Así un dato público se comparte y un dato de campaña se aísla.
