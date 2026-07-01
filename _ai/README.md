# _ai/ — Documentación AI-First del proyecto Territori

Esta carpeta es la fuente de verdad para trabajar el proyecto con AI (Claude Code, Cursor, etc.).

## Empieza aquí
- **`CONTEXT.md`** ← cárgalo al inicio de CADA sesión de Claude Code. Es lo más importante.

## Estructura
```
_ai/
├── CONTEXT.md                         ← documento maestro (leer siempre primero)
├── docs/
│   ├── PRD.md                         ← qué se construye y por qué (Fase 01-02)
│   ├── data-model.md                  ← todas las tablas, tipos, relaciones
│   └── api-contract.md                ← endpoints / rutas Inertia
├── adrs/                              ← decisiones de arquitectura
│   ├── ADR-000-template.md
│   ├── ADR-001-multi-tenancy.md
│   ├── ADR-002-postgis.md
│   ├── ADR-003-captura-y-cobertura.md
│   ├── ADR-004-privacidad.md
│   ├── ADR-005-whitelabel-facturacion.md
│   └── ADR-006-red-ciudadana-y-enlace.md
├── specs/                             ← una spec por feature, ANTES de implementar
│   ├── _template.spec.md
│   └── import-cartografia.spec.md     ← Sprint 1, listo para implementar
└── design/                            ← (Fase 03: tokens, screens) — pendiente
```

## Regla de oro
Ninguna feature se implementa sin su `.spec.md`. El AI nunca arranca sin `CONTEXT.md`.

## Siguiente paso
Sprint 1: implementar `specs/import-cartografia.spec.md` (importador de cartografía INE → PostGIS).
Antes: resolver las preguntas abiertas del PRD §11.
