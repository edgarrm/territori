# Spec — Núcleo de encuestas + canal nativo (formulario propio)

- **Feature ID**: F8 (iteración "Análisis territorial multinivel + encuestas multi-canal")
- **Estado**: Implementado
- **Depende de**: F6 `zonas-personalizadas.spec.md` / F7 `zonas-cobertura-estadisticas.spec.md` (ámbito `seccion`|`zona` — **NO implementados aún en este worktree**, ver nota abajo), ADR-004 (privacidad — no se captura intención de voto ni datos ligados a una persona)

## Objetivo
Modelar las encuestas de forma **canal-agnóstica** (preguntas, respuestas agregadas por ámbito) y construir el primer canal: un formulario propio dentro de Territori, con dos flujos de respuesta — brigadista autenticado en campo, y link público sin login.

## Por qué
Es la base reusable para cualquier canal futuro (Google Sheets en F9, Twilio/voz después) y el canal que más rápido entrega valor porque no depende de ninguna credencial ni API externa. Construirlo primero valida el modelo de datos completo (`encuestas`/`encuesta_preguntas`/`encuesta_respuestas_agregadas`) con el caso más simple antes de sumarle un canal externo.

## NOTA IMPORTANTE sobre F6/F7 (no implementados en este worktree)
`Zona` y sus tablas (F6/F7) NO existen todavía en este worktree — se implementan en otro sprint separado. Para F8, `ambito_tipo` acepta el valor `'zona'` como string válido en la columna, pero **NO hay FK ni tabla `zonas` que valide su existencia** (por diseño: `ambito_id` en `encuesta_respuestas_agregadas` ya se especifica SIN FK precisamente por esto — es un patrón polimórfico manual). Implementa y testea F8 usando `ambito_tipo='seccion'` en todos los tests (la única entidad de ámbito real disponible hoy es `Seccion`). Dejar `ambito_tipo='zona'` como valor aceptado por el enum/validación pero sin poder probarlo end-to-end todavía es correcto y esperado — no bloquea nada.

## Alcance

