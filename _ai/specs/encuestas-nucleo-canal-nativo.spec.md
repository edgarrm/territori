# Spec — Núcleo de encuestas + canal nativo (formulario propio)

- **Feature ID**: F8 (iteración "Análisis territorial multinivel + encuestas multi-canal")
- **Estado**: Borrador
- **Depende de**: F6 `zonas-personalizadas.spec.md` / F7 `zonas-cobertura-estadisticas.spec.md` (ámbito `seccion`|`zona`), ADR-004 (privacidad — no se captura intención de voto ni datos ligados a una persona)

## Objetivo
Modelar las encuestas de forma **canal-agnóstica** (preguntas, respuestas agregadas por ámbito) y construir el primer canal: un formulario propio dentro de Territori, con dos flujos de respuesta — brigadista autenticado en campo, y link público sin login.

## Por qué
Es la base reusable para cualquier canal futuro (Google Sheets en F9, Twilio/voz después) y el canal que más rápido entrega valor porque no depende de ninguna credencial ni API externa. Construirlo primero valida el modelo de datos completo (`encuestas`/`encuesta_preguntas`/`encuesta_respuestas_agregadas`) con el caso más simple antes de sumarle un canal externo.

## Alcance

### Incluye
- Migración `encuestas` (🏢): `tenant_id`, `nombre`, `canal` string (default `nativo`; el catálogo de canales válidos vive en código, no en un `CHECK` de BD), `configuracion_canal` jsonb nullable (vacío para `nativo`), `ambito_tipo` (`seccion`|`zona`), `modo_acceso` (`brigadista`|`publico`|`ambos`, default `brigadista`), `token_publico` string nullable único (generado solo cuando `modo_acceso` incluye `publico`, no adivinable — `Str::random(40)` o equivalente, nunca secuencial), `estado` (`activa`|`pausada`), `ultima_sincronizacion` nullable (no aplica a `nativo`), `ultimo_resultado` jsonb nullable, `ultimo_error` nullable, `creado_por_membership_id` FK nullable.
- Migración `encuesta_preguntas` (🏢): `tenant_id`, `encuesta_id` FK cascade, `campo` string nullable (para `nativo` no hay columna de origen que detectar — nullable, los canales pull de F9 sí lo usan), `etiqueta` (texto de la pregunta, escrito directamente por el coordinador en `nativo`), `tipo` (`opcion_unica`|`texto_libre`|`numerica`), `opciones` jsonb nullable (catálogo de opciones válidas para `opcion_unica`, definido explícitamente por el coordinador en `nativo`, no detectado), `incluida` boolean — **default `true`** para preguntas creadas vía `nativo` (ya son intencionales); F9 usará default `false` para preguntas detectadas por un canal pull. `orden` int. Único `(encuesta_id, campo)` solo cuando `campo` no es null.
- Migración `encuesta_respuestas_agregadas` (🏢): `tenant_id`, `encuesta_id` FK cascade, `pregunta_id` FK `encuesta_preguntas` cascade, `ambito_tipo` (`seccion`|`zona`), `ambito_id` (sin FK — apunta a `secciones.id` o `zonas.id` según `ambito_tipo`; primer uso de un patrón polimórfico en este codebase, señalar para revisión explícita en code review), `opcion` string, `conteo` unsignedInteger default 0, `actualizado_en` timestamptz. Único `(encuesta_id, pregunta_id, ambito_tipo, ambito_id, opcion)`.
- `App\Actions\Encuestas\TabularRespuesta::handle(Encuesta $encuesta, string $ambitoTipo, int $ambitoId, array $respuestas): void` — `$respuestas` = `pregunta_id => opcion`. Solo tabula preguntas `incluida=true` y `tipo=opcion_unica` (las de `texto_libre`/`numerica` se ignoran silenciosamente para tabulación, aunque puedan aceptarse en el payload). **Modo incremental**: `conteo = conteo + 1` vía upsert — a diferencia del recuento completo que usará el canal Sheets en F9, aquí cada submit es un evento nuevo sin "estado externo" que resincronizar.
- `App\Http\Controllers\EncuestaController`: `index`/`store`/`update` (alta de la encuesta + constructor de preguntas) — `rol:coordinador,admin`.
- **Flujo brigadista** (autenticado, dentro del grupo `tenant` existente): `App\Http\Controllers\EncuestaRespuestaController::store` (`POST /api/encuestas/{encuesta}/respuestas`) — recibe `{ambito_id, respuestas: {pregunta_id: opcion}}`, valida que cada opción pertenezca al catálogo de su pregunta, llama `TabularRespuesta`. **Nunca persiste la respuesta cruda de forma individual**, solo incrementa el agregado.
- **Flujo público, sin login** (**primera ruta de escritura pública del sistema** — hoy todo `routes/web.php` salvo `/` vive detrás de `auth`+`tenant`):
  - Rutas nuevas fuera de `auth`/`tenant`, dentro del `web` middleware base de Laravel (conserva sesión+CSRF de Inertia): `GET /e/{token}` (`App\Http\Controllers\EncuestaPublicaController::show`, resuelve la `Encuesta` por `token_publico`, sin pasar por `TenantContext`/`ResolveTenant`; 404 si `modo_acceso` no incluye `publico` o el token no existe) y `POST /e/{token}/respuestas` (`EncuestaPublicaController::store`).
  - **`tenant_id` se resuelve del `tenant_id` de la propia `Encuesta` encontrada por token, nunca del request** — única excepción documentada a la regla "tenant_id nunca se acepta del request" de `_ai/CONTEXT.md`; anotarla ahí también al implementar.
  - **Rate limiting obligatorio**: `throttle:` de Laravel sobre `POST /e/{token}/respuestas` (valor inicial sugerido `throttle:5,1` por IP, ajustable con el cliente).
  - Sin `consentimiento`/`aviso_privacidad_id` (la respuesta nunca se liga a una identidad) — el formulario público muestra un aviso de transparencia ("tus respuestas son anónimas y se muestran agregadas"), buena práctica, no requisito legal ADR-004.
  - El respondiente elige su ámbito (zona/sección) desde un selector en la propia página pública, según `ambito_tipo` de la encuesta.
