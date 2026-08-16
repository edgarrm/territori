# Seguridad y protección de datos — Territori

> Matriz de controles: qué protege cada uno, dónde vive en el código y qué test lo
> fija. Pensado para responder una auditoría de cliente sin tener que leer la
> aplicación entera.
>
> Los principios están en [`ADR-004`](../_ai/adrs/ADR-004-privacidad.md) y el
> aislamiento multi-campaña en [`ADR-001`](../_ai/adrs/ADR-001-multi-tenancy.md).
> Esto es la evidencia de que se cumplen. El checklist de despliegue está en
> [`operaciones.md`](operaciones.md).
>
> **Esto no es asesoría legal.** Las referencias a la LFPDPPP indican qué
> obligación busca satisfacer cada control, no una opinión jurídica.

## Cómo leer la matriz

Cada control tiene un test que falla si el control desaparece. Para verificar
cualquier fila, corre el archivo indicado:

```bash
php artisan test --compact tests/Feature/Seguridad
```

## 1. Identidad y acceso

| Control | Dónde vive | Test que lo fija |
|---|---|---|
| Autenticación con Fortify; el auto-registro se cierra por configuración y devuelve 404 | `app/Http/Middleware/BloqueaRegistroPublico.php` | `tests/Feature/Auth/RegistrationTest.php` |
| Rate limit de login (5/min por email+IP), y throttle propio en reset, registro y confirmación de contraseña | `app/Providers/FortifyServiceProvider.php` | `Seguridad/HardeningTest.php` |
| 2FA (TOTP) y passkeys, ambas con reconfirmación de contraseña | `config/fortify.php`, `Settings/SecurityController.php` | `Settings/SecurityTest.php` |
| **2FA obligatoria en las superficies con datos personales**: export, bandeja ARCO, `/capturados` y su endpoint, y el detalle de sección con su lista de electores. El dashboard no la exige porque solo muestra agregados | `routes/web.php`, `app/Http/Middleware/RequiereDobleFactor.php` | `Seguridad/DobleFactorObligatorioTest.php` |
| **Cambiar la contraseña invalida las demás sesiones** | `bootstrap/app.php` (`authenticateSessions`), `Settings/SecurityController::update` | `Settings/SecurityTest::test_cambiar_la_contrasena_invalida_las_demas_sesiones` |
| El rol vive en la membresía de campaña, nunca en el usuario; una membresía desactivada no concede acceso | `app/Models/User::membershipEn()`, `Http/Middleware/ResolveTenant.php` | `Tenancy/EnsureRolMiddlewareTest.php`, `Seguridad/HardeningTest.php` |
| Un coordinador no puede crear coordinadores ni admins (sin escalación de privilegios) | `app/Models/Membership::rolesQuePuedeAsignar()` | `Brigadistas/GestionBrigadistasTest.php` |
| **El último admin activo de una campaña no puede eliminar su cuenta** | `app/Http/Requests/Settings/ProfileDeleteRequest.php` | `Settings/BorradoDeCuentaTest.php` |

## 2. Aislamiento entre campañas

| Control | Dónde vive | Test que lo fija |
|---|---|---|
| Global scope por campaña, **fail-closed**: sin campaña activa las consultas no devuelven nada | `app/Models/Concerns/TenantScope.php` | `Tenancy/BelongsToTenantTest.php` |
| `tenant_id` nunca es asignable en masa; lo fija el trait al crear | `app/Models/Concerns/BelongsToTenant.php` | `Tenancy/BelongsToTenantTest.php` |
| La campaña se resuelve por subdominio o sesión y **siempre** se valida contra una membresía activa; nunca se lee del request | `app/Http/Middleware/ResolveTenant.php` | `Tenancy/ResolveTenantMiddlewareTest.php` |
| **Sin IDOR**: las 17 rutas con parámetro resuelven el modelo a mano dentro del scope de campaña (el binding implícito correría antes de `ResolveTenant`) y luego aplican regla de pertenencia. Un id de otra campaña da 404 | Todos los controladores; ver `ElectorController::show()` como patrón | `Seguridad/IdorTest.php`, `Tenancy/CatalogoNoEscopadoTest.php` |
| Las consultas SQL crudas (mapa, dashboard, ratios) filtran por `tenant_id` / `municipio_id` explícitamente | `MapaController`, `DashboardController`, `Support/Brigadistas/RatiosBrigadista.php` | `MapaCobertura/AislamientoMetasCoberturaTest.php` |
| El hash de teléfono lleva sal por campaña: dos campañas rivales no pueden cruzar padrones comparando hashes | `app/Support/Telefono.php` | `Privacidad/CifradoEnReposoTest.php` |