### Incluye
- Migración `encuestas` (🏢): `tenant_id`, `nombre`, `canal` string (default `nativo`; el catálogo de canales válidos vive en código — usa un enum PHP `App\Enums\CanalEncuesta` con al menos el caso `Nativo`, no un `CHECK` de BD, para no migrar cada vez que se agregue uno), `configuracion_canal` jsonb nullable (vacío para `nativo`), `ambito_tipo` (`seccion`|`zona`), `modo_acceso` (`brigadista`|`publico`|`ambos`, default `brigadista`), `token_publico` string nullable único (generado solo cuando `modo_acceso` incluye `publico`, no adivinable — `Str::random(40)` o equivalente, nunca secuencial), `estado` (`activa`|`pausada`), `ultima_sincronizacion` nullable (no aplica a `nativo`), `ultimo_resultado` jsonb nullable, `ultimo_error` nullable, `creado_por_membership_id` FK nullable.
- Migración `encuesta_preguntas` (🏢): `tenant_id`, `encuesta_id` FK cascade, `campo` string nullable (para `nativo` no hay columna de origen que detectar — nullable), `etiqueta` (texto de la pregunta, escrito directamente por el coordinador en `nativo`), `tipo` (`opcion_unica`|`texto_libre`|`numerica`), `opciones` jsonb nullable (catálogo de opciones válidas para `opcion_unica`, definido explícitamente por el coordinador en `nativo`), `incluida` boolean — **default `true`** (todas las preguntas creadas vía `nativo` son intencionales; el default `false` para canales pull es cosa de F9, no de este spec). `orden` int. Único `(encuesta_id, campo)` solo cuando `campo` no es null (índice único parcial de Postgres: `whereNotNull` en la migración).
- Migración `encuesta_respuestas_agregadas` (🏢): `tenant_id`, `encuesta_id` FK cascade, `pregunta_id` FK `encuesta_preguntas` cascade, `ambito_tipo` (`seccion`|`zona`), `ambito_id` (sin FK — apunta a `secciones.id` o `zonas.id` según `ambito_tipo`), `opcion` string, `conteo` unsignedInteger default 0, `actualizado_en` timestamptz. Único `(encuesta_id, pregunta_id, ambito_tipo, ambito_id, opcion)`.
- `App\Actions\Encuestas\TabularRespuesta::handle(Encuesta $encuesta, string $ambitoTipo, int $ambitoId, array $respuestas): void` — `$respuestas` = `pregunta_id => opcion`. Solo tabula preguntas `incluida=true` y `tipo=opcion_unica` (las de `texto_libre`/`numerica` se ignoran silenciosamente para tabulación, aunque puedan aceptarse en el payload). **Modo incremental**: `conteo = conteo + 1` vía upsert (usa `DB::table('encuesta_respuestas_agregadas')->upsert(...)` con `increment` manual, o `firstOrNew`+`increment()`, lo que sea más limpio en Laravel 13 — revisa qué patrón de upsert usa el proyecto en otros lados, ej. `CoberturaSeccion::upsertParaSeccion`, aunque aquí es incremental no de reemplazo total).
- `App\Http\Controllers\EncuestaController`: `index`/`store`/`update` (alta de la encuesta + constructor de preguntas) — `rol:coordinador,admin`.
- **Flujo brigadista** (autenticado, dentro del grupo `tenant` existente): `App\Http\Controllers\EncuestaRespuestaController::store` (`POST /api/encuestas/{encuesta}/respuestas`) — recibe `{ambito_id, respuestas: {pregunta_id: opcion}}`, valida que cada opción pertenezca al catálogo de su pregunta, llama `TabularRespuesta`. **Nunca persiste la respuesta cruda de forma individual**, solo incrementa el agregado.
- **Flujo público, sin login** (**primera ruta de escritura pública del sistema** — hoy todo `routes/web.php` salvo `/` vive detrás de `auth`+`tenant`, revisa `routes/web.php` para confirmar esto antes de escribir las rutas nuevas):
  - Rutas nuevas fuera de `auth`/`tenant`, dentro del `web` middleware base de Laravel (conserva sesión+CSRF de Inertia): `GET /e/{token}` (`App\Http\Controllers\EncuestaPublicaController::show`, resuelve la `Encuesta` por `token_publico`, sin pasar por `TenantContext`/`ResolveTenant`; 404 si `modo_acceso` no incluye `publico` o el token no existe) y `POST /e/{token}/respuestas` (`EncuestaPublicaController::store`).
  - **`tenant_id` se resuelve del `tenant_id` de la propia `Encuesta` encontrada por token, nunca del request** — única excepción documentada a la regla "tenant_id nunca se acepta del request" de `_ai/CONTEXT.md`. Añade una nota breve sobre esta excepción en la sección "Nunca" de `_ai/CONTEXT.md` al cerrar (una sola línea, no reescribas la sección).
  - **Rate limiting obligatorio**: `throttle:5,1` de Laravel sobre `POST /e/{token}/respuestas` por IP (valor por defecto documentado como ajustable).
  - Sin `consentimiento`/`aviso_privacidad_id` (la respuesta nunca se liga a una identidad) — el formulario público muestra un aviso de transparencia ("tus respuestas son anónimas y se muestran agregadas"), buena práctica, no requisito legal ADR-004.
  - El respondiente elige su ámbito (zona/sección) desde un selector en la propia página pública, según `ambito_tipo` de la encuesta — para `ambito_tipo=seccion`, el selector lista `Seccion::where('municipio_id', $encuesta->tenant->municipio_id)`.
- Frontend: `resources/js/pages/Encuestas.vue` (nueva, sidebar al nivel de Brigadistas/Eventos — revisa `resources/js/components/AppSidebar.vue` o donde esté la navegación para agregar la entrada) — alta con constructor de preguntas (agregar/quitar preguntas dinámicamente, tipo, opciones), selector de canal (solo "Formulario propio" habilitado, deja el hueco visual para más adelante), selector de `modo_acceso` con el link público (`/e/{token}`) copiable cuando aplica. `resources/js/pages/EncuestaCaptura.vue` (autenticada, mismo espíritu mobile-first que `resources/js/pages/Captura.vue` — LÉELO antes de escribir la tuya) y `resources/js/pages/EncuestaPublica.vue` (sin layout autenticado, mobile-first) — ambas usan el mismo componente `resources/js/components/FormularioEncuesta.vue`, solo cambia de dónde sale `ambito_id` y a qué endpoint postean.

### No incluye
- Canales pull (Google Sheets, Twilio/voz) — F9 y una spec futura, no los toques.
- Visualización en mapa/ficha de sección o zona (F10), no los toques.
- Re-tabulación retroactiva del histórico si se edita una pregunta después de tener respuestas ya contadas.
- Anti-bot más allá de rate-limiting por IP (sin CAPTCHA en v1 — decisión ya tomada, no hace falta preguntar).
- Cualquier cosa de `Zona`/F6/F7 más allá del valor de string `ambito_tipo='zona'` sin validar contra tabla (ver nota arriba).

