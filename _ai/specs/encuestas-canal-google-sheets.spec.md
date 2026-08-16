# Spec — Canal de encuestas: Google Sheets

- **Feature ID**: F9 (iteración "Análisis territorial multinivel + encuestas multi-canal")
- **Estado**: Borrador
- **Depende de**: F8 `encuestas-nucleo-canal-nativo.spec.md` (modelo `encuestas`/`encuesta_preguntas`/`encuesta_respuestas_agregadas`, Action `TabularRespuesta`)

## Objetivo
Agregar Google Sheets como segundo canal de encuestas, alimentando el modelo de datos ya construido en F8 sin modificarlo.

## Por qué
El cliente ya usa Google Forms para captar respuestas fuera del sistema; conectar el Sheet resultante permite traer esas respuestas al mismo análisis territorial que el canal nativo, sin duplicar ningún concepto.

## Alcance

### Incluye
- `App\Contracts\Encuestas\FuenteEncuesta` (interfaz, punto de extensión para cualquier canal "pull"):
  ```php
  interface FuenteEncuesta {
      /** Prueba de conexión: columnas/campos detectados en el origen. */
      public function verificarAcceso(Encuesta $encuesta): array;

      /** @return iterable<array{identificador: string, respuestas: array<string, mixed>}> */
      public function obtenerRespuestas(Encuesta $encuesta): iterable;
  }
  ```
- `App\Support\Encuestas\ResolverFuenteEncuesta` — resuelve la implementación concreta por `Encuesta.canal` (`match()` simple o binding en el contenedor).
- `config/services.php`: entrada `google_sheets` (`service_account_email`, `service_account_private_key` desde `.env`) — mismo patrón que `postmark`/`resend`/`ses` ya presentes en ese archivo.
- `App\Support\GoogleSheets\ClienteSheets` (sin dependencias Eloquent en runtime, mismo espíritu que `App\Support\Bloques`): firma JWT (RS256) con la clave de la service account, obtiene access token de Google, `obtenerValores(spreadsheetId, rango): array` llamando la API REST de Sheets v4 con `Illuminate\Support\Facades\Http`. Se evita el SDK completo `google/apiclient` (pesado); solo se agrega `firebase/php-jwt` (versión exacta pinneada) para firmar el JWT.
- `App\Services\Encuestas\GoogleSheetsFuente implements FuenteEncuesta`: envuelve `ClienteSheets`, traduce filas del Sheet a `{identificador, respuestas}`. `configuracion_canal` para este canal: `{spreadsheet_id, nombre_hoja, campo_identificador}` (`campo_identificador` = header exacto de la columna que identifica sección/zona).
- `App\Actions\Encuestas\SincronizarEncuesta::handle(Encuesta $encuesta): array`: resuelve la `FuenteEncuesta`, llama `obtenerRespuestas()`, sincroniza el catálogo de preguntas (columnas nuevas → `incluida=false`, requieren revisión manual del coordinador — a diferencia de las preguntas `nativo` de F8, aquí sí aplica el allow-list de privacidad porque llegan sin que nadie las haya pedido explícitamente), resuelve `ambito_id` haciendo match del `identificador` de cada fila contra `Seccion.numero`/`Zona.clave` (**el valor crudo del identificador nunca se persiste**, solo se usa para resolver el FK), y llama a **la misma `TabularRespuesta` de F8** por cada fila, pero en **modo recuento completo** (borra e inserta los conteos de esta encuesta antes de tabular, porque el Sheet siempre es el estado completo actual, no un log incremental) — mismo espíritu idempotente que `RecalcularCoberturaSeccion`.
- `EncuestaController::verificarAcceso` (test de conexión, devuelve headers detectados para elegir `campo_identificador`) y `EncuestaController::sincronizar` (`POST /api/encuestas/{encuesta}/sincronizar`, `rol:coordinador,admin`) — corre **síncrono en el request** (botón "Sincronizar ahora", sin jobs programados en v1; límite conocido si el Sheet crece a miles de filas).
- `EncuestaController::preguntas` (`GET/PUT`) — UI para marcar `incluida` por pregunta detectada.
- Frontend: extender `Encuestas.vue` con el canal "Google Sheets" en el selector (URL con parseo del `spreadsheet_id`, nombre de hoja, botón "Verificar acceso", selector de campo identificador), botón de sync + resultado ("N procesadas / M sin match"), tabla de preguntas detectadas con toggle "incluida".

### No incluye
- Sincronización automática/programada — solo bajo demanda ("Sincronizar ahora"), sin jobs de cola ni scheduler en v1.
- Canal Twilio/voz — queda para una spec futura (`encuestas-canal-voz.spec.md`), mismo patrón (`TwilioVozFuente implements FuenteEncuesta`), sin tocar este spec ni F8.
- Credenciales OAuth por tenant — v1 usa una service account compartida a nivel plataforma (ver decisión abierta).

## Decisión abierta a confirmar antes de codear
Una sola **service account de Google** compartida a nivel plataforma (recomendado: el cliente comparte su Sheet como lector con un correo fijo que el sistema entrega, sin manejar OAuth/refresh tokens por tenant) frente a credenciales OAuth propias por tenant (más complejo: flujo de consentimiento, refresh tokens, revocación). También validar la cuota de la API de Google compartida entre tenants si hay muchas sincronizaciones simultáneas.

## Criterios de aceptación (tests)
1. Dado una `Encuesta` `canal=google_sheets` con `spreadsheet_id`/`nombre_hoja` válidos y acceso de lectura otorgado a la service account, al llamar `verificarAcceso`, devuelve la lista de headers de la fila 1.
2. Dado un Sheet sin acceso otorgado a la service account, al llamar `verificarAcceso`, responde con un error claro (401/403 de Google traducido a mensaje entendible), sin caerse.
3. Dado un Sheet con 10 filas de respuestas y una columna identificadora que matchea números de sección existentes, al sincronizar, `encuesta_respuestas_agregadas` refleja el recuento completo de esas 10 filas y `encuestas.ultima_sincronizacion` se actualiza.
4. Dado 2 filas cuyo valor de columna identificadora no matchea ninguna `Seccion`/`Zona`, al sincronizar, esas 2 se cuentan como "sin match" en `ultimo_resultado`, sin fallar la sincronización completa.
5. Dado una columna nueva en el Sheet que no existía en una sincronización anterior, al sincronizar, se crea en `encuesta_preguntas` con `incluida=false`.
6. Dado sincronizar dos veces seguidas sin cambios en el Sheet, el resultado en `encuesta_respuestas_agregadas` es idéntico (idempotente, el recuento completo no duplica).
7. Dado una columna con `tipo=texto_libre` detectado por alta cardinalidad, nunca se tabula aunque esté `incluida=true`.
8. Dado dos tenants con Sheets distintos, sincronizar uno no afecta las respuestas agregadas del otro.
9. Dado el valor crudo de la columna identificadora de cada fila, nunca se persiste en ninguna tabla (test que confirma que no aparece en `encuesta_respuestas_agregadas` ni en ninguna otra columna).

## Notas de implementación
- El access token de Google (válido ~60 min) se cachea brevemente dentro de una misma sincronización (`Cache::remember` ~50 min) para no pedir uno nuevo por cada llamada.
- `TabularRespuesta` de F8 se reusa pasando un modo "recuento completo": transacción que borra los `encuesta_respuestas_agregadas` de esa encuesta y los vuelve a insertar fila por fila del Sheet, en vez del `+1` incremental del canal nativo.
- `vendor/bin/pint --dirty --format agent` al cierre.
