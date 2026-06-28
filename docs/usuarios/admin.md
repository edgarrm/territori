# Guía del Administrador

Tu trabajo: **dar de alta la campaña, configurar la marca y administrar el equipo y el plan**. Tienes todas las capacidades del coordinador, más la gestión de la campaña.

## Dar de alta una campaña

1. Abre **Crear campaña**.
2. Elige el **estado** y luego el **municipio** (el selector se filtra en cascada). El municipio define el territorio: la campaña hereda sus secciones electorales del INE.
3. Ponle **nombre** a la campaña.
4. (Opcional) Configura tu **marca white-label**:
   - **Nombre de marca** visible (si no, aparece "Territori").
   - **Color** primario de la interfaz.
   - **Subdominio** propio (debe ser único).
5. Al guardar, entras directo a la campaña como administrador.

> La **carga de la cartografía** del municipio (sus secciones en el mapa) la realiza el equipo técnico una vez; ver `docs/operaciones.md`. Si tu mapa aparece vacío, solicítala.

## Invitar miembros

Agregas personas por su **correo**:
- Una misma persona puede estar en **varias campañas** con distinto rol.
- Asignas el rol **por campaña**: `admin`, `coordinador` o `brigadista`.
- Para brigadistas, defines su **meta diaria**.

(La gestión diaria de brigadistas —alta, activar/desactivar, zonas, ratios— está en **Brigadistas**; ver la [guía del coordinador](coordinador.md).)

## Facturación: brigadistas activos

El cobro de tu plan es **por brigadista activo** (no por persona registrada):
- Un brigadista cuenta para facturación cuando está marcado como **activo**.
- Tu plan tiene un **límite de brigadistas activos**. Al intentar activar uno por encima del límite, el sistema te avisa con una opción de **mejorar el plan (upsell)**.
- Desactivar a un brigadista lo saca del conteo (queda registrada la fecha, para trazabilidad del cobro).

> Consejo: desactiva a quienes ya no estén capturando para no pagar de más, y reactívalos cuando vuelvan.

## Cambiar de campaña

Si administras varias campañas, usa el **selector de campaña** (arriba a la izquierda) para cambiar entre ellas sin cerrar sesión.

## Privacidad y cumplimiento

Como administrador eres responsable del manejo de datos personales de tu campaña. Lee la guía de [privacidad y derechos ARCO](privacidad-arco.md): qué datos se guardan, cómo se protegen y cómo atender solicitudes de los titulares.