## 3. Datos personales (LFPDPPP)

| Control | Dónde vive | Test | Obligación |
|---|---|---|---|
| Cifrado en reposo de teléfono, email, domicilio, observaciones y ubicación | casts `encrypted` en `app/Models/Elector.php` | `Privacidad/CifradoEnReposoTest.php` | art. 19 |
| Consentimiento obligatorio con fecha, modalidad y versión del aviso de privacidad | `StoreElectorRequest`, `Actions/Electores/CapturarElector.php` | `Captura/AvisoPrivacidadTest.php` | art. 8, 16 |
| **Enmascarado por mínimo privilegio**: quien no capturó el registro ve nombre en iniciales, teléfono con 4 dígitos y domicilio/observaciones ocultos | `app/Support/Pii.php`, `Concerns/PresentaElectores::puedeVerPii()` | `Privacidad/VisibilidadPiiTest.php` | art. 19 |
| **El mismo criterio aplica al timeline y a la agenda**: la nota de una interacción y el nombre en la agenda se enmascaran igual que en las listas | `app/Http/Controllers/InteraccionController.php` | `Privacidad/VisibilidadPiiTest.php` | art. 19 |
| Bitácora de accesos a datos personales, inmutable y sin valores personales dentro | `Actions/Privacidad/RegistrarAcceso.php`, `app/Models/BitacoraAcceso.php` | `Seguridad/BitacoraTest.php` | art. 21 |
| Derechos ARCO completos: Acceso (reporte íntegro al titular), Rectificación, Cancelación (scrub + baja lógica) y Oposición (`no_contactar`, que saca al titular de agenda y export) | `Http/Controllers/SolicitudArcoController.php`, `Actions/Privacidad/` | `Privacidad/DerechosArcoTest.php`, `ArcoTest.php` | art. 22–34 |
| Plazo legal de 20 días hábiles calculado y visible en la bandeja | `app/Models/SolicitudArco.php` | `Privacidad/DerechosArcoTest.php` | art. 32 |
| Export del padrón: 2FA + reconfirmar contraseña + 3/hora + tope de 50 000 filas + traza + mitigación de CSV injection | `Http/Controllers/ExportController.php`, `routes/web.php` | `Export/ExportElectoresTest.php`, `Seguridad/BitacoraTest.php` | art. 19, 21 |
| **Retención**: al cerrar una campaña arranca el plazo tras el cual se suprime la PII de sus contactos; los cancelados se borran físicamente; las sesiones caducadas se limpian | `Console/Commands/PurgarDatosCommand.php`, programado en `routes/console.php` | `Privacidad/RetencionTest.php` | art. 11 |
| **Cerrar campaña es una acción de la app** (admin + 2FA + contraseña + traza), no un UPDATE manual, y su reloj (`cerrada_en`) no lo mueve ninguna otra escritura | `Actions/Campana/CerrarCampana.php`, `resources/js/components/CerrarCampana.vue` | `Campana/CerrarCampanaTest.php` | art. 11 |
| Crear campaña deja traza: es el acto que abre un tratamiento nuevo | `Actions/Campana/RegistrarCampana.php` | `Tenancy/RegistrarCampanaTest.php` | art. 21 |
| No se ingieren nombres del padrón del INE; solo cartografía y estadística agregada públicas | `Database/Seeders/CartografiaSeeder.php` | `CartografiaSeederTest.php` | — |

## 4. Endurecimiento de la aplicación

