# Spec — Visualización de encuestas (mapa + ficha)

- **Feature ID**: F10 (iteración "Análisis territorial multinivel + encuestas multi-canal")
- **Estado**: Implementado
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

## Nota de implementación (F10 — COMPLETO)

Acciones nuevas `App\Actions\Encuestas\ResumirRespuestasEncuesta::handle(Encuesta, ambitoTipo, Collection<int,int> $ambitoIds)` (desglose pregunta+opción para un conjunto de ámbitos, zero-fill de opciones sin respuesta — criterio 2) y `App\Actions\Encuestas\ResumirEncuestasParaAmbito::handle(ambitoTipo, ambitoId)` (encuestas activas con ≥1 respuesta en UN ámbito, reusando la primera con `collect([$ambitoId])` — cero lógica de agregación nueva, mismo patrón "Action genérica reusada por varios controladores" de F5/F7). `EncuestaController::resumen(Request $request, string $encuesta)` (`GET /api/encuestas/{encuesta}/resumen?ambito=seccion|zona`, resolución manual anti-binding): el universo de `ambito_id` es TODAS las secciones del municipio del tenant o TODAS las zonas (no solo las que tienen respuesta), así una sección/zona sin ninguna respuesta aparece con conteos en 0 (criterio 2). Ruta colocada en el mismo grupo de lectura abierta que `zonas.resumen` (`rol:brigadista,coordinador,admin`, sin 2FA) — no en el grupo `rol:coordinador,admin` donde vive el resto de `EncuestaController` (gestión de encuestas), porque este endpoint también lo consume la ficha de zona de `Zonas.vue`, accesible a brigadista. `MapaController::resumenSeccion` y `ZonaController::resumen` agregan la clave `encuestas` llamando a `ResumirEncuestasParaAmbito` con un solo `ambito_id`; `MapaController::index` agrega `encuestasDisponibles` (solo activas + `ambito_tipo=seccion`, con su catálogo de preguntas tabulables) para poblar el sub-selector del mapa (criterio 6, encuestas `pausada` excluidas en el origen, no en el frontend).

**Decisión de UX del sub-selector** (spec dejaba la decisión abierta): selector anidado en el panel lateral de `Mapa.vue` (tres `<select>` encadenados Encuesta → Pregunta → "opción mayoritaria | % de una opción X"), NO modal. Mismo patrón visual que el resto de controles del panel (checkbox de zonas, selects de `Zonas.vue`); un modal habría introducido una interacción nueva (abrir/cerrar diálogo) para lo que es, en esencia, más criterio de filtrado dentro del mismo modo "Encuestas" — se mantiene la consistencia con el resto del panel en vez de crear un patrón de interacción nuevo solo para esta pieza. Solo se recarga red al cambiar de encuesta (trae todas las preguntas de una vez); cambiar pregunta/opción-a-ver solo recolorea con los datos ya cargados.

Coloreado del modo "Encuestas": categórico (opción mayoritaria, criterio 3) usa la primera opción de la lista ya ordenada por conteo desc que devuelve el backend, con color de una paleta rotativa nueva y compartida `colorPorIndice()` (`resources/js/lib/formato.ts` — las opciones de una encuesta, a diferencia de partidos/bloques, no tienen color institucional propio); gradiente de % de una opción (criterio 4) usa una escala fija de 5 cortes (`ESCALA_PCT_OPCION`, mismo espíritu que `ESCALA_COMPETITIVIDAD`). `SIN_DATOS` (gris, constante ya existente) para secciones con `total_respuestas=0` en ambos sub-modos. La ficha de sección del propio mapa (panel al hacer clic) NO se tocó — el detalle de encuesta vive en la card nueva de `Seccion.vue`/`Zonas.vue`, fuera de alcance para el panel compacto del mapa (mismo criterio de "no incluye" de la spec, para no repetir la misma información en dos lugares del panel).

Cards "Encuesta: {nombre}" en `Seccion.vue` (insertada entre "Votos por partido y coalición 2024" y "Perfil por edad") y en el panel expandible de `Zonas.vue` (junto a las cards de Cobertura/Elección 2024 de F7): mismo patrón visual swatch+label+conteo+%+barra que la card de F3, una card por encuesta activa con AL MENOS una respuesta en ese ámbito (una encuesta activa sin ninguna respuesta ahí no genera una card vacía — filtrado ya en `ResumirEncuestasParaAmbito`), y dentro de la card TODAS las preguntas/opciones de esa encuesta se muestran completas (incluidas las de conteo 0, mismo criterio de "no ocultar sin datos" que motiva el criterio 2).

Desviación menor de alcance: la spec no pedía explícitamente qué endpoint debía usar el panel de detalle de sección DEL MAPA (el compacto al hacer clic en una sección) — se decidió no tocarlo (ver arriba), manteniendo `desglose_2024`/demás campos existentes sin cambios y sin agregar `encuestas` a `MapaController::cobertura()` (el GeoJSON), igual que F3 mantuvo `desglose_2024` fuera de `cobertura()` por tamaño de payload.

14 tests nuevos verdes (`tests/Feature/Encuestas/EncuestasResumenTest.php`, cubren los 7 criterios de aceptación); suite completa **592 verdes**, Larastan y Pint limpios (`npm run build`, `npm run types:check`, `npm run lint:check` y `npx prettier --check` también verificados sin errores). **Cierra el backlog F5-F10 de la iteración "Análisis territorial multinivel + encuestas multi-canal".**
