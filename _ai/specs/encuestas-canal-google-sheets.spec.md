# Spec — Canal de encuestas: Google Sheets (OAuth por tenant)

- **Feature ID**: F9 (iteración "Análisis territorial multinivel + encuestas multi-canal")
- **Estado**: Listo para implementar
- **Depende de**: F8 `encuestas-nucleo-canal-nativo.spec.md` (modelo `encuestas`/`encuesta_preguntas`/`encuesta_respuestas_agregadas`, Action `TabularRespuesta`)

## Objetivo
Agregar Google Sheets como segundo canal de encuestas, alimentando el modelo de datos ya construido en F8 sin modificarlo, autenticando **con la propia cuenta de Google de cada tenant** (OAuth, no una service account compartida).

## Por qué
El cliente ya usa Google Forms para captar respuestas fuera del sistema; conectar el Sheet resultante permite traer esas respuestas al mismo análisis territorial que el canal nativo. **Decisión (revisada respecto al diseño original)**: en vez de una service account de plataforma compartida (que exigía "comparte tu Sheet con este correo raro"), cada tenant conecta **su propia cuenta de Google** vía OAuth — UX más natural (el tenant ya tiene acceso a su propio Sheet, no hace falta compartir nada) y aislamiento real entre tenants (sin cuota de API compartida).

## Alcance

### Incluye
- **Setup externo previo (fuera de este spec, responsabilidad del operador/Edgar, no automatizable en código)**: crear un OAuth 2.0 Client ID tipo "Web application" en Google Cloud Console, con el redirect URI apuntando a `GET /settings/google/callback` del dominio real de la app, y cargar `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` en `.env`. Sin esto, el flujo OAuth no puede probarse contra Google real — los tests de este spec usan `Http::fake()` para simular las respuestas de Google, no credenciales reales.
- `config/services.php`: entrada `google` (`client_id`, `client_secret`, `redirect` — mismo patrón que `postmark`/`resend`/`ses` ya presentes en ese archivo).
- Migración `conexiones_google` (🏢, una por tenant — único `tenant_id`): `tenant_id`, `access_token` (cast `encrypted`), `refresh_token` (cast `encrypted`), `expira_en` timestamptz, `cuenta_email` string (email de la cuenta de Google conectada, para mostrar en la UI "Conectado como x@gmail.com"), `conectado_por_membership_id` FK nullable, `conectado_en` timestamptz. **Primera vez que este codebase guarda un secreto OAuth de terceros** — mismo criterio de cifrado en reposo que `electores.telefono`/`domicilio` (ADR-004), pero es un secreto operativo de la campaña, no un dato personal de un ciudadano; documentarlo como una categoría nueva de dato sensible en `_ai/CONTEXT.md` si aplica.
- `App\Http\Controllers\Settings\GoogleConexionController`: `show` (`GET /settings/google`, Inertia, `rol:coordinador,admin` — estado de conexión: conectado como `{email}` / no conectado), `conectar` (`GET /settings/google/conectar` — redirige a la URL de consentimiento de Google con `scope=https://www.googleapis.com/auth/spreadsheets.readonly`, `access_type=offline`, `prompt=consent` para forzar `refresh_token` incluso en reconexión, y un `state` aleatorio guardado en sesión para CSRF), `callback` (`GET /settings/google/callback` — valida `state` contra sesión, intercambia `code` por `access_token`+`refresh_token` vía `POST https://oauth2.googleapis.com/token`, guarda/actualiza `conexiones_google` del tenant), `desconectar` (`POST /settings/google/desconectar` — revoca el token contra Google (`POST https://oauth2.googleapis.com/revoke`) y borra la fila).
- `App\Contracts\Encuestas\FuenteEncuesta` (interfaz, ya prevista en el diseño original de F9, sin cambios):
  ```php
  interface FuenteEncuesta {
      public function verificarAcceso(Encuesta $encuesta): array;
      /** @return iterable<array{identificador: string, respuestas: array<string, mixed>}> */
      public function obtenerRespuestas(Encuesta $encuesta): iterable;
  }
  ```
