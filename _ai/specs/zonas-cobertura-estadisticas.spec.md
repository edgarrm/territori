# Spec — Estadísticas y cobertura de zona

- **Feature ID**: F7 (iteración "Análisis territorial multinivel + encuestas multi-canal")
- **Estado**: Borrador
- **Depende de**: F5 `agregacion-territorial.spec.md` (`AgregarEstadisticaTerritorial`/`AgregarCoberturaTerritorial`), F6 `zonas-personalizadas.spec.md` (modelo `Zona`)

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
