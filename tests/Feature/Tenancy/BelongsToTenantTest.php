<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;

    private ?int $municipioId = null;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_items', function ($table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('nombre');
            $table->timestamps();
        });
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'nombre' => 'Campaña '.uniqid(),
            'municipio_id' => $this->municipioId(),
        ]);
    }

    private function municipioId(): int
    {
        if ($this->municipioId !== null) {
            return $this->municipioId;
        }

        $entidadId = DB::table('entidades')->insertGetId(['clave' => 99, 'nombre' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

        return $this->municipioId = DB::table('municipios')->insertGetId(['entidad_id' => $entidadId, 'clave' => 1, 'nombre' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_query_solo_devuelve_registros_del_tenant_activo(): void
    {
        $tenantA = $this->tenant();
        $tenantB = $this->tenant();

        TenantContext::set($tenantA);
        TestItem::create(['nombre' => 'de A']);

        TenantContext::set($tenantB);
        TestItem::create(['nombre' => 'de B']);

        TenantContext::set($tenantA);
        $this->assertSame(['de A'], TestItem::pluck('nombre')->all());

        TenantContext::set($tenantB);
        $this->assertSame(['de B'], TestItem::pluck('nombre')->all());
    }

    public function test_sin_tenant_activo_no_devuelve_nada(): void
    {
        $tenantA = $this->tenant();
        TenantContext::set($tenantA);
        TestItem::create(['nombre' => 'de A']);

        TenantContext::clear();

        $this->assertSame(0, TestItem::count());
    }

    public function test_tenant_id_inyectado_via_mass_assignment_es_ignorado(): void
    {
        $tenantA = $this->tenant();
        $tenantB = $this->tenant();

        TenantContext::set($tenantA);

        // Un request malicioso intenta forzar el tenant_id de otra campaña.
        $item = TestItem::create(['nombre' => 'de A', 'tenant_id' => $tenantB->id]);

        $this->assertSame($tenantA->id, $item->tenant_id);
    }

    public function test_creating_asigna_tenant_id_automaticamente(): void
    {
        $tenantA = $this->tenant();
        TenantContext::set($tenantA);

        $item = TestItem::create(['nombre' => 'auto']);

        $this->assertSame($tenantA->id, $item->tenant_id);
    }
}

class TestItem extends Model
{
    use BelongsToTenant;

    protected $table = 'test_items';

    protected $fillable = ['nombre'];
}
