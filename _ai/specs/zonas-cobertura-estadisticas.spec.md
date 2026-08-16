# Spec — Estadísticas y cobertura de zona

- **Feature ID**: F7 (iteración "Análisis territorial multinivel + encuestas multi-canal")
- **Estado**: Implementado
- **Depende de**: F5 `agregacion-territorial.spec.md` (`AgregarEstadisticaTerritorial`/`AgregarCoberturaTerritorial`), F6 `zonas-personalizadas.spec.md` (modelo `Zona`)

## Nota de implementación (post-hecho)

Migración `zona_seccion` (`database/migrations/2026_08_15_000005_create_zona_seccion_table.php`) exactamente como en el alcance: PK compuesta `(zona_id, seccion_id)`, `tenant_id` por columna (mismo motivo que `brigadista_seccion`: `secciones` es un catálogo global sin `tenant_id`), sin modelo Eloquent — se opera con `DB::table('zona_seccion')` directo. `App\Actions\Zonas\CalcularInterseccionZona::handle(Zona $zona)`: borra completo y, solo si la zona tiene `geom`, hace un único `INSERT ... SELECT` con `ST_Intersects` como filtro previo a `ST_Area(ST_Intersection(...))/ST_Area(secciones.geom)`, sin restringir por municipio del tenant (mismo criterio de F6). **Bug real encontrado y corregido durante el TDD**: la comprobación inicial de "¿tiene geom?" leía `$zona->geom` en memoria, pero `CrearZona`/`ActualizarZona` (F6) escriben `geom` con SQL crudo (`ValidaGeometriaZona::guardarGeom`) sin refrescar el atributo del modelo — el chequeo en memoria siempre veía `null` y la acción nunca insertaba nada aunque la geometría sí estuviera persistida en BD. Fix: la comprobación consulta la BD directo (`DB::table('zonas')->whereNotNull('geom')->exists()`) en vez de confiar en el atributo Eloquent. `CrearZona`/`ActualizarZona` llaman a `CalcularInterseccionZona` de forma síncrona justo después de `guardarGeom()`, **solo cuando el `geom` recibido en el payload no es null** (una zona `tipo=mundial` sin geometría no dispara la acción; si una zona con geometría existente se actualiza para quitarle el polígono, `zona_seccion` queda con las filas viejas hasta el próximo `territori:recalcular-zonas` — caso límite no cubierto por los 8 criterios de aceptación, documentado aquí en vez de resuelto). Comando `territori:recalcular-zonas {tenant}` (mismo patrón que `territori:recalcular-cobertura`): recorre todas las zonas del tenant y llama a la Action, idempotente.

`ZonaController::resumen(string $zona)` (`GET /api/zonas/{zona}/resumen`, resolución manual por la regla anti-binding de Sprint 4 — no `Zona $zona` implícito) resuelve las secciones con `resolverSeccionesDeZona()`: lee `zona_seccion` propia (`pct_interseccion >= 0.5`) y la une recursivamente con las de todas sus `hijas()` (límite defensivo de 20 niveles de profundidad), y llama directo a `AgregarEstadisticaTerritorial`/`AgregarCoberturaTerritorial` de F5 con la configuración de competitividad resuelta por un método privado `configuracionCompetitividad()` — copia exacta del de `TerritorioController` (F5), duplicado a propósito porque `MapaController` y `TerritorioController` ya seguían ese mismo patrón de no compartirlo entre controladores. Ruta añadida al grupo de lectura abierta de zonas (`rol:brigadista,coordinador,admin`), igual que `zonas.index`/`zonas.geojson` — el spec no pide restringir por secciones asignadas del brigadista (a diferencia de `TerritorioController::resumen`), así que no se implementó esa intersección.

Frontend: en vez de una página nueva, el resumen de zona se agregó como panel expandible dentro de la tabla de `Zonas.vue` (clic en el nombre de la zona), con dos cards condensadas (Cobertura, Elección 2024 con desglose por bloque) inspiradas en el layout de `Seccion.vue` pero no el componente reusado literal — la forma de la respuesta de `ZonaController::resumen` (envoltura `{estadistica, cobertura}` de F5) difiere de la de `MapaController::resumenSeccion` (campos aplanados), así que replicar el layout visual fue más directo que forzar un componente compartido.

13 tests nuevos verdes (`tests/Feature/Zonas/ZonaEstadisticasTest.php` cubre los 8 criterios + la integración síncrona con `CrearZona`/`ActualizarZona`; `tests/Feature/Zonas/RecalcularZonasCommandTest.php` cubre el criterio 7 de idempotencia); suite completa **568 verdes**, Larastan y Pint limpios.

## Objetivo
Calcular y exponer estadísticas de cobertura de campaña y electoral 2024 agregadas para cualquier `Zona`, reusando exactamente las Actions de F5, vía intersección geométrica con las secciones INE que la zona cubre.

## Por qué
Sin esto, las zonas creadas en F6 son solo polígonos decorativos. El valor real de "seccionar" es poder correrles el mismo tipo de análisis que ya existe por sección electoral — es la prueba de que la Action genérica de F5 fue bien diseñada, porque F7 no agrega ninguna lógica de agregación nueva.

## Alcance

