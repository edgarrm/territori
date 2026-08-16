<?php

namespace Tests\Unit;

use App\Support\Pii;
use Tests\TestCase;

class PiiTest extends TestCase
{
    public function test_enmascarar_nombre_deja_visible_el_primer_apellido(): void
    {
        $this->assertSame('J. C. Pérez G.', Pii::enmascararNombre('Juan Carlos Pérez García'));
        $this->assertSame('J. Pérez G.', Pii::enmascararNombre('Juan Pérez García'));
        $this->assertSame('J. Pérez', Pii::enmascararNombre('Juan Pérez'));
    }

    public function test_enmascarar_nombre_de_una_sola_palabra_no_revela_nada(): void
    {
        $this->assertSame('J.', Pii::enmascararNombre('Juan'));
    }

    public function test_enmascarar_nombre_normaliza_espacios_y_respeta_vacios(): void
    {
        $this->assertSame('A. López', Pii::enmascararNombre('  Ana   López  '));
        $this->assertSame('', Pii::enmascararNombre(''));
        $this->assertNull(Pii::enmascararNombre(null));
    }

    public function test_enmascarar_texto_oculta_por_completo(): void
    {
        $this->assertSame('•••', Pii::enmascararTexto('Vive con su madre, diabético'));
        $this->assertSame('', Pii::enmascararTexto(''));
        $this->assertNull(Pii::enmascararTexto(null));
    }
}
