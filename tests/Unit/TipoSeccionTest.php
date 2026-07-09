<?php

namespace Tests\Unit;

use App\Enums\TipoSeccion;
use PHPUnit\Framework\TestCase;

class TipoSeccionTest extends TestCase
{
    public function test_label_y_color_de_cada_caso(): void
    {
        $this->assertSame('Alfa', TipoSeccion::Alfa->label());
        $this->assertSame('Beta', TipoSeccion::Beta->label());
        $this->assertSame('Gama', TipoSeccion::Gama->label());

        $this->assertNotSame('', TipoSeccion::Alfa->color());
        $this->assertNotSame('', TipoSeccion::Beta->color());
        $this->assertNotSame('', TipoSeccion::Gama->color());
    }

    public function test_desde_lista_nominal_clasifica_por_umbrales_default(): void
    {
        $this->assertSame(TipoSeccion::Alfa, TipoSeccion::desdeListaNominal(1223, 1000, 500));
        $this->assertSame(TipoSeccion::Beta, TipoSeccion::desdeListaNominal(514, 1000, 500));
        $this->assertSame(TipoSeccion::Gama, TipoSeccion::desdeListaNominal(222, 1000, 500));
        $this->assertNull(TipoSeccion::desdeListaNominal(null, 1000, 500));
        $this->assertNull(TipoSeccion::desdeListaNominal(0, 1000, 500));
    }

    public function test_desde_lista_nominal_respeta_umbral_del_tenant(): void
    {
        $this->assertSame(TipoSeccion::Beta, TipoSeccion::desdeListaNominal(750, 800, 500));
    }
}
