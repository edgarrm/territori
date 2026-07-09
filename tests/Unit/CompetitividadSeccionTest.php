<?php

namespace Tests\Unit;

use App\Enums\CompetitividadSeccion;
use PHPUnit\Framework\TestCase;

class CompetitividadSeccionTest extends TestCase
{
    public function test_label_de_cada_caso(): void
    {
        $this->assertSame('Ganada franca', CompetitividadSeccion::GanadaFranca->label());
        $this->assertSame('Competida', CompetitividadSeccion::Competida->label());
        $this->assertSame('Empatada', CompetitividadSeccion::Empatada->label());
        $this->assertSame('Perdida', CompetitividadSeccion::Perdida->label());
        $this->assertSame('Sin datos', CompetitividadSeccion::SinDatos->label());
    }

    public function test_color_de_cada_caso(): void
    {
        $this->assertSame('#15803d', CompetitividadSeccion::GanadaFranca->color());
        $this->assertSame('#f59e0b', CompetitividadSeccion::Competida->color());
        $this->assertSame('#6b7280', CompetitividadSeccion::Empatada->color());
        $this->assertSame('#dc2626', CompetitividadSeccion::Perdida->color());
        $this->assertSame('#9ca3af', CompetitividadSeccion::SinDatos->color());
    }
}
