<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MessageController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| These routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. They are prefixed with "/api".
|--------------------------------------------------------------------------
*/

Route::prefix('v1')
    ->middleware(['auth:api'])
    ->group(function () {
        Route::post('message', [MessageController::class, '__invoke'])
            ->name('api.message');
    });

