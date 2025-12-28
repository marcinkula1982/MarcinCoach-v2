<?php

use App\Http\Controllers\Api\WorkoutsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);
Route::get('/me', [MeController::class, 'index']);

Route::post('/workouts/import', [WorkoutsController::class, 'import']);
Route::get('/workouts/{id}', [WorkoutsController::class, 'show']);
Route::get('/workouts/{id}/signals', [WorkoutsController::class, 'signals']);
Route::get('/workouts/{id}/compliance', [WorkoutsController::class, 'compliance']);
Route::get('/workouts/{id}/compliance-v2', [WorkoutsController::class, 'complianceV2']);

