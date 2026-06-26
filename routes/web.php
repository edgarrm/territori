<?php

use App\Http\Controllers\CampanaSelectorController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('campanas/seleccionar', [CampanaSelectorController::class, 'index'])->name('campanas.seleccionar');
    Route::post('campanas/seleccionar', [CampanaSelectorController::class, 'store'])->name('campanas.seleccionar.store');
    Route::get('campanas/sin-membership', [CampanaSelectorController::class, 'sinMembership'])->name('campanas.sin-membership');

    Route::middleware('rol:admin')->group(function () {
        Route::get('campanas/crear', fn () => '')->name('campanas.crear');
    });
});

require __DIR__.'/settings.php';