| Control | Dónde vive | Test que lo fija |
|---|---|---|
| CSP con nonce, HSTS sobre HTTPS, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` | `app/Http/Middleware/CabecerasSeguridad.php` | `Seguridad/HardeningTest.php` |
| **Cabecera `Host` validada** contra `APP_URL` y `TENANT_DOMAIN` (con subdominios): sin esto un `Host:` ajeno envenena el enlace de reset y envía el token al atacante | `bootstrap/app.php` (`trustHosts`) | `Seguridad/CabeceraHostTest.php` |
| **Proxies de confianza sin default permisivo**: sin `TRUSTED_PROXIES` no se confía en ninguno, y `X-Forwarded-For` deja de poder falsear la IP del rate limit y de la bitácora | `config/app.php`, `AppServiceProvider::configurarProxiesDeConfianza()` | `Seguridad/RutasDesarrolloTest.php` |
| `X-Forwarded-Host` y `-Prefix` nunca se confían | `AppServiceProvider::configurarProxiesDeConfianza()` | `Seguridad/RutasDesarrolloTest.php` |
| Las rutas que publican `laravel/dusk` y `laravel/boost` fuera de producción se bloquean; `/_dusk/login/{id}` sería un bypass total de autenticación | `app/Http/Middleware/BloqueaRutasDeDesarrollo.php` | `Seguridad/RutasDesarrolloTest.php` |
| **La app no arranca en producción** con `APP_DEBUG` activo, sin cookie de sesión segura, sin cifrado de sesión o con proxies en `*` | `AppServiceProvider::verificarConfiguracionDeProduccion()` | `Seguridad/RutasDesarrolloTest.php` |
| Rate limit global de dominio (120/min) y específico en alta de electores (20/min), que es el oráculo de enumeración por teléfono duplicado | `routes/web.php` | `Seguridad/HardeningTest.php` |
| Todo campo de texto libre tiene tope de longitud (`observaciones` y `nota`, 2000) | `StoreElectorRequest`, `StoreInteraccionRequest` | `Interacciones/RegistrarInteraccionTest.php` |
| Cadena de suministro: `min-release-age=7`, `ignore-scripts`, `save-exact`, lockfiles versionados | `.npmrc` | — (verificación manual: `composer audit`, `npm audit`) |
| Análisis estático sin errores y formato uniforme | `phpstan.neon`, `pint.json` | `vendor/bin/phpstan`, `vendor/bin/pint --test` |

## 5. Riesgos aceptados

Decisiones conscientes, documentadas para que un auditor no las lea como
descuidos:

| Riesgo | Por qué se acepta |
|---|---|
| `style-src 'unsafe-inline'` en la CSP | La UI usa `:style` dinámico en varios componentes y CSP no puede dar nonce a un atributo `style=""`. El vector real de un XSS es ejecutar script, y `script-src` sí es estricto (self + nonce). |
| Distinción 403 vs 404 dentro de la propia campaña | Un miembro puede distinguir "existe pero no es mío" de "no existe" para ids de **su** campaña. Unificar todo a 404 perdería la semántica correcta del 403 sin reducir riesgo: el espacio de ids ya es el suyo y las listas le muestran esos registros enmascarados. |
| `v-html` en el QR de 2FA | `TwoFactorSetupModal.vue` renderiza el SVG que genera Fortify, no entrada de usuario. Es el único sink de HTML crudo del frontend; queda anotado para que no se replique el patrón. |
| Un admin puede crear campañas sin límite | Sin tope de plan por ahora. Mitigado con traza (`campana.creada`): es detectable a posteriori. Pendiente de decisión de producto. |
| La bitácora no tiene alertas | Registra, no notifica. Detección a posteriori, no prevención en tiempo real. |

## 6. Fuera del alcance de la aplicación

Controles que dependen del despliegue, no del código. Están en el checklist de
[`operaciones.md`](operaciones.md) y **deben verificarse en cada entorno**:

- HTTPS forzado y redirección a nivel de plataforma (la app emite HSTS, no redirige).
- Respaldos cifrados de PostgreSQL, con restauración probada y **retención alineada
  con `RETENCION_*`**: no sirve suprimir por el art. 11 si el respaldo de hace dos
  años conserva todo.
- Scheduler corriendo: sin él `territori:purgar-datos` nunca se dispara y el
  incumplimiento del art. 11 es silencioso.
- Rol de base de datos dedicado, no superusuario, y `DB_SSLMODE=require`.
- Custodia y rotación de `APP_KEY` (cifra la PII y firma el hash de teléfono).
- Designación del responsable ante el INAI y del procedimiento de atención de
  solicitudes ARCO: la app calcula el plazo de 20 días hábiles, pero no avisa a
  nadie cuando está por vencer.
