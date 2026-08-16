<?php

namespace Tests\Feature\Privacidad;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P14: rotar `APP_KEY` sin migrar deja la PII ilegible y **rompe el dedup en
 * silencio** (los hashes nuevos no coinciden con los guardados, así que cada
 * contacto ya registrado se puede recapturar como nuevo).
 * `territori:recifrar-pii` es el procedimiento; el runbook está en
 * docs/operaciones.md.
 */
class RotacionAppKeyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Membership, 2: Seccion, 3: AvisoPrivacidad}
     */
    private function setupCampana(): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador']);
        TenantContext::set($tenant);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $membership, $seccion, $aviso];
    }

    /**
     * Cambia APP_KEY en caliente, como haría `key:generate` entre dos
     * ejecuciones de la app.
     */
    private function rotarClave(): string
    {
        $anterior = (string) Config::get('app.key');
        $nueva = 'base64:'.base64_encode(Encrypter::generateKey((string) Config::get('app.cipher')));

        Config::set('app.key', $this->bytes($nueva));
        $this->app->forgetInstance('encrypter');

        return $anterior;
    }

    private function bytes(string $clave): string
    {
        return str_starts_with($clave, 'base64:')
            ? (string) base64_decode(substr($clave, 7), true)
            : $clave;
    }

    public function test_recifra_la_pii_y_recalcula_los_hashes_con_la_clave_nueva(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana();
        $elector = Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'telefono' => '5511112222',
            'telefono_hash' => Telefono::hash('5511112222'),
            'domicilio' => 'Calle Falsa 123', 'observaciones' => 'Nota privada',
        ]);

        $anterior = $this->rotarClave();

        $this->artisan("territori:recifrar-pii --clave-anterior={$anterior}")->assertSuccessful();

        TenantContext::set($tenant);
        $fresco = Elector::query()->find($elector->id);
        $this->assertSame('5511112222', $fresco->telefono);
        $this->assertSame('Calle Falsa 123', $fresco->domicilio);
        $this->assertSame('Nota privada', $fresco->observaciones);
        // El hash queda consistente con la clave nueva: el dedup sigue vivo.
        $this->assertSame(Telefono::hash('5511112222'), $fresco->telefono_hash);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana();
        $elector = Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'telefono' => '5511112222',
            'telefono_hash' => Telefono::hash('5511112222'),
        ]);

        $crudoAntes = DB::table('electores')->where('id', $elector->id)->first();
        $anterior = $this->rotarClave();

        $this->artisan("territori:recifrar-pii --clave-anterior={$anterior} --dry-run")->assertSuccessful();

        $crudoDespues = DB::table('electores')->where('id', $elector->id)->first();
        $this->assertSame($crudoAntes->telefono, $crudoDespues->telefono);
        $this->assertSame($crudoAntes->telefono_hash, $crudoDespues->telefono_hash);
    }

    public function test_sin_clave_anterior_solo_recalcula_los_hashes(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana();
        $elector = Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'telefono' => '5511112222',
            'telefono_hash' => 'hash-viejo-que-ya-no-corresponde',
        ]);

        $this->artisan('territori:recifrar-pii')->assertSuccessful();

        TenantContext::set($tenant);
        $fresco = Elector::query()->find($elector->id);
        $this->assertSame(Telefono::hash('5511112222'), $fresco->telefono_hash);
        $this->assertSame('5511112222', $fresco->telefono);
    }

    public function test_una_clave_anterior_invalida_falla_sin_tocar_datos(): void
    {
        $this->setupCampana();

        $this->artisan('territori:recifrar-pii --clave-anterior=base64:no-es-base64-real!!')
            ->assertFailed();
    }
}
