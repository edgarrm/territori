# ADR-004 — Privacidad y Protección de Datos Personales

- **Estado**: Aceptado
- **Fecha**: 2026-06-26
- **Contexto SDD**: Fase 04 — Architecture
- **Nota**: Esto no es asesoría legal. Las decisiones aquí deben validarse con un abogado especializado en protección de datos y materia electoral antes de operar comercialmente.

## Contexto

El sistema almacena datos personales de electores (nombre, teléfono, domicilio, ubicación). En México aplica la **LFPDPPP**. Decisión de producto ya tomada: **no se captura intención de voto ni afiliación política** (quedan fuera de alcance — W1 del PRD), lo que evita el régimen de datos sensibles del art. 9. Aun así, los datos de contacto requieren tratamiento conforme a ley.

## Decisión

1. **Minimización de datos**: solo se capturan campos con finalidad declarada (contacto de campaña). No se capturan datos sensibles. El esquema no tiene columna para intención de voto.

2. **Consentimiento y aviso de privacidad**:
   - Tabla `avisos_privacidad` versiona el texto del aviso.
   - Cada `elector` referencia la versión de aviso bajo la que se capturó (`aviso_privacidad_id`) y un flag `consentimiento` con timestamp.
   - El brigadista no puede guardar sin marcar consentimiento.

3. **Derechos ARCO** (Acceso, Rectificación, Cancelación, Oposición):
   - Endpoint/operación para exportar todos los datos de un elector (Acceso/portabilidad).
   - Borrado real (no solo soft-delete) ante solicitud de Cancelación, con registro de la solicitud en `solicitudes_arco` para trazabilidad.

4. **Seguridad técnica**:
   - Cifrado en reposo de columnas sensibles (teléfono, domicilio) usando cast `encrypted` de Laravel.
   - TLS en tránsito (obligatorio).
   - Control de acceso por rol (`admin|coordinador|brigadista`); un brigadista solo ve los electores que le corresponden, no toda la campaña.
   - Auditoría: registrar quién accede/modifica datos de electores.

5. **Aislamiento entre campañas**: garantizado por ADR-001 (tenant_id).

6. **Padrón del INE**: NO se ingieren nombres del padrón electoral (uso restringido). Solo se usa cartografía pública (polígonos, números de sección) y el conteo agregado de lista nominal por sección (estadística pública). Los nombres en el sistema provienen exclusivamente de captura propia con consentimiento.

## Consecuencias

**Positivas**: cumplimiento defendible desde el diseño; menor superficie de riesgo al excluir datos sensibles; confianza del cliente.

**Negativas / costos**:
- El cifrado de columnas impide buscar por teléfono/domicilio con `LIKE` directo. Mitigación: para dedup, almacenar un **hash determinista** del teléfono normalizado (columna `telefono_hash` indexada) para comparar sin exponer; o usar cifrado determinista solo para esa columna.
- El flujo de consentimiento añade fricción a la captura rápida. Mitigación: en modo Lotería, consentimiento verbal con registro de un solo tap, y aviso visible en pantalla.

## Pendiente de validar con abogado
- Texto exacto del aviso de privacidad.
- Si el responsable del tratamiento es la campaña (tenant) o el operador del SaaS, y cómo se refleja en contratos de encargo de tratamiento.
- Requisitos específicos del INE/autoridad electoral aplicable según el tipo de elección.
