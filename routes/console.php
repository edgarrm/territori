<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Retención de datos personales (LFPDPPP art. 11)
|--------------------------------------------------------------------------
|
| La supresión de datos que ya no son necesarios no puede depender de que
| alguien se acuerde de correr el comando. De madrugada y sin solaparse: la
| purga recorre todos los tenants y hace borrados físicos.
|
| Requiere el scheduler corriendo en producción (`php artisan schedule:work`
| o la entrada de cron equivalente); ver docs/operaciones.md.
|
*/
Schedule::command('territori:purgar-datos')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();
