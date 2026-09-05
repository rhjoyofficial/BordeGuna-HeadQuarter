<?php

use App\Http\Controllers\Health\HealthController;
use Illuminate\Support\Facades\Route;

// Dependency-aware health for monitoring. Unauthenticated by design; exposes statuses only.
Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('api.health');
