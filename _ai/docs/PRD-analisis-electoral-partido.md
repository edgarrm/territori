# PRD — Análisis electoral por partido (iteración post-demo, julio 2026)

- **Fecha**: 2026-07-08
- **Fuente**: demo con el primer cliente (Mazatlán) + transcripción de la reunión + CSV `mazatlan_2024_ganador_seccion_coalicion_y_partido.csv` (523 secciones, ya importado en `estadisticas_seccion`)
- **Complementa a**: `_ai/docs/PRD.md` (PRD maestro). Este documento cubre solo la iteración post-demo.

---

## 1. Problem Statement

Hoy el sistema clasifica secciones con lentes "neutrales" (cobertura, penetración, oportunidad demográfica, índice de prioridad 40/40/20). En la demo, el estratega dejó claro que **su lente principal es partidista y operativa**:

1. Quiere ver cada sección como **ganada franca / competida / empatada / perdida** *desde la perspectiva del partido de su campaña*, con la **diferencia de votos** (no el porcentaje) como dato central:
   > "Tú tienes que decir al sistema que eres Morena… si es la diferencia a favor de Morena, esta es una ganada franca… en vez de oportunidad, potencial y cobertura, poner ganada, competida o perdida."
2. Quiere clasificar secciones por **tamaño de lista nominal** — Alfa, Beta, Gama — porque eso decide dónde invierte el trabajo de campo:
   > "Yo voy a las alfas primero… te voy a mandar primero a estas 50 alfas; si me da tiempo, voy con las betas; si no me da tiempo, no voy a las gamas."
   > "El dato del alfa es la lista nominal, no el votante… no me puedo ir al total de votos."
3. Quiere el **desglose de votos por partido y por opción de boleta** (coaliciones combinadas) dentro del detalle de sección, porque ahí lee matices que el bloque agregado esconde:
   > "En esa sección el PAN está fuerte… ahí me habla de un panismo más fuerte que un priismo."
   > "Si tú sumas todos estos, te deben de dar los 116 votos del concentrado."
4. Anticipó que las **coaliciones cambian por elección** (2027: probablemente Morena+PVEM+PT), así que el agrupamiento no puede estar hardcodeado:
   > "El sistema tendría que reconocer que para el 2027 van a ir juntos. Que sea algo que lo puedas modificar… que no te ocupe ahorita."

Como Territori es un SaaS multi-tenant, nada de esto puede asumir "Morena": el partido, los umbrales y qué indicadores se muestran son **configuración por campaña (tenant)**.

## 2. Objetivos / criterios de éxito

- O1 — El estratega abre /prioridades o el mapa y en <10 segundos identifica sus alfas ganadas-francas, competidas y perdidas, con la diferencia de votos visible.
- O2 — Una campaña de otro partido (p. ej. PAN) configura su partido y obtiene el mismo análisis desde su perspectiva, sin cambios de código.
- O3 — El desglose por partido/coalición de cualquier sección cuadra al voto con el CSV fuente (validación que el cliente hizo en vivo: la suma de opciones = total de votos).
- O4 — La campaña decide qué indicadores ve su equipo (competitividad, tipo Alfa/Beta/Gama, índice neutral, oportunidad) con toggles, sin deploy.

## 3. Modelo conceptual

### Opciones de boleta y bloques
En la elección 2024 cada boleta ofrecía votar por un **partido individual** (PAN, MORENA, …) o por una **combinación de coalición** (PVEM_MORENA, PAN_PRI_PRD, PAN_PRI, …). El CSV/`votos_partidos` guarda las 24 opciones tal cual.

Un **bloque** agrupa las opciones que suman para una coalición:
- Una opción pertenece al bloque de la coalición cuyo conjunto de partidos **contiene todas las siglas** de la opción (PAN_PRI ⊆ {PAN, PRI, PRD, PAS} → bloque "Fuerza y Corazón").
- Un partido no coaligado (MC, PT, PES en 2024) es su propio bloque.
- `CAND_IND*` forman el bloque "Independientes".

Coaliciones 2024 (seed inicial): **Fuerza y Corazón por México** = {PAN, PRI, PRD, PAS}; **Sigamos Haciendo Historia** = {MORENA, PVEM}. Las coaliciones viven en un catálogo por año: registrar 2027 será dar de alta una fila, no tocar código.

### Perspectiva de campaña
- La campaña (tenant) declara **su partido**. Su **bloque propio** es el bloque de la coalición que contiene a ese partido (o el partido solo si no está coaligado ese año).
- **Diferencia** = votos del bloque propio − votos del mejor bloque rival.
- **Estatus de competitividad** (umbral configurable, default 30):

