# Arquitectura técnica — Territori

> Documento para un desarrollador que se incorpora al proyecto. Consolida lo disperso en `_ai/CONTEXT.md` y los ADRs. Cuando algo aquí contradiga `_ai/CONTEXT.md`, gana CONTEXT.md (o se actualiza explícitamente). Las decisiones formales viven en [`_ai/adrs/`](../_ai/adrs/); el modelo de datos completo en [`_ai/docs/data-model.md`](../_ai/docs/data-model.md).

## Capas

```
Vue 3 + Inertia (resources/js/pages)
        │  (props server-side; sin API REST para la app interna)
        ▼
Controladores (app/Http/Controllers)  ── FormRequests (validación + authorize)
        │
        ▼
Actions de dominio (app/Actions/**)    ── Support (app/Support/**: Telefono, Tenancy, Brigadistas)
        │
        ▼
Modelos Eloquent (app/Models) ── PostgreSQL 16 + PostGIS
        │
        ▼
Eventos de dominio → Listeners → Jobs (cobertura)
```

La lógica de negocio vive en **Actions** de un solo método (`handle()`), no en los controladores. Los controladores resuelven el request, delegan a la Action y devuelven Inertia/JSON. Las funciones espaciales se ejecutan en PostGIS vía el paquete `matanyadaev/laravel-eloquent-spatial` o SQL crudo.

## Multi-tenancy (ADR-001)

Single-DB, aislamiento por columna `tenant_id`.

- **`App\Models\Concerns\BelongsToTenant`** + **`TenantScope`**: global scope que filtra por `App\Support\Tenancy\TenantContext`. **Sin tenant activo → cero resultados** (nunca todos: falla cerrada).
- **`ResolveTenant`** (middleware, stack `web`): resuelve el tenant del subdominio (`config('app.tenant_domain')`) o de la sesión. El `tenant_id` se resuelve del **usuario autenticado**, NUNCA del request.
- **`EnsureRol`** (alias `rol:` en `bootstrap/app.php`): lee la membresía activa vía `User::membershipEn()`. El rol vive en `memberships`, **nunca** en `users`.
- **`EnsureTenantSeleccionado`**: bloquea rutas que requieren un tenant ya elegido (alta self-service de campaña).

### Reglas de oro (multi-tenant)

- Nunca aceptar `tenant_id` desde el cliente/request.
- Nunca leer el rol desde `users` (es por campaña, vive en la membresía).
- Nunca ligar una captura a `user_id`: usar `membership_id` (ata la persona al tenant correcto).
- Prohibido `withoutGlobalScopes()` salvo en jobs de sistema documentados.

### ⚠️ Trampas de arquitectura (ya encontradas y resueltas — respétalas)

1. **`SubstituteBindings` corre ANTES que `ResolveTenant`.** Por eso el binding implícito de un modelo tenant-scoped (`{elector}`, `{evento}`, `{interaccion}`) se resolvería **sin** `TenantContext` (en prod → 404 al dueño legítimo; nunca fuga cross-tenant porque falla cerrada). **Regla:** resolver modelos tenant-scoped **manualmente** en el controlador con `Model::query()->findOrFail($id)` (corre tras `ResolveTenant`), nunca por type-hint de binding implícito.
2. **PK compuesta rompe `save()`/`update()` de instancia.** `CoberturaSeccion` (PK `tenant_id+seccion_id`) y los pivotes (`brigadista_seccion`) NO se escriben con `save()` — generaba un `UPDATE` sin WHERE que tocaba toda la tabla. Usar `CoberturaSeccion::upsertParaSeccion(...)` (filtra explícito) y `belongsToMany(...)->sync(...)` con payload `tenant_id`. `CoberturaSeccion::save()` lanza `LogicException`.
3. **`Membership` NO usa `BelongsToTenant`** (intencional: se consulta para resolver el tenant mismo). Por eso TODA query de brigadistas/pivote filtra `tenant_id` **explícito** y resuelve `{membership}` a mano.
4. **Los jobs no heredan el `TenantContext`**: `ActualizarCoberturaSeccion` lo fija desde su `tenant_id` antes de consultar.

## PostGIS (ADR-002)

- Todo es **SRID 4326** de punta a punta. Nunca mezclar SRIDs.
- Detección de sección por GPS con `ST_Contains` (limitado al municipio del tenant).
- La geometría servida al frontend va **simplificada** (`ST_SimplifyPreserveTopology`) y/o cacheada por municipio. La geometría full se conserva para `ST_Contains`.
- La extensión se habilita por **migración** (`enable_postgis`), no a mano, para que sea reproducible.

## Captura unificada y cobertura (ADR-003)

Los **3 modos** de captura (`loteria` | `individual` | `evento`) escriben en la misma tabla `electores`, diferenciados por `modo_captura`:

