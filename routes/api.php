<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

// Auth endpoints (rate-limited separately)
Route::middleware('throttle:api-auth')->prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Public read-only endpoints
Route::middleware('throttle:api')->group(function () {
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/services/{service}', [ServiceController::class, 'show'])->whereUuid('service');

    Route::get('/requests', [ServiceRequestController::class, 'index']);
    Route::get('/requests/{serviceRequest}', [ServiceRequestController::class, 'show'])->whereUuid('serviceRequest');

    Route::get('/users/{user}', [ProfileController::class, 'show'])->whereUuid('user');
});

// Authenticated endpoints
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'me']);
    Route::patch('/profile', [ProfileController::class, 'update']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->whereUuid('transaction');
    Route::post('/transactions/{transaction}/approve', [TransactionController::class, 'approve'])->whereUuid('transaction');
    Route::post('/transactions/{transaction}/refuse', [TransactionController::class, 'refuse'])->whereUuid('transaction');
    Route::post('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->whereUuid('transaction');
    Route::post('/transactions/{transaction}/complete', [TransactionController::class, 'complete'])->whereUuid('transaction');
    Route::post('/transactions/{transaction}/confirm', [TransactionController::class, 'confirm'])->whereUuid('transaction');
});