| Diferencia | Estatus |
|---|---|
| ≥ umbral (30) | Ganada franca |
| 1 a umbral−1 | Competida |
| 0 | Empatada |
| < 0 | Perdida |
| sin datos / sin partido configurado | Sin datos |

### Tipo de sección (umbrales configurables)

| Lista nominal | Tipo |
|---|---|
| ≥ 1,000 | Alfa |
| 500 – 999 | Beta |
| 1 – 499 | Gama |

Se calcula **on-read** sobre `secciones.lista_nominal` (no se persiste): 523 secciones, umbrales por tenant.

## 4. Feature List (MoSCoW de la iteración)

### Must
- **F1 — Configuración de campaña + catálogo electoral** (`configuracion-campana.spec.md`): tablas `partidos` y `coaliciones` (globales, con seed 2024), `tenants.partido_id` + `tenants.settings` (umbrales y toggles de indicadores), pantalla "Campaña" en settings (solo admin).
- **F2 — Competitividad y tipo de sección** (`competitividad-seccion.spec.md`): action de cálculo, estatus + diferencia + tipo Alfa/Beta/Gama en mapa (modo competitividad re-hecho por partido, modo nuevo Alfa/Beta/Gama), /prioridades (columnas + filtros + orden) y detalle de sección; todo respetando los toggles.
- **F3 — Desglose de votos por partido y coalición** (`desglose-votos-seccion.spec.md`): card nueva en el detalle de sección **antes** de "Perfil por edad", barras agrupadas por bloque con opciones de boleta desglosadas; versión compacta en la ficha del mapa.

### Should
- **F4 — Ajuste UI**: botón "Agregar elector" se mueve del header de la página al header de la card "Electores" (incluido en F3 spec).
- Extraer paletas/formatters compartidos a `resources/js/lib/` (nota de implementación en F3; hoy están triplicados en Mapa/Seccion/Prioridades).

### Could
- Gráfica de pastel del desglose (el cliente la mencionó; se pospone — las barras por bloque cubren la lectura).
- Registrar coaliciones desde UI de super-admin (por ahora seeder/comando).

### Won't (esta iteración)
- Reconocimiento automático de coaliciones 2027 en captura/proyección ("que no te ocupe ahorita" — el catálogo por año lo deja preparado).
- Librería de charts, i18n de estas pantallas.
- Persistir competitividad/tipo en BD (cálculo on-read es suficiente al volumen actual).
- Cambiar la fórmula del índice neutral 40/40/20 (se mantiene, solo se vuelve toggleable).

## 5. Impacto en data model y pantallas (resumen)

| Área | Cambio |
|---|---|
| BD | +`partidos`, +`coaliciones` (globales); `tenants` +`partido_id`, +`settings` jsonb |
| Backend | +`Settings/CampanaController`; +`Actions/Estadisticas/CalcularCompetitividadSeccion`; `MapaController::cobertura/resumenSeccion/prioridades` enriquecidos; enums `CompetitividadSeccion` y `TipoSeccion` |
| Frontend | +`pages/settings/Campana.vue`; `Mapa.vue` (modo competitividad por partido, modo Alfa/Beta/Gama, toggles); `Prioridades.vue` (columnas/filtros); `Seccion.vue` (card desglose, badges, botón elector) |
| Datos | Ningún re-import: `estadisticas_seccion.votos_partidos` ya contiene las 24 opciones de boleta. Solo seeders de `partidos`/`coaliciones`. |

## 6. Orden de implementación sugerido

1. **F1** (todo lo demás depende del partido/umbrales/toggles del tenant).
2. **F2** (cálculo + superficies).
3. **F3 + F4** (desglose y ajuste de UI).

Cada feature sigue el ciclo SDD: spec → tests (PHPUnit; Dusk donde toque UI crítica) → implementación → Pint.

## 7. Preguntas abiertas

- ¿El rol `brigadista` debe ver competitividad/desglose, o solo coordinador/admin? (Default propuesto en specs: visible para todos los roles del tenant, igual que el resto de la estadística; el toggle por campaña ya da control.)
- Colores oficiales de partidos: usar los institucionales aproximados (PAN azul, PRI rojo/verde tricolor→rojo, PRD amarillo, MORENA guinda, PVEM verde, MC naranja, PT rojo oscuro, PAS morado, PES morado oscuro) — confirmar con el cliente si quiere ajustarlos.