- `App\Support\Encuestas\ResolverFuenteEncuesta` — resuelve la implementación concreta por `Encuesta.canal`.
- `App\Support\GoogleSheets\ClienteSheets` (sin dependencias Eloquent en runtime): dado el `tenant_id`, carga `conexiones_google`, **refresca el `access_token` si está vencido o por vencer** (`POST https://oauth2.googleapis.com/token` con `grant_type=refresh_token`, actualiza `access_token`/`expira_en` en la tabla), y expone `obtenerValores(spreadsheetId, rango): array` llamando la API REST de Sheets v4 con `Illuminate\Support\Facades\Http` y el `access_token` vigente en el header `Authorization: Bearer`. **Sin dependencias nuevas de Composer** — el intercambio/refresco de tokens es una llamada HTTP estándar, no requiere firmar JWT (eso solo aplicaba al diseño de service account, descartado).
- `App\Services\Encuestas\GoogleSheetsFuente implements FuenteEncuesta`: envuelve `ClienteSheets`, traduce filas del Sheet a `{identificador, respuestas}`. `configuracion_canal` de la `Encuesta` para este canal: `{spreadsheet_id, nombre_hoja, campo_identificador}` (ya NO necesita nada de credenciales — esas viven en `conexiones_google`, a nivel tenant, no por encuesta).
- `App\Actions\Encuestas\SincronizarEncuesta::handle(Encuesta $encuesta): array`: resuelve la `FuenteEncuesta`, llama `obtenerRespuestas()`, sincroniza el catálogo de preguntas (columnas nuevas → `incluida=false`), resuelve `ambito_id` haciendo match del `identificador` contra `Seccion.numero`/`Zona.clave` (**el valor crudo del identificador nunca se persiste**), y llama a la misma `TabularRespuesta` de F8 por cada fila, en **modo recuento completo** (borra e inserta los conteos de esta encuesta antes de tabular) — mismo espíritu idempotente que `RecalcularCoberturaSeccion`.
- `EncuestaController::verificarAcceso`/`sincronizar` (`POST /api/encuestas/{encuesta}/sincronizar`, `rol:coordinador,admin`) — corre **síncrono en el request**. Si el tenant no tiene `conexiones_google`, responde con un error claro dirigiendo a `/settings/google`.
- Frontend: `resources/js/pages/settings/Google.vue` (nueva, mismo patrón que `settings/Campana.vue`) con botón "Conectar con Google" (redirige a `google.conectar`) / estado conectado + botón "Desconectar". Extender `Encuestas.vue` con el canal "Google Sheets" en el selector (URL con parseo del `spreadsheet_id`, nombre de hoja, botón "Verificar acceso", selector de campo identificador — si el tenant no está conectado, deshabilitar este canal con link a `/settings/google`), botón de sync + resultado ("N procesadas / M sin match"), tabla de preguntas detectadas con toggle "incluida".

### No incluye
- Sincronización automática/programada — solo bajo demanda ("Sincronizar ahora"), sin jobs de cola ni scheduler en v1.
- Canal Twilio/voz — spec futura (`encuestas-canal-voz.spec.md`), mismo patrón (`TwilioVozFuente implements FuenteEncuesta`), sin tocar este spec ni F8.
- Multi-cuenta de Google por tenant (un tenant = una conexión de Google; si necesita leer Sheets de cuentas distintas, se resuelve compartiendo esos Sheets con la cuenta ya conectada — no se construye selector de múltiples cuentas).
- Automatizar la creación del OAuth Client ID en Google Cloud Console (paso manual externo, documentado en `docs/` como parte del setup de producción, no en código).

