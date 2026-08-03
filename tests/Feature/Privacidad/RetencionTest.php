<?php

namespace Tests\Feature\Privacidad;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P8: no existía política de retención de ningún tipo. `territori:purgar-datos`
 * es la que aplica el art. 11 de la LFPDPPP (suprimir cuando la finalidad se
 * agota) en sus tres frentes: cierre de campaña, borrado físico de cancelados y
 * sesiones caducadas.
 */
class RetencionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Membership, 2: Seccion, 3: AvisoPrivacidad}
     */
    private function setupCampana(string $estado = 'activa'): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id, 'estado' => $estado]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador']);
        TenantContext::set($tenant);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $membership, $seccion, $aviso];
    }

    private function elector(Tenant $t, Membership $m, Seccion $s, AvisoPrivacidad $a, string $tel): Elector
    {
        return Elector::factory()->create([
            'tenant_id' => $t->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'telefono' => $tel, 'telefono_hash' => Telefono::hash($tel),
        ]);
    }

    // --- Borrado físico de cancelados --------------------------------------

    public function test_borra_fisicamente_los_cancelados_mas_antiguos_que_el_plazo(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana();
        $viejo = $this->elector($tenant, $m, $s, $a, '5511112222');
        $reciente = $this->elector($tenant, $m, $s, $a, '5533334444');

        foreach ([$viejo, $reciente] as $elector) {
            $elector->forceFill(['nombre' => '', 'telefono' => '', 'telefono_hash' => null])->save();
            $elector->delete();
        }
        Elector::withTrashed()->whereKey($viejo->id)->update(['deleted_at' => now()->subDays(400)]);

        $this->artisan('territori:purgar-datos')->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertNull(Elector::withTrashed()->find($viejo->id));
        $this->assertNotNull(Elector::withTrashed()->find($reciente->id));
    }

    public function test_no_borra_un_soft_deleted_que_todavia_conserva_pii(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana();
        // Soft-deleted por otra vía (no por el scrub ARCO): conserva nombre y
        // telefono_hash, así que la purga no debe tocarlo.
        $conPii = $this->elector($tenant, $m, $s, $a, '5511112222');
        $conPii->delete();
        Elector::withTrashed()->whereKey($conPii->id)->update(['deleted_at' => now()->subDays(400)]);

        $this->artisan('territori:purgar-datos')->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertNotNull(Elector::withTrashed()->find($conPii->id));
    }

    public function test_dry_run_no_borra_nada(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana();
        $viejo = $this->elector($tenant, $m, $s, $a, '5511112222');
        $viejo->forceFill(['nombre' => '', 'telefono' => '', 'telefono_hash' => null])->save();
        $viejo->delete();
        Elector::withTrashed()->whereKey($viejo->id)->update(['deleted_at' => now()->subDays(400)]);

        $this->artisan('territori:purgar-datos --dry-run')->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertNotNull(Elector::withTrashed()->find($viejo->id));
    }

    // --- Cierre de campaña --------------------------------------------------

    public function test_campana_cerrada_hace_tiempo_pierde_la_pii_de_sus_electores(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana('cerrada');
        $elector = $this->elector($tenant, $m, $s, $a, '5511112222');
        $elector->forceFill(['email' => 'ana@correo.com', 'domicilio' => 'Calle 1'])->save();
        $interaccion = Interaccion::factory()->create([
            'tenant_id' => $tenant->id, 'elector_id' => $elector->id,
            'membership_id' => $m->id, 'nota' => 'Prefiere que le llamen de noche',
        ]);

        Tenant::query()->whereKey($tenant->id)->update(['updated_at' => now()->subDays(365)]);

        $this->artisan('territori:purgar-datos')->assertSuccessful();

        TenantContext::set($tenant);
        $borrado = Elector::withTrashed()->find($elector->id);
        $this->assertNotNull($borrado->deleted_at);
        $this->assertSame('', $borrado->nombre);
        $this->assertNull($borrado->email);
        $this->assertNull($borrado->domicilio);
        $this->assertNull(Interaccion::query()->find($interaccion->id)->nota);
    }

    public function test_campana_cerrada_dentro_del_plazo_de_gracia_no_se_toca(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana('cerrada');
        $elector = $this->elector($tenant, $m, $s, $a, '5511112222');
        Tenant::query()->whereKey($tenant->id)->update(['updated_at' => now()->subDays(5)]);

        $this->artisan('territori:purgar-datos')->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertNull(Elector::query()->find($elector->id)->deleted_at);
    }

    public function test_campana_activa_no_se_purga_por_antigua_que_sea(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana('activa');
        $elector = $this->elector($tenant, $m, $s, $a, '5511112222');
        Tenant::query()->whereKey($tenant->id)->update(['updated_at' => now()->subDays(2000)]);

        $this->artisan('territori:purgar-datos')->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertNull(Elector::query()->find($elector->id)->deleted_at);
    }

    public function test_el_cierre_de_campana_deja_traza_en_la_bitacora(): void
    {
        [$tenant, $m, $s, $a] = $this->setupCampana('cerrada');
        $this->elector($tenant, $m, $s, $a, '5511112222');
        Tenant::query()->whereKey($tenant->id)->update(['updated_at' => now()->subDays(365)]);

        $this->artisan('territori:purgar-datos')->assertSuccessful();

        // Sin request HTTP no hay actor, pero sí campaña: la traza se escribe.
        $this->assertDatabaseHas('bitacora_accesos', [
            'tenant_id' => $tenant->id,
            'accion' => 'retencion.campana_cerrada',
            'recurso_tipo' => 'tenant',
            'recurso_id' => $tenant->id,
            'membership_id' => null,
        ]);
    }

    // --- Sesiones -----------------------------------------------------------

    public function test_purga_las_sesiones_caducadas_y_conserva_las_vivas(): void
    {
        $this->setupCampana();

        // La suite corre con SESSION_DRIVER=array (phpunit.xml); la limpieza
        // solo aplica al driver database, que es el de producción.
        Config::set('session.driver', 'database');

        DB::table('sessions')->insert([
            ['id' => 'vieja', 'payload' => 'x', 'last_activity' => now()->subDays(30)->getTimestamp()],
            ['id' => 'viva', 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
        ]);

        $this->artisan('territori:purgar-datos')->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => 'vieja']);
        $this->assertDatabaseHas('sessions', ['id' => 'viva']);
    }

    public function test_rechaza_un_plazo_invalido(): void
    {
        $this->artisan('territori:purgar-datos --dias=0')->assertFailed();
    }
}