- Frontend: `resources/js/pages/Encuestas.vue` (nueva, sidebar al nivel de Brigadistas/Eventos) — alta con constructor de preguntas, selector de canal (solo "Formulario propio" habilitado en F8), selector de `modo_acceso` con el link público (`/e/{token}`) copiable cuando aplica. `resources/js/pages/EncuestaCaptura.vue` (autenticada, mismo espíritu que `Captura.vue`) y `resources/js/pages/EncuestaPublica.vue` (sin layout autenticado, mobile-first) — ambas usan el mismo componente `resources/js/components/FormularioEncuesta.vue`, solo cambia de dónde sale `ambito_id` y a qué endpoint postean.

### No incluye
- Canales pull (Google Sheets, Twilio/voz) — F9 y una spec futura.
- Visualización en mapa/ficha de sección o zona (F10).
- Re-tabulación retroactiva del histórico si se edita una pregunta después de tener respuestas ya contadas (se permite editar, pero no se migra el histórico — fuera de alcance v1).
- Anti-bot más allá de rate-limiting por IP (sin CAPTCHA en v1 — ver decisión abierta).

## Decisión abierta a confirmar antes de codear
Valor exacto del rate limit del link público y si el IP-throttling basta o hace falta algo más fuerte (CAPTCHA) si el cliente anticipa que el link se difunda masivamente en redes sociales.

## Criterios de aceptación (tests)
1. Dado un coordinador que crea una encuesta `canal=nativo`, `ambito_tipo=seccion`, con 2 preguntas `opcion_unica` y sus opciones, al guardar, `encuesta_preguntas` queda con `incluida=true` en ambas por default.
2. Dado un brigadista autenticado con acceso a una sección de su tenant, al responder vía `POST /api/encuestas/{encuesta}/respuestas` con `ambito_id` de esa sección y una opción válida por pregunta, `encuesta_respuestas_agregadas` incrementa `conteo` en 1 para `(pregunta, seccion, opcion)`.
3. Dado dos respuestas seguidas con la misma opción en la misma sección, el conteo llega a 2 (incremental, no se sobreescribe).
4. Dado un payload con una opción que **no** está en `encuesta_preguntas.opciones` de esa pregunta, el request falla validación (422) sin tabular nada.
5. Dado `modo_acceso=publico` al crear/activar la encuesta, `encuestas.token_publico` se puebla con un valor único no adivinable (no secuencial, test verifica que no es predecible desde el `id`).
6. Dado `GET /e/{token}` con un token válido, la página pública carga sin sesión autenticada (sin redirección a login).
7. Dado más de N requests a `POST /e/{token}/respuestas` en 1 minuto desde la misma IP (N = límite configurado), la request N+1 es rechazada con 429.
8. Dado una respuesta pública válida con un `tenant_id` falso incluido en el body del request, la respuesta se tabula usando el `tenant_id` real de la `Encuesta` encontrada por token — el valor del body se ignora por completo.
9. Dado `modo_acceso=brigadista` (sin público habilitado, `token_publico=null`), `GET /e/{token}` responde 404 para cualquier token.
10. Dado una pregunta `tipo=texto_libre` o `tipo=numerica`, nunca se tabula en `encuesta_respuestas_agregadas` aunque venga en el payload de respuesta.
11. Dado un viewer sin rol `coordinador`/`admin`, al intentar `POST /encuestas` (crear encuesta) recibe 403.

## Notas de implementación
- `token_publico`: usar un generador no secuencial (`Str::random(40)` o equivalente) — nunca derivarlo del `id` de la encuesta.
- `FormularioEncuesta.vue` es el componente compartido entre `EncuestaCaptura.vue` (autenticado) y `EncuestaPublica.vue` (público) — mismo formulario, distinto endpoint y origen de `ambito_id`.
- Documentar en `_ai/CONTEXT.md`, sección "Nunca", la excepción puntual: la ruta pública de encuestas resuelve `tenant_id` desde una fila ya autorizada por su token secreto, no desde el request — no es una violación de la regla general, es la única excepción explícita.
- `vendor/bin/pint --dirty --format agent` al cierre.
