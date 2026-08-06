<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\UserPreferenceController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout',      [AuthController::class, 'logout']);
    Route::get('/me',           [AuthController::class, 'me']);
    Route::get('/dashboard',        [DashboardController::class, 'index']);
    Route::get('/dashboard/export', [DashboardController::class, 'export']);
    Route::get('/preferences',  [UserPreferenceController::class, 'show']);
    Route::put('/preferences',  [UserPreferenceController::class, 'update']);
});