- **Lotería**: sesión de captura masiva; la sección se hereda de la lotería abierta. Una sola sesión abierta por brigadista.
- **Individual**: sección explícita o resuelta por GPS (`ST_Contains`).
- **Evento**: la sección se hereda del evento (`evento.seccion_id`); fallback a sección/GPS si el evento no tiene sede.

El mapa **nunca** agrega en vivo sobre `electores`. Lee de la tabla derivada **`cobertura_seccion`**, mantenida así:

```
CapturarElector → event ElectorCapturado
    → listener ActualizarCoberturaAlCapturar (registrado explícito en AppServiceProvider::boot, NO auto-discovery)
        → job ActualizarCoberturaSeccion (fija TenantContext desde tenant_id)
            → Action RecalcularCoberturaSeccion (RECUENTA la sección — idempotente, no +1)
```

El comando `territori:recalcular-cobertura {tenant}` reusa la misma Action (idempotente).

## Captura, dedup y consentimiento

- **`App\Support\Telefono`**: normaliza (últimos 10 dígitos MX, null si <10) y hashea HMAC-SHA256 con `APP_KEY` → `telefono_hash` para dedup.
- **Dedup por tenant**: re-captura del mismo teléfono → `App\Exceptions\ElectorDuplicado` → 409 con el id existente.
- **Consentimiento obligatorio**: no se persiste un elector sin `consentimiento=true` y `aviso_privacidad_id` (regla `accepted` en el FormRequest).
- `membership_id` SIEMPRE se resuelve en el servidor vía `User::membershipEn`, nunca del request.

## Privacidad / ARCO (ADR-004)

- `telefono` y `domicilio` con cast `encrypted` (cifrados en reposo). `telefono_hash` separado para dedup.
- **Cancelación (derecho ARCO)** = **baja lógica** (`SoftDeletes`, NO borrado físico), **solo coordinador/admin** (`CancelarElector`): transacción que **scrubea la PII** (`nombre`/`telefono`→'' por NOT NULL; `telefono_hash`/`domicilio`/`ubicacion`/`observaciones`→null) + `softDelete` + registra `solicitudes_arco` (`cancelacion`/`atendida`) + recobertura de la sección.
- `SoftDeletes` + `BelongsToTenant` son dos global scopes que conviven: dedup/listas/recobertura/export ya excluyen trasheados (re-capturar el mismo teléfono tras cancelar funciona porque el hash se scrubbeó).
- **Export CSV** (`ExportController`): `streamDownload` + `cursor()`, PII descifrada por el cast, con mitigación anti-CSV-injection (prefija `'` a celdas que abren con `= + - @`).
- Nunca se capturan campos de intención de voto o afiliación (fuera de alcance, riesgo legal).

## White-label + facturación (ADR-005)

- Marca por tenant (`marca_nombre`/`marca_logo_url`/`marca_color`/`subdominio`). Branding compartido vía `HandleInertiaRequests` (fallback "Territori") e inyectado como `--brand` en `AppLayout.vue`.
- Cobro por **brigadista activo** = `membership` con `rol=brigadista` y `activo=true`; `tenants.limite_brigadistas` por plan. Activar por encima del tope → 422 con upsell. `RatiosBrigadista` calcula los agregados sin N+1 (una query con `COUNT(*) FILTER`).

## Mapa de directorios

```
app/
├── Actions/            Lógica de dominio (Campana, Electores, Loterias, Metas,
│                       Brigadistas, Interacciones, Eventos, Privacidad, Cobertura)
├── Support/            Telefono, Tenancy/{TenantContext}, Brigadistas/RatiosBrigadista
├── Models/             Eloquent (+ Concerns/BelongsToTenant, TenantScope)
├── Http/
│   ├── Controllers/    Resuelven request → delegan a Actions
│   ├── Requests/       Store*/Update* (validación + authorize por tenant/rol)
│   ├── Middleware/     ResolveTenant, EnsureRol, EnsureTenantSeleccionado, HandleInertiaRequests
│   └── Responses/      LoginResponse (sustituye la de Fortify)
├── Events/             ElectorCapturado
├── Jobs/               ActualizarCoberturaSeccion
└── Console/Commands/   territori:recalcular-cobertura
resources/js/pages/     Mapa, Captura, Metas, Brigadistas, Agenda, Elector, Eventos,
                        Seccion, campanas/{Crear,Seleccionar,SinMembership}
database/seeders/       CartografiaSeeder, DemoTenantSeeder, DemoCapturasSeeder
```

## Convenciones

- Migraciones: una por tabla, con índices compuestos `(tenant_id, ...)` donde aplique.
- Comandos artisan de dominio prefijados `territori:`.
- **Spec primero**: ninguna feature se implementa sin su `_ai/specs/{feature}.spec.md`. Tests primero (TDD).
- Dependencias con versión exacta (sin `^`/`~`); `npm ci`/`composer install` (no `install`/`update`); no tocar `.npmrc` (seguridad de cadena de suministro, ver `_ai/SETUP.md` §8).
