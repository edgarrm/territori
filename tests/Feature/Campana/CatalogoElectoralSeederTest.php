<?php

namespace Tests\Feature\Campana;

use App\Models\Coalicion;
use App\Models\Partido;
use Database\Seeders\CoalicionSeeder;
use Database\Seeders\PartidoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogoElectoralSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_crea_los_9_partidos_2024(): void
    {
        $this->seed(PartidoSeeder::class);

        $this->assertSame(9, Partido::count());
        $this->assertEqualsCanonicalizing(
            ['PAN', 'PRI', 'PRD', 'PT', 'PVEM', 'MC', 'PAS', 'MORENA', 'PES'],
            Partido::pluck('siglas')->all()
        );
    }

    public function test_seeder_crea_las_2_coaliciones_2024(): void
    {
        $this->seed(CoalicionSeeder::class);

        $this->assertSame(2, Coalicion::count());

        $fuerza = Coalicion::where('nombre', 'Fuerza y Corazón por México')->first();
        $this->assertNotNull($fuerza);
        $this->assertEqualsCanonicalizing(['PAN', 'PRI', 'PRD', 'PAS'], $fuerza->partidos);

        $historia = Coalicion::where('nombre', 'Sigamos Haciendo Historia')->first();
        $this->assertNotNull($historia);
        $this->assertEqualsCanonicalizing(['MORENA', 'PVEM'], $historia->partidos);
    }

    public function test_seeder_es_idempotente(): void
    {
        $this->seed(PartidoSeeder::class);
        $this->seed(PartidoSeeder::class);
        $this->seed(CoalicionSeeder::class);
        $this->seed(CoalicionSeeder::class);

        $this->assertSame(9, Partido::count());
        $this->assertSame(2, Coalicion::count());
    }
}
