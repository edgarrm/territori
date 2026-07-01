# 📋 Reporte de Demo — Territori

Guía de presentación + explicación de ARCO.

---

## 1. El pitch en una frase

> **Territori es el sistema operativo de una campaña territorial:** convierte el
> trabajo de calle de los brigadistas en un mapa de cobertura en tiempo real, con
> metas por sección, gestión de brigadistas y **cumplimiento de protección de
> datos (LFPDPPP) integrado** — algo que la mayoría de las herramientas de
> campaña ignora.

Tres niveles, un solo dato vivo: **Estrategia (admin) → Operación (coordinador) → Calle (brigadista).**

---

## 2. Qué hay cargado en la demo

Campaña **"Campaña Demo"** sobre **Mazatlán, Sinaloa** (530 secciones reales del INE):

| Dato | Volumen |
|---|---|
| Electores capturados | **24,199** |
| Secciones (con padrón/lista nominal) | 530 (220–3,150) |
| Brigadistas activos | 5 |
| Zonas asignadas | 530 |
| Seguimientos vencidos (agenda) | ~40 |
| Eventos | 2 (mitin + reunión vecinal) |
| Solicitudes ARCO | **4 pendientes + 1 atendida** |

**El mapa se reparte en los 5 colores** en sus dos métricas (esto es lo que más impacta visualmente):

- **Cobertura de meta:** 79 cumplidas · 155 buen avance · 161 en proceso · 81 rezagadas · 54 desérticas
- **Penetración del padrón:** 79 alta · 208 buena · 135 media · 54 baja · 54 nula

---

## 3. Credenciales (todas con password: `password`)

| Rol | Email | Qué demuestra |
|---|---|---|
| **Admin** | `admin@demo.test` | Visión estratégica, mapa, crear campañas |
| **Coordinador** | `coordinador@demo.test` | Metas, brigadistas, export, **bandeja ARCO** |
| **Brigadista** | `brigadista@demo.test` | Captura en campo, agenda, registrar ARCO |

---

## 4. Guion de presentación (≈10 min, 3 actos)

### 🟦 Acto 1 — Admin: "la foto estratégica" (3 min)

1. **Dashboard** → KPIs globales de campaña (capturados, meta total, % avance, brigadistas activos).
2. **Mapa → Cobertura**: el coropletico de 5 colores. *"De un vistazo veo dónde voy bien (azul/verde) y dónde estoy abandonado (rojo)."*
3. Cambia a **Penetración**: *"Y aquí veo, del padrón completo de cada sección, qué tan profundo he llegado."*
4. Clic en una sección → se ve el callejero de fondo + ficha con su detalle.

> **Frase clave:** *"Ningún Excel te da esto. Es la campaña entera en una pantalla."*

### 🟩 Acto 2 — Coordinador: "cómo se administra" (4 min)

1. **Metas**: ajusta la meta de una sección rezagada → la cobertura recalcula.
2. **Brigadistas**: activa/desactiva, **asigna zonas** en el mapa, abre **ratios** de rendimiento.
3. **Exportar CSV** de electores (dato sensible, solo gestión).
4. **Solicitudes ARCO** (sidebar) → ver punto 5.

> **Frase clave:** *"El coordinador no captura: orquesta. Asigna territorio y mide a su gente."*

### 🟧 Acto 3 — Brigadista: "el trabajo de calle" (3 min)

1. **Dashboard** propio (sus capturas, sus zonas — distinto del de admin).
2. **Captura**: registra un elector → **exige consentimiento** con el aviso de privacidad vigente (compliance en vivo).
3. **Lotería**: modo de captura rápida en evento.
4. **Agenda**: ~40 seguimientos vencidos; atiende uno.

> **Frase clave:** *"El brigadista solo ve lo suyo. Y no puede capturar sin consentimiento. El sistema lo obliga a cumplir la ley."*

---

## 5. Explicación de ARCO (el diferenciador legal)

### Qué es

**ARCO = Acceso, Rectificación, Cancelación, Oposición.** Son los 4 derechos que la
ley mexicana de datos personales (**LFPDPPP**) le da a toda persona sobre sus datos.
Como Territori guarda datos de electores (nombre, teléfono, domicilio), legalmente es
"responsable de datos" y **debe poder atenderlos**:

| Derecho | El ciudadano pide… | En Territori |
|---|---|---|
| **A**cceso | "¿Qué datos míos tienen?" | Se le entrega su info / export |
| **R**ectificación | "Está mal, corríjanlo" | Editar la ficha del elector |
| **C**ancelación | "Bórrenme" | Baja lógica + borrado de datos (PII scrub) |
| **O**posición | "No me contacten" | Se deja de tratar |

### Cómo se demuestra (flujo de punta a punta)

1. **Brigadista** (en la ficha del elector) registra una solicitud → queda **pendiente**. *No tiene poder para borrar nada.*
2. **Coordinador** entra a **Solicitudes ARCO** (bandeja) → ve las pendientes por tipo → **atiende**:
   - Una **Cancelación** ejecuta la baja real (con confirmación irreversible): se borran los datos personales y baja el conteo.
   - Las demás se marcan atendidas (la resolución se hace en la ficha).
3. Cambia el filtro a **Atendidas** → trazabilidad completa para auditoría.

### Por qué vende

> *"Tres controles que te protegen del INAI y de impugnaciones: (1) **consentimiento**
> explícito al capturar, (2) **derechos ARCO** con registro y bandeja, (3)
> **separación por rol** — quien levanta no es quien autoriza el borrado — más
> **teléfonos cifrados**. Es cumplimiento normativo convertido en producto."*

---

## 6. Los 3 mensajes para cerrar

1. **Visibilidad total** — la campaña entera en un mapa vivo, no en reportes muertos.
2. **Control operativo** — metas, zonas y rendimiento por brigadista, por rol.
3. **Cumplimiento de ley integrado** — ARCO + consentimiento + cifrado, listo para auditoría.

---

## 7. Checklist antes de presentar

- [ ] App corriendo (`territori.test`) y `npm run build` hecho.
- [ ] Demo fresca: `php artisan tinker --execute '(new \Database\Seeders\DemoTenantSeeder)->run(); (new \Database\Seeders\DemoCapturasSeeder)->run();'`
- [ ] Tener abiertas 3 pestañas/sesiones (una por rol) para no perder tiempo en logins.
- [ ] Empezar en **Mapa modo Cobertura** — es el "wow" inicial.
