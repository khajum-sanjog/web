<?php

use App\Http\Console;
use App\Http\Controllers\MobileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->away("https://demo.voxmg.com");
});

Route::controller(Console::class)->group(function () {
    Route::get('/artisan/key-generate', '_artisanRunKeyGenerate');
    Route::get('/artisan/migrate', '_artisanRunMigration');
    Route::get('/artisan/clear', '_artisanRunCacheClear');;
});

Route::get('/payments', [MobileController::class, 'paymentRenderAction'])
    ->middleware('auth')
    ->name('mobile.payment');
