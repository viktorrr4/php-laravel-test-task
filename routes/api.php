<?php

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Webhooks\MarketplaceWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MarketHub webhook ingress
|--------------------------------------------------------------------------
| Signature is verified in middleware against the raw request body before the
| controller runs. The controller stays thin: persist the event and queue it.
*/
Route::post('/webhooks/marketplace', MarketplaceWebhookController::class)
    ->middleware('verify.webhook')
    ->name('webhooks.marketplace');

/*
|--------------------------------------------------------------------------
| Admin orders API (contract: task/openapi.yaml)
|--------------------------------------------------------------------------
*/
Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