## Criterios de aceptación (tests)
1. Dado un coordinador sin conexión de Google, al visitar `GET /settings/google`, ve el estado "no conectado" con el botón "Conectar con Google".
2. Dado el flujo de callback con un `state` que no coincide con el guardado en sesión, `GET /settings/google/callback` rechaza la request (403/422) sin guardar ninguna conexión — protección CSRF del callback OAuth.
3. Dado un callback válido (`Http::fake()` simulando la respuesta de intercambio de tokens de Google), `conexiones_google` se crea/actualiza con `access_token`/`refresh_token` cifrados y `cuenta_email` poblado desde la respuesta de Google.
4. Dado un tenant ya conectado, al reconectar (nuevo `conectar`→`callback`), la fila existente se actualiza (upsert por `tenant_id`), no se duplica.
5. Dado un `access_token` vencido (`expira_en` en el pasado) y un `refresh_token` válido, al llamar `ClienteSheets::obtenerValores()`, se refresca automáticamente el token (`Http::fake()` simulando el refresh) antes de la llamada a Sheets, y `conexiones_google` queda con el nuevo `access_token`/`expira_en`.
6. Dado `POST /settings/google/desconectar`, la fila de `conexiones_google` del tenant se borra y se llama al endpoint de revocación de Google (verificar con `Http::fake()` que se hizo la llamada).
7. Dado una `Encuesta` `canal=google_sheets` de un tenant **sin** `conexiones_google`, al llamar `verificarAcceso`/`sincronizar`, responde con un error claro indicando que hace falta conectar Google, sin lanzar excepción no controlada.
8. Dado un Sheet con 10 filas de respuestas y una columna identificadora que matchea números de sección existentes, al sincronizar, `encuesta_respuestas_agregadas` refleja el recuento completo de esas 10 filas y `encuestas.ultima_sincronizacion` se actualiza.
9. Dado 2 filas cuyo valor de columna identificadora no matchea ninguna `Seccion`/`Zona`, al sincronizar, esas 2 se cuentan como "sin match" en `ultimo_resultado`, sin fallar la sincronización completa.
10. Dado una columna nueva en el Sheet que no existía en una sincronización anterior, al sincronizar, se crea en `encuesta_preguntas` con `incluida=false`.
11. Dado sincronizar dos veces seguidas sin cambios en el Sheet, el resultado en `encuesta_respuestas_agregadas` es idéntico (idempotente).
12. Dado el valor crudo de la columna identificadora de cada fila, nunca se persiste en ninguna tabla.
13. Dado dos tenants distintos, cada uno con su propia `conexiones_google`, sincronizar el Sheet de uno nunca usa el token del otro (aislamiento — test explícito, dado que ahora hay credenciales por tenant en vez de una service account compartida, este es el riesgo nuevo más importante a probar).

## Notas de implementación
- Todos los tests de OAuth usan `Http::fake()` para simular las respuestas de Google (`oauth2.googleapis.com/token`, `oauth2.googleapis.com/revoke`, `sheets.googleapis.com/...`) — no se requieren credenciales reales de Google Cloud para que la suite pase. La verificación end-to-end contra Google real queda como paso manual posterior, una vez Edgar cargue `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` reales en un entorno con dominio público (el `redirect_uri` de OAuth no funciona con `localhost`/dominios `.test` salvo que Google Cloud lo permita explícitamente en modo testing).
- `access_token`/`refresh_token`: cast `encrypted` de Laravel (mismo mecanismo que `electores.telefono`), nunca loguear su valor.
- El refresco de token debe ser defensivo: si el `refresh_token` fue revocado manualmente por el usuario desde su cuenta de Google (fuera del control de la app), la llamada de refresh fallará — `ClienteSheets` debe capturar ese caso y traducirlo a un error claro ("reconecta tu cuenta de Google en Configuración"), borrando la conexión inválida para que la UI vuelva a mostrar "no conectado" en vez de fallar en bucle.
- `vendor/bin/pint --dirty --format agent` al cierre.
