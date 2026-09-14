<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CampaignCommandController;
use App\Http\Controllers\CollaboratorAccountController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::view('/register', 'register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::view('/chat', 'chat');
    Route::get('/chat/conversations', [ChatController::class, 'index']);
    Route::get('/chat/conversations/{id}', [ChatController::class, 'show']);
    Route::post('/chat', [ChatController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/collaborator-account', [CollaboratorAccountController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/ai-commands/{command}/confirm', [CampaignCommandController::class, 'confirm'])->middleware('throttle:20,1');
    Route::post('/ai-commands/{command}/cancel', [CampaignCommandController::class, 'cancel'])->middleware('throttle:20,1');
    Route::post('/ai-commands/{command}/send', [CampaignCommandController::class, 'send'])->middleware('throttle:20,1');
    Route::post('/logout', [AuthController::class, 'logout']);
});
