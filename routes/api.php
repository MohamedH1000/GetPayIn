<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware(['api'])->group(function () {
    // Product routes
    Route::get('/products/{product}', [ProductController::class, 'show']);

    // Hold routes
    Route::post('/holds', [HoldController::class, 'store']);

    // Order routes
    Route::post('/orders', [OrderController::class, 'store']);

    // Payment webhook
    Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle']);
});