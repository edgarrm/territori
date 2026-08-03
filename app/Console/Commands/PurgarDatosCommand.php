<?php

namespace App\Console\Commands;

use App\Actions\Privacidad\BorrarPiiDelElector;
use App\Actions\Privacidad\RegistrarAcceso;
use App\Models\Elector;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Retención de datos personales (LFPDPPP art. 11: los datos se suprimen cuando
 * dejan de ser necesarios para la finalidad que los justificó). Tres tareas:
 *
 * 1. **Cierre de campaña**: al pasar un tenant a `estado = 'cerrada'` se agota
 *    la finalidad del tratamiento; pasado el plazo de gracia sus electores
 *    pierden la PII y quedan de baja lógica.
 * 2. **Borrado físico de cancelados**: las filas ya scrubbeadas (por Cancelación
 *    ARCO o por el punto anterior) se conservan un tiempo para poder acreditar
 *    que la baja se ejecutó; pasado el plazo ya no aportan nada.
 * 3. **Sesiones caducadas**: `sessions` guarda el payload de sesión de usuarios
 *    con acceso al padrón y nadie la limpiaba.
 *
 * Se recorre tenant por tenant fijando el `TenantContext`: `TenantScope` es
 * fail-closed, así que sin contexto activo las queries no verían nada, y de
 * paso cada traza en la bitácora queda atribuida a su campaña.
 *
 * No se usa `Prunable` / `model:prune` a propósito: `prunable()` correría sin
 * `TenantContext` y el scope fail-closed lo volvería un no-op silencioso.
 * Esquivarlo exigiría el único `withoutGlobalScope` del repo.
 */
#[Signature('territori:purgar-datos {--dias= : Días de antigüedad para el borrado físico de cancelados (default: config app.retencion)} {--dry-run : Solo informa, no borra}')]
#[Description('Aplica la política de retención de datos personales: cierra campañas vencidas, borra cancelados antiguos y limpia sesiones caducadas.')]
class PurgarDatosCommand extends Command
{
    /**
     * Estado del tenant que dispara la retención por cierre de campaña.
     */
    private const ESTADO_CERRADA = 'cerrada';

    public function handle(BorrarPiiDelElector $borrarPii, RegistrarAcceso $bitacora): int
    {
        $simular = (bool) $this->option('dry-run');

        $diasCancelados = $this->option('dias') !== null
            ? (int) $this->option('dias')
            : (int) Config::get('app.retencion.electores_cancelados_dias');

        $diasCerrada = (int) Config::get('app.retencion.campana_cerrada_dias');

        if ($diasCancelados < 1 || $diasCerrada < 1) {
            $this->components->error('Los plazos de retención deben ser de al menos 1 día.');

            return self::FAILURE;
        }

        $bloqueados = 0;
        $borrados = 0;

        foreach (Tenant::query()->orderBy('id')->cursor() as $tenant) {
            TenantContext::set($tenant);

            $bloqueados += $this->bloquearCampanaCerrada($tenant, $diasCerrada, $simular, $borrarPii, $bitacora);
            $borrados += $this->borrarCanceladosAntiguos($diasCancelados, $simular, $bitacora);
        }

        TenantContext::clear();

        $sesiones = $this->purgarSesiones($simular);

        $this->components->twoColumnDetail('Electores dados de baja por cierre de campaña', (string) $bloqueados);
        $this->components->twoColumnDetail('Electores cancelados borrados físicamente', (string) $borrados);
        $this->components->twoColumnDetail('Sesiones caducadas eliminadas', (string) $sesiones);

        if ($simular) {
            $this->components->warn('Modo --dry-run: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    /**
     * Campaña cerrada hace más de `$dias`: se agotó la finalidad, así que sus
     * electores vivos pierden la PII y quedan de baja lógica. A partir de ahí
     * entran al plazo del borrado físico como cualquier cancelado.
     */
    private function bloquearCampanaCerrada(
        Tenant $tenant,
        int $dias,
        bool $simular,
        BorrarPiiDelElector $borrarPii,
        RegistrarAcceso $bitacora,
    ): int {
        if ($tenant->estado !== self::ESTADO_CERRADA) {
            return 0;
        }

        // `cerrada_en`, no `updated_at`: cualquier escritura sobre el tenant
        // (guardar la configuración del análisis electoral, por ejemplo) movía
        // `updated_at` y aplazaba el borrado de la PII sin que nadie lo notara.
        if ($tenant->cerrada_en === null || $tenant->cerrada_en->gt(now()->subDays($dias))) {
            return 0;
        }

        $total = Elector::query()->count();

        if ($total === 0 || $simular) {
            return $total;
        }

        DB::transaction(function () use ($borrarPii): void {
            foreach (Elector::query()->cursor() as $elector) {
                $borrarPii->handle($elector);
                $elector->delete();
            }
        });

        $bitacora->handle('retencion.campana_cerrada', 'tenant', $tenant->id, [
            'electores' => $total,
            'dias' => $dias,
        ]);

        return $total;
    }

    /**
     * Borrado físico de las filas ya scrubbeadas. El filtro por `telefono_hash`
     * nulo y `nombre` vacío es la firma del scrub: nunca borra un elector que
     * conserve datos personales, aunque esté soft-deleted por otra vía.
     */
    private function borrarCanceladosAntiguos(int $dias, bool $simular, RegistrarAcceso $bitacora): int
    {
        $query = Elector::onlyTrashed()
            ->whereNull('telefono_hash')
            ->where('nombre', '')
            ->where('deleted_at', '<', now()->subDays($dias));

        $total = $query->count();

        if ($total === 0 || $simular) {
            return $total;
        }

        $query->forceDelete();

        $bitacora->handle('retencion.purga', 'elector', null, [
            'filas' => $total,
            'dias' => $dias,
        ]);

        return $total;
    }

    /**
     * Sesiones cuyo `last_activity` excede el lifetime configurado. Solo aplica
     * al driver `database`; los demás almacenes caducan solos.
     */
    private function purgarSesiones(bool $simular): int
    {
        if (Config::get('session.driver') !== 'database') {
            return 0;
        }

        $tabla = (string) Config::get('session.table', 'sessions');
        $limite = now()->subMinutes((int) Config::get('session.lifetime', 120))->getTimestamp();

        $query = DB::table($tabla)->where('last_activity', '<', $limite);

        return $simular ? $query->count() : $query->delete();
    }
}
