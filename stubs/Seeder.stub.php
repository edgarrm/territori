<?php

namespace Illuminate\Database;

use Illuminate\Console\Command;

abstract class Seeder
{
    /**
     * The console command instance.
     *
     * En runtime esta propiedad solo se asigna cuando el seeder corre vía
     * Artisan (ver Seeder::setCommand()); al instanciar un seeder a mano
     * (ej. en tests o `php artisan tinker`) queda sin inicializar, por lo
     * que el tipo real es nullable.
     *
     * @var Command|null
     */
    protected $command;
}
