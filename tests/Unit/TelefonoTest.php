<?php

namespace Tests\Unit;

use App\Support\Telefono;
use RuntimeException;
use Tests\TestCase;

class TelefonoTest extends TestCase
{
    public function test_normaliza_a_los_ultimos_diez_digitos(): void
    {
        $this->assertSame('5512345678', Telefono::normalizar('+52 (55) 1234-5678'));
        $this->assertSame('5512345678', Telefono::normalizar('5512345678'));
    }

    public function test_normalizar_devuelve_null_si_hay_menos_de_diez_digitos(): void
    {
        $this->assertNull(Telefono::normalizar('1234'));
        $this->assertNull(Telefono::normalizar(''));
    }

    public function test_hash_es_determinista_para_el_mismo_numero_en_distinto_formato(): void
    {
        $a = Telefono::hash('+52 55 1234 5678', 1);
        $b = Telefono::hash('(55) 1234-5678', 1);

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function test_hash_difiere_entre_numeros_distintos(): void
    {
        $this->assertNotSame(Telefono::hash('5512345678', 1), Telefono::hash('5599999999', 1));
    }

    /**
     * P11: con solo APP_KEY, el mismo teléfono producía el mismo hash en dos
     * campañas rivales del mismo host, así que comparar las columnas
     * `telefono_hash` de dos tenants revelaba qué contactos comparten sin
     * descifrar un solo dato.
     */
    public function test_el_mismo_numero_da_hashes_distintos_en_campanas_distintas(): void
    {
        $this->assertNotSame(
            Telefono::hash('5512345678', 1),
            Telefono::hash('5512345678', 2),
        );
    }

    public function test_hash_de_numero_invalido_es_null(): void
    {
        $this->assertNull(Telefono::hash('123', 1));
    }

    /**
     * Fail-closed, igual que TenantScope: sin campaña no se puede calcular un
     * hash correcto y devolver uno sin salt rompería el dedup en silencio.
     */
    public function test_hash_sin_campana_activa_falla(): void
    {
        $this->expectException(RuntimeException::class);

        Telefono::hash('5512345678');
    }
}
