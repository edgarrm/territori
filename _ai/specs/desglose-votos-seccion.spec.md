# Spec — Desglose de votos por partido y coalición en detalle de sección

- **Feature ID**: F3 + F4 (iteración post-demo jul-2026)
- **Estado**: Listo para implementar
- **Depende de**: F1 `configuracion-campana.spec.md` (catálogo de partidos/coaliciones y servicio de bloques), `estadisticas_seccion.votos_partidos` (ya poblada), `_ai/docs/PRD-analisis-electoral-partido.md`

## Objetivo
Mostrar en el detalle de sección (y en versión compacta en la ficha del mapa) el desglose de votos 2024 por partido y por opción de boleta, agrupado por bloque/coalición, colocado antes de la card "Perfil por edad"; y mover el botón "Agregar elector" a la card "Electores".

## Por qué
El estratega lee matices en el desglose que el bloque esconde ("ahí veo un panismo más fuerte que un priismo") y validó los datos sumando opciones contra el total. La complicación que pidió resolver: un partido aparece en varias opciones de boleta (MORENA, PVEM_MORENA…), así que la agrupación por bloque es la única forma legible de presentarlo.

## Alcance

### Incluye
- **Backend — `MapaController::resumenSeccion()`**: nuevo campo `desglose_2024` (null si no hay `votos_partidos`), estructurado por el servicio de bloques de F1 para que el front no re-implemente la regla de mapeo:
  ```json
  {
    "total_votos": 116,
    "votos_nulos": 11,
    "bloques": [
      {
        "nombre": "Sigamos Haciendo Historia",
        "siglas": ["MORENA", "PVEM"],
        "color": "#7c2d12",
        "total": 57,
        "es_bloque_propio": true,
        "opciones": [
          { "clave": "MORENA", "siglas": ["MORENA"], "votos": 57 },
          { "clave": "PVEM", "siglas": ["PVEM"], "votos": 0 },
          { "clave": "PVEM_MORENA", "siglas": ["PVEM", "MORENA"], "votos": 0 }
        ]
      }
    ]
  }
  ```
  - Bloques ordenados por `total` desc; dentro, opciones por votos desc. Se incluyen opciones con 0 votos (el cliente lee "el Verde no sacó un voto" como dato).
  - `color`: para coaliciones, color del partido dominante del bloque (o color definido en el catálogo); para partidos sueltos, su color de `partidos`; "Independientes" gris.
  - `es_bloque_propio` marcado según el partido del tenant (null-safe si no hay partido).
  - `votos_nulos` solo si la fuente lo trae en `estadisticas_seccion` (columna `total_votos` ya existe; nulos se derivan si están disponibles — si no, omitir la clave).
- **Frontend — `Seccion.vue`**: nueva card **"Votos por partido y coalición (2024)"** ubicada **entre** "Elección 2024" y "Perfil por edad" (requisito explícito del cliente). Render solo si `desglose_2024` existe:
  - Por bloque: encabezado con swatch de color, nombre, siglas y subtotal + % del total; barra horizontal proporcional al total del bloque (estilo divs Tailwind de las cards existentes); chip "Tu bloque" si `es_bloque_propio`.
  - Dentro de cada bloque: filas por opción de boleta (etiqueta legible: "MORENA", "PVEM + MORENA", "PAN + PRI + PRD"…), votos con `tabular-nums` y mini-barra relativa al bloque.
  - Pie de card: "Suma de opciones: {n} de {total_votos} votos" como auto-verificación visible (la validación que el cliente hizo en vivo).
- **Frontend — `Mapa.vue`** (ficha de sección al hacer click): versión compacta del desglose — solo bloques (swatch, nombre corto, total, % en barra apilada u filas), sin opciones individuales; con link existente "Ver detalle de la sección →" para el desglose completo. Los datos ya llegan por el mismo fetch a `resumen`.
- **F4 — Botón "Agregar elector"**: se quita del header de la página (`Seccion.vue` header flex, junto al `<h1>`) y se coloca en el header de la card "Electores", alineado a la derecha del título, conservando el mismo `abrirModal()`/Dialog. Mantener atributo `dusk` si existe para no romper la suite.
- **Refactor acotado**: extraer a `resources/js/lib/formato.ts` (o `paletas.ts`) los helpers duplicados que esta feature toca (`fmt`/número es-MX, % y colores de bloque) y consumirlos desde `Seccion.vue` y `Mapa.vue`; `Prioridades.vue` puede migrar oportunísticamente si el diff se mantiene chico.

### No incluye
- Gráfica de pastel (Could del PRD; las barras por bloque cubren la lectura).
- Filtros/orden interactivo dentro de la card (es lectura, no exploración).
- Cambios a la card "Elección 2024" existente (los badges de estatus son de F2).
- Mostrar el desglose en /prioridades (tabla quedaría ilegible; el detalle es la superficie correcta).

## Criterios de aceptación (tests)
1. Dado `GET /api/secciones/{seccion}/resumen` de una sección con `votos_partidos` (fixture sección 2540 del CSV), entonces `desglose_2024.bloques` agrupa: Fuerza y Corazón = PAN 30 + PRI 6 + PRD 0 + PAS 5 + PAN_PRI_PRD 3 + PAN_PRI 1 (+ resto de combos en 0) = 45; Sigamos Haciendo Historia = MORENA 57 (+ PVEM/PVEM_MORENA 0) = 57; y la suma de todos los bloques = 116 − votos nulos = total de opciones del jsonb.
2. Dado una sección sin `votos_partidos`, entonces `desglose_2024 = null` y la card no se renderiza (sin errores).
3. Dado un tenant con partido MORENA, el bloque "Sigamos Haciendo Historia" trae `es_bloque_propio = true` y los demás false; sin partido configurado, todos false.
4. Dado el payload, los bloques vienen ordenados por total desc y cada opción pertenece a exactamente un bloque (ninguna opción del jsonb queda fuera ni duplicada — test de partición sobre el fixture completo).
5. Dado la página de detalle de sección renderizada (Dusk), la card "Votos por partido y coalición (2024)" aparece después de "Elección 2024" y antes de "Perfil por edad".
6. Dado la página de detalle (Dusk), el botón "Agregar elector" está dentro de la card "Electores" y ya no en el header de la página, y al hacer click abre el mismo modal y el POST sigue funcionando (test de feature existente del store no cambia).
7. Dado la ficha del mapa de una sección con datos, se muestra el resumen por bloques con totales que coinciden con el desglose completo.

## Notas de implementación
- El GeoJSON de `cobertura()` **no** debe incluir el desglose (payload): la ficha del mapa ya hace fetch a `resumen` al seleccionar — reutilizar ese dato.
- Etiquetas de opciones: derivar de `siglas` con `join(' + ')`; casos especiales `CAND_IND{n}` → "Candidato independiente {n}".
- El orden de cards en `Seccion.vue` es secuencial en el template (~línea 284 en adelante): insertar la card nueva entre la card de Elección 2024 (`:386-495`) y la de Perfil por edad (`:498`).
- La suite Dusk existente (26 verdes) tiene tests sobre la página de sección: correr `php artisan test --compact` (PHPUnit) y los Dusk afectados tras mover el botón.
- Skeleton/empty state: mientras el fetch de `resumen` resuelve, la card muestra skeleton pulsante (patrón ya usado con deferred/fetch en la página).
- `vendor/bin/pint --dirty --format agent` si se toca PHP.
