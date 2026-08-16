<?php

namespace App\Console\Commands;

use App\Models\Elector;
use App\Models\SolicitudArco;
use App\Models\Tenant;
use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Migración de la PII cifrada entre claves, y recálculo de `telefono_hash`.
 *
 * Cubre dos operaciones que hoy rompen la app en silencio si se hacen a mano:
 *
 * - **Rotación de `APP_KEY` (P14)**: los casts `encrypted` y el HMAC de
 *   `telefono_hash` cuelgan de esa clave. Cambiarla sin migrar deja
 *   `telefono`/`email`/`domicilio`/`observaciones`/`ubicacion` ilegibles y
 *   **rompe la deduplicación sin ningún error visible**: los hashes nuevos ya
 *   no coinciden con los guardados, así que cada contacto existente se puede
 *   volver a capturar como si fuera nuevo. Con `--clave-anterior` se descifra
 *   con la clave vieja y se vuelve a cifrar con la de `APP_KEY`.
 * - **Alta del salt por campaña (P11)**: sin `--clave-anterior` solo recalcula
 *   `telefono_hash`, que es lo que hace falta cuando cambia la forma del hash
 *   pero no la clave.
 *
 * Procedimiento completo en `docs/operaciones.md`.
 */
#[Signature('territori:recifrar-pii {--clave-anterior= : APP_KEY previa (base64:...) si se está rotando la clave} {--tenant= : Limitar a una campaña} {--dry-run : Solo informa, no escribe}')]
#[Description('Re-cifra la PII con la APP_KEY actual y recalcula telefono_hash (rotación de clave / cambio de salt).')]
class RecifrarPiiCommand extends Command
{
    /**
     * Columnas con cast `encrypted` que hay que migrar, por modelo. Agregar una
     * columna cifrada al esquema obliga a sumarla aquí: si no, la rotación de
     * clave la deja ilegible.
     *
     * @var array<class-string<Elector|SolicitudArco>, list<string>>
     */
    private const COLUMNAS_CIFRADAS = [
        Elector::class => ['telefono', 'email', 'domicilio', 'observaciones', 'ubicacion'],
        SolicitudArco::class => ['titular_contacto'],
    ];

    public function handle(): int
    {
        try {
            $anterior = $this->resolverEncrypterAnterior();
        } catch (Throwable $e) {
            $this->components->error('No se pudo usar --clave-anterior: '.$e->getMessage());

            return self::FAILURE;
        }

        $simular = (bool) $this->option('dry-run');
        $tenants = Tenant::query()->orderBy('id');

        if ($this->option('tenant') !== null) {
            $tenants->whereKey((int) $this->option('tenant'));
        }

        $recifrados = 0;
        $rehasheados = 0;

        foreach ($tenants->cursor() as $tenant) {
            TenantContext::set($tenant);

            [$c, $h] = $this->procesarCampana($tenant, $anterior, $simular);
            $recifrados += $c;
            $rehasheados += $h;
        }

        TenantContext::clear();

        $this->components->twoColumnDetail('Valores re-cifrados', (string) $recifrados);
        $this->components->twoColumnDetail('telefono_hash recalculados', (string) $rehasheados);

        if ($simular) {
            $this->components->warn('Modo --dry-run: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} valores re-cifrados, hashes recalculados
     */
    private function procesarCampana(Tenant $tenant, ?Encrypter $anterior, bool $simular): array
    {
        $recifrados = 0;
        $rehasheados = 0;

        foreach (self::COLUMNAS_CIFRADAS as $modelo => $columnas) {
            // Se lee y escribe por el query builder, sin casts: el modelo
            // descifraría con la clave actual, que es justo la que no sirve
            // cuando se está rotando.
            $tabla = (new $modelo)->getTable();

            foreach (DB::table($tabla)->where('tenant_id', $tenant->id)->orderBy('id')->cursor() as $fila) {
                $cambios = [];

                if ($anterior !== null) {
                    foreach ($columnas as $columna) {
                        $valor = $fila->{$columna} ?? null;

                        if ($valor === null || $valor === '') {
                            continue;
                        }

                        $cambios[$columna] = Crypt::encryptString($anterior->decryptString((string) $valor));
                        $recifrados++;
                    }
                }

                if ($modelo === Elector::class && $fila->telefono_hash !== null) {
                    $plano = $anterior !== null
                        ? $anterior->decryptString((string) $fila->telefono)
                        : Crypt::decryptString((string) $fila->telefono);

                    $cambios['telefono_hash'] = Telefono::hash($plano, $tenant->id);
                    $rehasheados++;
                }

                if ($cambios !== [] && ! $simular) {
                    DB::table($tabla)->where('id', $fila->id)->update($cambios);
                }
            }
        }

        return [$recifrados, $rehasheados];
    }

    /**
     * Encrypter con la clave anterior, o null si no se está rotando.
     */
    private function resolverEncrypterAnterior(): ?Encrypter
    {
        $clave = $this->option('clave-anterior');

        if ($clave === null) {
            return null;
        }

        $bytes = str_starts_with((string) $clave, 'base64:')
            ? base64_decode(substr((string) $clave, 7), true)
            : (string) $clave;

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('la clave no es base64 válido.');
        }

        return new Encrypter($bytes, (string) Config::get('app.cipher'));
    }
}
