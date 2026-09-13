<?php

use App\Http\Controllers\Health\HealthController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Http\Controllers\Webhooks\ShopWebhookController;
use Illuminate\Support\Facades\Route;

// Dependency-aware health for monitoring. Unauthenticated by design; exposes statuses only.
Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('api.health');

// Shop webhook inbox (docs/35 G): signature-verified, deduplicated, 202 then processed asynchronously.
Route::post('/webhooks/shop/{store}', ShopWebhookController::class)->middleware('throttle:300,1')->name('webhooks.shop');

// Meta (Messenger) webhook inbox (docs/72 B): 404 while `channels.meta.inbound` is off, which it is.
Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify'])->middleware('throttle:60,1')->name('webhooks.meta.verify');
Route::post('/webhooks/meta', [MetaWebhookController::class, 'receive'])->middleware('throttle:600,1')->name('webhooks.meta');