### Incluye
- Migración `zona_seccion` (pivote 🏢, mismo patrón exacto que `database/migrations/2026_06_26_220000_create_brigadista_seccion_table.php`): `tenant_id`, `zona_id` FK `zonas` cascade, `seccion_id` FK `secciones` cascade, `pct_interseccion` decimal(5,4) (0–1), `calculado_en` timestamptz; PK compuesta `(zona_id, seccion_id)`, índice `tenant_id`. Sin modelo Eloquent con `save()` de instancia — mismo motivo que `CoberturaSeccion` (PK compuesta).
- `App\Actions\Zonas\CalcularInterseccionZona::handle(Zona $zona): void` — una query PostGIS: `ST_Area(ST_Intersection(zona.geom, secciones.geom)) / ST_Area(secciones.geom)` filtrado primero por `ST_Intersects` (evita computar intersección de geometrías que ni se tocan), borra e inserta completo `zona_seccion` para esa zona. Corre **síncrona** dentro de `CrearZona`/`ActualizarZona` de F6 cuando cambia la geometría (bajo volumen — cientos de secciones como mucho — no justifica un job encolado, a diferencia de `ActualizarCoberturaSeccion` que corre por cada captura de elector).
- Comando `territori:recalcular-zonas {tenant}` (idempotente, mismo naming que `territori:recalcular-cobertura`) — recompute completo de `zona_seccion` para todas las zonas de un tenant, red de seguridad tras importar/actualizar cartografía.
- Regla de pertenencia: una sección cuenta para una zona si `pct_interseccion >= 0.5` (aproximación deliberada y documentada, no fraccionaria — evita estadísticas confusas de "medio elector capturado"). `pct_interseccion` se guarda siempre, para trazabilidad y un eventual refinamiento ponderado futuro.
- `ZonaController::resumen(Zona $zona)` (`GET /api/zonas/{zona}/resumen`): resuelve los `seccion_id` con `pct_interseccion >= 0.5` y llama **directamente** a `AgregarEstadisticaTerritorial`/`AgregarCoberturaTerritorial` de F5 — cero lógica de agregación nueva. Cuando la zona es un nivel intermedio de la jerarquía (`zona_padre_id`, ej. "estado" o "continente" sin `geom` propia — ver F6), el resolver desciende **recursivamente** por `hijas()` hasta llegar a las zonas hoja con `zona_seccion`, uniendo sus conjuntos de `seccion_id` antes de agregar. Una zona hoja sin ninguna sección INE relacionada (ej. zona fuera de México) simplemente no aporta datos electorales 2024, pero no rompe el cálculo.
- Frontend: ficha de zona reusando el layout de `resources/js/pages/Seccion.vue` (cards de cobertura + electoral 2024 + desglose por bloque).

### No incluye
- Atribución fraccionaria/ponderada por el porcentaje real de intersección — v1 es binaria (`>= 0.5` cuenta entera, `< 0.5` no cuenta nada).
- Resolución punto-exacto por elector individual (`ST_Contains` sobre `electores.ubicacion`) — solo cubre modo individual/evento con GPS, no lotería (la mayoría de capturas); se documenta como limitación conocida de v1, no se construye aquí.
- Encuestas ligadas a zona (F8-F10).
- Política de borrado de una zona con `zona_seccion`/agregados ya calculados más allá del `nullOnDelete`/cascade ya definido en F6 — si se requiere soft-delete específico por tener datos asociados, se revisita cuando haga falta.

## Criterios de aceptación (tests)
1. Dado una `Zona` cuyo polígono cubre completamente 3 secciones y parcialmente una cuarta al 30%, cuando se calcula la intersección, `zona_seccion` registra las 4 con su `pct_interseccion`, pero `resumen()` solo agrega las 3 con `>= 0.5` (excluye la del 30%).
2. Dado una sección con `pct_interseccion` exactamente en `0.5`, esa sección **sí** se incluye (regla `>=`, no `>`).
3. Dado se actualiza la geometría de una zona ya existente, al guardar, `zona_seccion` se recalcula completo (filas viejas borradas, no acumuladas junto a las nuevas).
4. Dado `ZonaController::resumen(zona)`, el resultado coincide exactamente con llamar `AgregarEstadisticaTerritorial`/`AgregarCoberturaTerritorial` de F5 sobre el mismo conjunto de `seccion_id` resuelto a mano (test de equivalencia, prueba de que no hay lógica duplicada).
5. Dado una `Zona` nivel "estado" cuyas `hijas()` son varias zonas "ciudad" con sus propias `zona_seccion`, al pedir su resumen se agregan las secciones de **todas** las zonas hijas recursivamente.
6. Dado una zona hoja sin ninguna sección INE relacionada (ej. zona en otro país), al pedir su resumen electoral 2024, devuelve `secciones_incluidas = 0` sin lanzar excepción.
7. Dado `territori:recalcular-zonas {tenant}`, correrlo dos veces seguidas sin cambios en la cartografía produce el mismo resultado en `zona_seccion` (idempotente).
8. Dado dos tenants distintos con zonas sobre las mismas secciones globales, el `resumen()` de cada uno usa su propio `cobertura_seccion` (tenant-scoped) sin fuga entre tenants — mismo criterio que el resto del sistema.

## Notas de implementación
- Filtrar con `ST_Intersects` **antes** de `ST_Area(ST_Intersection(...))` es obligatorio por performance: computar la intersección exacta de geometrías que ni se tocan es trabajo desperdiciado sobre cientos de secciones.
- El resolver recursivo de `zona_padre_id` debe protegerse contra ciclos (aunque no deberían ocurrir por construcción vía UI/import) — un límite de profundidad defensivo es suficiente, no hace falta detección de grafo completa.
- `vendor/bin/pint --dirty --format agent` al cierre.