## Criterios de aceptación (tests)
1. Dado un coordinador que crea una encuesta `canal=nativo`, `ambito_tipo=seccion`, con 2 preguntas `opcion_unica` y sus opciones, al guardar, `encuesta_preguntas` queda con `incluida=true` en ambas por default.
2. Dado un brigadista autenticado con acceso a una sección de su tenant, al responder vía `POST /api/encuestas/{encuesta}/respuestas` con `ambito_id` de esa sección y una opción válida por pregunta, `encuesta_respuestas_agregadas` incrementa `conteo` en 1 para `(pregunta, seccion, opcion)`.
3. Dado dos respuestas seguidas con la misma opción en la misma sección, el conteo llega a 2 (incremental, no se sobreescribe).
4. Dado un payload con una opción que **no** está en `encuesta_preguntas.opciones` de esa pregunta, el request falla validación (422) sin tabular nada.
5. Dado `modo_acceso=publico` al crear/activar la encuesta, `encuestas.token_publico` se puebla con un valor único no adivinable (no secuencial, test verifica que no es predecible desde el `id`).
6. Dado `GET /e/{token}` con un token válido, la página pública carga sin sesión autenticada (sin redirección a login).
7. Dado más de 5 requests a `POST /e/{token}/respuestas` en 1 minuto desde la misma IP, la request 6 es rechazada con 429.
8. Dado una respuesta pública válida con un `tenant_id` falso incluido en el body del request, la respuesta se tabula usando el `tenant_id` real de la `Encuesta` encontrada por token — el valor del body se ignora por completo.
9. Dado `modo_acceso=brigadista` (sin público habilitado, `token_publico=null`), `GET /e/{token}` responde 404 para cualquier token.
10. Dado una pregunta `tipo=texto_libre` o `tipo=numerica`, nunca se tabula en `encuesta_respuestas_agregadas` aunque venga en el payload de respuesta.
11. Dado un viewer sin rol `coordinador`/`admin`, al intentar `POST /encuestas` (crear encuesta) recibe 403.

## Notas de implementación
- `token_publico`: usar `Str::random(40)` o equivalente — nunca derivarlo del `id` de la encuesta.
- `FormularioEncuesta.vue` es el componente compartido entre `EncuestaCaptura.vue` (autenticado) y `EncuestaPublica.vue` (público) — mismo formulario, distinto endpoint y origen de `ambito_id`.
- Documenta en `_ai/CONTEXT.md`, sección "Nunca", la excepción puntual sobre `tenant_id` resuelto del token en la ruta pública (una línea).
- `vendor/bin/pint --dirty --format agent` al cierre.

## Notas de cierre (implementación real)
- Rate limit de `POST /e/{token}/respuestas` implementado como `throttle:5,1` (5 requests/minuto por IP), tal cual pedía el spec — valor ajustable en la definición de la ruta en `routes/web.php`.
- `EncuestaRespuestaAgregada` sí tiene modelo Eloquent (con `BelongsToTenant`) para mantener el mismo patrón que `CoberturaSeccion`/`MetaSeccion`; no lleva factory propia porque en los tests solo se puebla vía `TabularRespuesta` (nunca directo).
- En el flujo público, `EncuestaPublicaController::resolverPorToken()` es el único punto de todo el sistema con `withoutGlobalScope(TenantScope::class)` fuera de jobs de sistema; justo después, `TenantContext::set($encuesta->tenant)` fija el tenant real (mismo patrón que los jobs/comandos que no heredan `currentTenant`, ej. `ActualizarCoberturaSeccion`), y el resto de queries (preguntas, tabulación) usa el scope normal.
- `EncuestaController::update` reemplaza el set completo de preguntas (borra + recrea) en vez de un diff fino; no hay re-tabulación retroactiva (fuera de alcance) y borrar una pregunta ya usada arrastra en cascada sus `encuesta_respuestas_agregadas`, consistente con "no incluye".
- 13 tests nuevos en `tests/Feature/Encuestas/EncuestasTest.php` cubren los 11 criterios de aceptación más 2 extra (index Inertia, update reemplaza preguntas). Suite completa corrida sin regresiones (los únicos fallos preexistentes en el worktree son por falta de `npm run build`, ajenos a F8).
