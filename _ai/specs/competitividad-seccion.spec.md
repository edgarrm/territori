# Spec — Competitividad por partido y tipo de sección (Alfa/Beta/Gama)

- **Feature ID**: F2 (iteración post-demo jul-2026)
- **Estado**: Listo para implementar
- **Depende de**: F1 `configuracion-campana.spec.md` (partido, umbrales, toggles, servicio de bloques), `estadisticas_seccion` (ya poblada), `_ai/docs/PRD-analisis-electoral-partido.md`

## Objetivo
Clasificar cada sección como Ganada franca / Competida / Empatada / Perdida (con diferencia de votos) desde la perspectiva del partido de la campaña, y como Alfa/Beta/Gama por lista nominal, y mostrarlo en mapa, /prioridades y detalle de sección respetando los toggles de indicadores.

## Por qué
Es la petición central de la demo: el estratega decide la agenda de campo con "alfas ganadas/competidas primero" y necesita la diferencia de votos, no porcentajes ni índices neutrales. El modo `competitividad` actual (margen_pp entre bloques 2024 fijos) no responde "¿cómo me fue a MÍ?".

## Alcance

### Incluye
- **Enums PHP** (`app/Enums/`): `CompetitividadSeccion` { `GanadaFranca`, `Competida`, `Empatada`, `Perdida`, `SinDatos` } y `TipoSeccion` { `Alfa`, `Beta`, `Gama` }, con helpers `label()` y `color()`.
- **Action `App\Actions\Estadisticas\CalcularCompetitividadSeccion`**: entrada `votos_partidos` (array|null) + partido del tenant (siglas|null) + coaliciones del año + umbral; salida `{ estatus, diferencia_votos, votos_bloque_propio, votos_mejor_rival, bloque_propio }`.
  - Usa el servicio de bloques de F1: bloque propio = bloque del partido; rival = bloque con más votos entre los demás.
  - Estatus: `diferencia >= umbral` → GanadaFranca; `1..umbral-1` → Competida; `0` → Empatada; `< 0` → Perdida; `votos_partidos` vacío/null **o** partido no configurado → SinDatos.
- **Tipo de sección**: método estático `TipoSeccion::desdeListaNominal(?int $listaNominal, int $umbralAlfa, int $umbralBeta): ?TipoSeccion` (≥alfa → Alfa; ≥beta → Beta; ≥1 → Gama; null/0 → null). Cálculo on-read, no se persiste.
- **`MapaController::cobertura()` (GeoJSON)**: agrega a cada feature `competitividad` (string del enum), `diferencia_votos` (int|null) y `tipo_seccion` (string|null), calculados server-side con la config del tenant. Cargar coaliciones/partido una sola vez fuera del loop.
- **`MapaController::resumenSeccion()`**: agrega al bloque `electoral_2024` → `competitividad`, `diferencia_votos`, `votos_bloque_propio`, `votos_mejor_rival`, `bloque_propio`; y al nivel de sección → `tipo_seccion`.
- **`MapaController::prioridades()`**: agrega `competitividad`, `diferencia_votos`, `tipo_seccion` a cada fila.
- **`Mapa.vue`**:
  - Modo `competitividad` re-hecho: pinta por estatus (Ganada franca `#15803d`, Competida `#f59e0b`, Empatada `#6b7280`, Perdida `#dc2626`, Sin datos `#9ca3af` — alineado con `SIN_DATOS` existente); leyenda con conteo por estatus. Si el tenant no tiene partido configurado, el botón del modo muestra aviso "Configura el partido de tu campaña" con link a `/settings/campana` y el pintado cae al margen neutral actual (comportamiento previo).
  - Modo nuevo `tipo_seccion` ("Alfa / Beta / Gama"), categórico de 3 colores + sin datos. El modo `tipo` INE (urbana/rural) se renombra en UI a "Tipo INE" para no confundir.
  - Los botones de modo se filtran según `indicadores` del tenant: `competitividad` → modo competitividad; `tipo_seccion` → modo Alfa/Beta/Gama; `indice_neutral` → modo prioridad; `oportunidad` → modo oportunidad. Los modos base (cobertura, penetración, tipo INE, ganador) siempre visibles.
  - Ficha de sección (panel click): badges de tipo (α/β/γ) junto al número de sección y de estatus con `diferencia_votos` ("Ganada franca · +45 votos" / "Perdida · −178 votos"), respetando toggles.
