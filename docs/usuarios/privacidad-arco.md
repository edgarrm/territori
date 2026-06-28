# Privacidad y derechos ARCO

Territori maneja datos personales de contactos ciudadanos. Esta guía explica, en lenguaje claro, qué se guarda, cómo se protege y cómo atender las solicitudes de las personas. Está diseñada para cumplir con la **Ley Federal de Protección de Datos Personales en Posesión de los Particulares (LFPDPPP)** de México.

> Esta guía es orientativa para el uso de la plataforma; no sustituye la asesoría legal de tu campaña.

## Qué datos se guardan (y cuáles NO)

**Sí se guardan** (solo con consentimiento de la persona):
- Nombre, teléfono, domicilio (opcional), observaciones.
- La sección donde se capturó y quién la capturó.

**Nunca se guardan:**
- ❌ **Intención de voto.**
- ❌ **Afiliación política.**

Estos campos están **fuera de alcance por diseño** para reducir el riesgo legal. La plataforma no permite registrarlos.

## Cómo se protegen los datos

- **Consentimiento obligatorio**: no se puede guardar un contacto sin que se haya aceptado el **aviso de privacidad** vigente. Queda registrada la versión del aviso aceptada.
- **Cifrado en reposo**: el **teléfono** y el **domicilio** se guardan cifrados. No aparecen en crudo dentro de la base ni en las vistas.
- **Aislamiento por campaña**: los datos de una campaña no son visibles desde otra.
- **Acceso por rol**: las acciones sensibles (cancelar un contacto, exportar) están restringidas a coordinador/admin.

## Derechos ARCO

Los titulares de los datos pueden ejercer sus derechos **ARCO**:

| Derecho | Qué significa |
|---|---|
| **A**cceso | Saber qué datos suyos tienes. |
| **R**ectificación | Corregir datos inexactos. |
| **C**ancelación | Pedir que se eliminen sus datos. |
| **O**posición | Oponerse al tratamiento de sus datos. |

### Cómo registrar una solicitud
Cualquier miembro puede **registrar una solicitud ARCO** desde la ficha del elector. La solicitud queda en estado **pendiente** para su atención.

### Cómo atender una cancelación
La **cancelación** (eliminar a un contacto) solo la puede ejecutar **coordinador o admin**. Cuando se cancela un contacto, Territori:

1. **Lo da de baja** (baja lógica): deja de aparecer en listas, mapa, reportes y exportaciones.
2. **Borra (scrub) su información personal**: nombre, teléfono, domicilio, ubicación y observaciones se eliminan/anonimizan, de modo que ya no quedan datos identificables.
3. **Deja registro de la solicitud** ARCO como atendida (cumplimiento), sin conservar la PII.
4. **Recalcula la cobertura** de la sección para que los números queden correctos.

> Como el teléfono se borra al cancelar, esa persona puede volver a capturarse después si da su consentimiento de nuevo (no queda "bloqueada" por el duplicado).

## Buenas prácticas para el equipo

- Exporta a CSV solo cuando sea necesario y **resguarda el archivo** (contiene datos personales descifrados).
- Mantén el aviso de privacidad de tu campaña actualizado.
- Atiende las solicitudes ARCO con prontitud y deja constancia.
- Recuerda al equipo: **nunca** preguntar ni anotar intención de voto.
