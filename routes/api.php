<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1'); // 5 attempts per minute

// Public routes
Route::get('/products/{id}', [ProductController::class, 'show'])->middleware('throttle:60,1'); // 60 requests per minute

// Webhook route (no auth required - payment provider calls this)
// Higher rate limit for webhooks as they come from payment providers
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handlePaymentWebhook'])
    ->middleware('throttle:100,1'); // 100 requests per minute

// Authenticated routes with rate limiting
Route::middleware(['auth:sanctum', 'throttle:30,1'])->group(function () {
    Route::post('/holds', [HoldController::class, 'store'])->middleware('throttle:20,1'); // 20 holds per minute per user
    Route::post('/orders', [CheckoutController::class, 'checkout'])->middleware('throttle:10,1'); // 10 orders per minute per user
});