- **`Prioridades.vue` + controller**: columnas nuevas **Tipo** (badge α/β/γ) y **Estatus** (badge + diferencia con signo); filtros por tipo y por estatus; orden default: tipo (Alfa→Beta→Gama) y dentro, estatus (GanadaFranca→Competida→Empatada→Perdida→SinDatos); la columna del índice neutral (Prioridad) y la de Oportunidad se muestran solo si su toggle está activo. La fórmula neutral 40/40/20 no cambia.
- **`Seccion.vue`** (card "Elección 2024"): badge de estatus + diferencia de votos en el header de la card (sustituye al badge genérico "Ganó {bloque}" cuando hay partido configurado; si no, se conserva el actual); badge de tipo (α/β/γ) junto al título "Sección {n}".

### No incluye
- Persistencia de estatus/tipo en BD (on-read; 523 secciones y el GeoJSON ya se arma por request).
- Cambios al import (`territori:importar-estadisticas`) ni a `votos_partidos`.
- Proyección 2027 / coaliciones futuras en el cálculo (usa las coaliciones del año 2024 del catálogo).
- Pantalla de configuración (F1) y desglose por partido (F3).

## Criterios de aceptación (tests)
1. Dado un tenant con partido MORENA y umbral 30, y una sección cuyo bloque Morena-PVEM suma 156 vs mejor rival 79, cuando se calcula, entonces estatus = GanadaFranca y diferencia = +77.
2. Dado el mismo tenant y una sección con bloque propio 57 vs rival 48, entonces Competida y diferencia = +9; con 50 vs 50 → Empatada (0); con 260 vs 318 → Perdida (−58).
3. Dado un tenant con umbral configurado en 50, una diferencia de +45 da Competida (el umbral del tenant manda sobre el default).
4. Dado un tenant con partido MC (sin coalición 2024), el bloque propio es solo los votos de MC.
5. Dado un tenant **sin partido configurado** o una sección **sin `votos_partidos`**, entonces estatus = SinDatos y `diferencia_votos = null` (sin errores en GeoJSON, resumen ni prioridades).
6. Dado `lista_nominal` = 1223 / 514 / 222 / null con umbrales default, entonces tipo = Alfa / Beta / Gama / null; con `umbral_alfa = 800`, 750 da Beta.
7. Dado `GET /api/cobertura.geojson` con tenant configurado, cada feature incluye `competitividad`, `diferencia_votos`, `tipo_seccion` coherentes con `estadisticas_seccion` (feature test con secciones sembradas conocidas).
8. Dado `GET /api/secciones/{seccion}/resumen`, el payload incluye los campos nuevos en `electoral_2024` y `tipo_seccion`.
9. Dado dos tenants con partidos distintos (MORENA vs PAN) sobre las mismas secciones globales, la misma sección devuelve estatus opuestos coherentes (perspectiva por tenant, sin fugas).
10. Dado un tenant con `indicadores.competitividad = false`, la vista /prioridades no incluye/renderiza la columna de estatus y el mapa no ofrece el modo (verificable por props de Inertia en feature test; Dusk opcional).

## Notas de implementación
- Los ejemplos de los criterios 1–2 salen de la demo (secciones reales del CSV): reproducirlos como fixtures de test da trazabilidad con lo que el cliente validó en vivo.
- En `cobertura()` el cálculo corre por feature: resolver partido+coaliciones+umbral **una vez** y pasar valores al action (o un método `paraColeccion()`) para no hacer queries en loop (Larastan/N+1).
- El front no re-implementa la regla de estatus: consume `competitividad`/`diferencia_votos`/`tipo_seccion` ya calculados. Solo mapea enum → color/etiqueta (agregar a las constantes de paleta; idealmente en el módulo compartido `resources/js/lib/` propuesto en F3).
- `SinDatos` en el mapa usa el mismo gris `SIN_DATOS` (`#9ca3af`) ya definido en `Mapa.vue`.
- Los toggles llegan por props compartidas de Inertia (F1); `prioridades()` también los usa server-side para no mandar columnas apagadas.
- Dusk: agregar un escenario al suite existente (mapa cambia a modo competitividad y la leyenda muestra los 5 estatus) solo si el tiempo lo permite; los feature tests de props cubren lo esencial.
- `vendor/bin/pint --dirty --format agent` al cierre.
