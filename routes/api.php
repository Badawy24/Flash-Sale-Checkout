<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// Public routes
Route::get('/products/{id}', [ProductController::class, 'show']);

// Webhook route (no auth required - payment provider calls this)
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handlePaymentWebhook']);

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/holds', [HoldController::class, 'store']);
    Route::post('/orders', [CheckoutController::class, 'checkout']);
});
