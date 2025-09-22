<?php

use App\Http\Controllers\OrderFulfillmentController;
use App\Http\Controllers\ProductSyncController;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('process-orders', [OrderFulfillmentController::class, 'processOrders']);
    Route::post('shopify-webhook', [OrderFulfillmentController::class, 'handleWebhook']);
    Route::get('sync-products', [ProductSyncController::class, 'syncProducts']);
    Route::get('sales-report', function () {
        return Order::whereBetween('created_at', [now()->subDays(30), now()])->sum('total_price');
    });
});
