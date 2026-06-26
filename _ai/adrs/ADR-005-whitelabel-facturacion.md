# ADR-005 — White-label y Facturación por Brigadista Activo

- **Estado**: Aceptado
- **Fecha**: 2026-06-26
- **Contexto SDD**: Fase 04 — Architecture
- **Decisiones de producto**: P3 (white-label desde el inicio), P4 (cobro por brigadista activo)

## Contexto

Dos decisiones de negocio impactan la arquitectura:
- **P3**: cada campaña debe ver su propia marca (logo, color, nombre), no "Territori".
- **P4**: el cobro es por brigadista activo, no precio fijo ni por volumen de electores.

## Decisión

### White-label
Campos de marca en `tenants`: `marca_nombre`, `marca_logo_url`, `marca_color`, `subdominio` (único).

**Resolución del tenant por subdominio**: `{campaña}.territori.app` resuelve el tenant antes del login, lo que permite mostrar marca correcta en la pantalla de acceso y simplifica el caso multi-campaña (el subdominio fija el tenant activo). Si no hay subdominio, se resuelve tras login por la(s) membership(s) del usuario.

- Los assets de marca (logo) se sirven desde almacenamiento de objetos; nunca confiar en URLs externas arbitrarias.
- El color primario se inyecta como CSS custom property en el layout; el resto del design system se deriva de ahí.
- Fallbacks a marca "Territori" si los campos están vacíos.

### Facturación por brigadista activo
La unidad facturable = `membership` con `rol='brigadista'` y `activo=true` en un tenant.

- `tenants.limite_brigadistas` define el tope por plan (NULL = sin tope). Activar un brigadista por encima del límite se bloquea o dispara upsell.
- Trazabilidad: `memberships.activado_en` / `desactivado_en` permiten facturar por periodo (ej. brigadistas-día o pico mensual). La métrica exacta de cobro (pico del mes vs. promedio) se decide en producto; el dato queda registrado para soportar cualquiera.
- Un job/consulta de facturación: `COUNT(memberships WHERE tenant_id=? AND rol='brigadista' AND activo=true)`.

## Consecuencias

**Positivas**: marca por cliente sin instancias separadas; modelo de cobro alineado al valor (más brigadistas = más uso = más pago); el dato de activación soporta varias políticas de cobro sin rehacer el esquema.

**Negativas / riesgos**:
- Resolución por subdominio añade configuración DNS/wildcard y manejo de tenant "no encontrado". Mitigación: soportar también acceso por dominio raíz + selector de campaña tras login.
- Desactivar/reactivar brigadistas para evadir cobro: si se factura por pico mensual, se neutraliza. Documentar la política elegida.
- El límite de brigadistas debe validarse en el punto de activación de membership, con mensaje claro (no error genérico).

## Pendiente de producto
- Política de cobro exacta: ¿pico de brigadistas activos del mes, promedio, o conteo al corte? El esquema ya lo soporta; falta la regla de negocio.
