# Spec — Visualización de encuestas (mapa + ficha)

- **Feature ID**: F10 (iteración "Análisis territorial multinivel + encuestas multi-canal")
- **Estado**: Borrador
- **Depende de**: F8 `encuestas-nucleo-canal-nativo.spec.md` (modelo de datos, funciona igual sin importar el canal), F7 `zonas-cobertura-estadisticas.spec.md` (ficha de zona)

## Objetivo
Mostrar el resultado de cualquier encuesta — sin importar el canal por el que llegaron sus respuestas — en el mapa y en la ficha de sección/zona, igual que hoy se muestra cobertura o competitividad.

## Por qué
Cierra el ciclo de valor de la Fase 3: sin visualización, las respuestas tabuladas en F8/F9 no le sirven de nada al coordinador/candidato para decidir nada.

## Alcance

### Incluye
- `EncuestaController::resumen(Encuesta $encuesta, string $ambitoTipo)` (`GET /api/encuestas/{encuesta}/resumen?ambito=seccion|zona`) — por cada `ambito_id`, desglose de cada pregunta con `conteo` y `%` calculado sobre el total de esa pregunta+ámbito, mismo formato de respuesta en espíritu que `DesglosarVotosSeccion` (F3: nombre/color/total/%/barra).
- `resources/js/pages/Mapa.vue`: nuevo modo "Encuestas" con sub-selector dinámico "Encuesta → Pregunta → (opción mayoritaria | % de una opción)" — a diferencia de los modos fijos existentes, la lista de encuestas/preguntas es dinámica. Colorea por opción mayoritaria (mismo patrón categórico que el modo `ganador`) o por gradiente de % de una opción específica (mismo patrón que la escala de `competitividad`).
- `resources/js/pages/Seccion.vue` y la ficha de zona (F7): nueva card "Encuesta: {nombre}" por pregunta activa con respuestas en ese ámbito, reusando el patrón visual de la card "Votos por partido y coalición (2024)" de F3 (swatch + label + subtotal + % + barra).
- Solo encuestas con `estado=activa` aparecen como opción seleccionable.

### No incluye
- Exportación a CSV/PDF de resultados de encuesta.
- Comparación entre encuestas o series de tiempo (evolución de una encuesta a lo largo de varias sincronizaciones).
- Cualquier cambio a la lógica de tabulación de F8/F9 — este spec es puramente de lectura/presentación.

## Criterios de aceptación (tests)
1. Dado una encuesta con respuestas tabuladas en 3 secciones, al pedir `GET /api/encuestas/{encuesta}/resumen?ambito=seccion`, devuelve por cada sección el desglose de cada pregunta con conteo y `%` calculado sobre el total de esa pregunta+sección.
2. Dado una sección sin ninguna respuesta tabulada para esa encuesta, el resumen la devuelve con conteos en 0 (no la omite) — para diferenciar "no respondida" de "no existe".
3. Dado el modo "Encuestas" en `Mapa.vue`, al seleccionar una encuesta y una pregunta, el mapa colorea cada sección por su opción mayoritaria (categórico).
4. Dado el mismo modo eligiendo "ver por opción X", el mapa colorea por gradiente del `%` de esa opción específica.
5. Dado `Seccion.vue` con una encuesta activa que tiene respuestas en esa sección, se muestra la card "Encuesta: {nombre}" con sus preguntas y barras.
6. Dado una encuesta `estado=pausada`, no aparece como opción seleccionable en el modo "Encuestas" del mapa.
7. Dado dos tenants distintos con encuestas del mismo nombre, el `resumen()` nunca mezcla datos entre tenants.

## Notas de implementación
- Reusar el patrón visual de la card "Votos por partido y coalición" de F3 (`Seccion.vue`) y las constantes de color (`GANADORES`/`SIN_DATOS`) de `Mapa.vue` donde aplique para el modo categórico.
- Confirmar con el cliente la UX exacta del selector dinámico "Encuesta → Pregunta → opción" (selector anidado en el panel lateral vs. modal "Configurar vista de encuesta") antes de construir el componente — es la pieza de UI más distinta a lo que ya existe en `Mapa.vue`.
- `vendor/bin/pint --dirty --format agent` al cierre.
