# ADR-003 — Modelo de Captura Unificado y Agregados de Cobertura

- **Estado**: Aceptado
- **Fecha**: 2026-06-26
- **Contexto SDD**: Fase 04 — Architecture

## Contexto

Existen tres modos de captura (Lotería, Individual, Evento) y el dashboard debe mostrar cobertura por sección en "tiempo casi real" sobre 530+ secciones por municipio, con múltiples campañas concurrentes. Dos decisiones acopladas: cómo modelar los tres modos y cómo calcular la cobertura sin matar la base de datos.

## Decisión

### 1. Captura unificada
Los tres modos escriben en la **misma tabla `electores`**. Se diferencian por el campo `modo_captura` (`loteria|individual|evento`) y por FKs opcionales (`loteria_id`, `evento_id`). La lógica de UI cambia por modo; el modelo de datos no se triplica.

- **Lotería**: se abre una sesión (`loterias`) con una `seccion_id` fija; cada elector creado en esa sesión hereda la sección y queda ligado por `loteria_id`.
- **Individual**: `seccion_id` se resuelve por buscador, GPS (ST_Contains) o domicilio. Sin `loteria_id` ni `evento_id`.
- **Evento**: ligado a `evento_id`; `seccion_id` puede variar por asistente.

### 2. Agregados de cobertura — tabla derivada, no agregación en vivo
El mapa NO ejecuta `COUNT(*) GROUP BY seccion` sobre `electores` en cada carga. Mantenemos una tabla **`cobertura_seccion`** (tenant_id, seccion_id, capturados, meta, cobertura, penetracion, actualizado_en) que se actualiza:
- De forma incremental por **evento de dominio** `ElectorCapturado` → incrementa el contador de su sección (job en cola).
- Con un **recálculo completo** programado (cada X min o on-demand) como red de seguridad.

El endpoint del mapa lee `cobertura_seccion` join `secciones` (geom simplificada) y devuelve GeoJSON. Barato y constante.

### 3. Interacciones como tabla aparte
El historial de contactos vive en `interacciones` (1 elector → N interacciones), con `tipo` (canal) y `resultado` separados (ver data-model). No se mezcla con `electores`.

## Consecuencias

**Positivas**: una sola tabla de personas → analítica unificada; el mapa escala porque lee agregados precalculados; los tres modos comparten validación y dedup.

**Negativas / riesgos**:
- La tabla derivada puede desincronizarse si un job falla. Mitigación: recálculo periódico idempotente + un comando `territori:recalcular-cobertura {tenant}` manual.
- Eventual consistency: la cobertura puede ir unos segundos atrás del dato crudo. Aceptable para el caso de uso (KR: < 1 min de latencia).

## Alternativa descartada
Agregación en vivo con índice `(tenant_id, seccion_id)`: funciona con pocos datos pero degrada con varias campañas grandes y recálculo en cada pan/zoom del mapa. Se descarta por escalabilidad.
