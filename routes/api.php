<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HoldController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/holds', [HoldController::class, 'store']);
});
