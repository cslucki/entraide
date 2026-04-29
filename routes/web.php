<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\PointController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ExplorerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/explorer', [ExplorerController::class, 'index'])->name('explorer');
Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');
Route::get('/requests/{request}', [RequestController::class, 'show'])->name('requests.show');
Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Services
    Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
    Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
    Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
    Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    // Requests (demandes)
    Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
    Route::post('/requests', [RequestController::class, 'store'])->name('requests.store');
    Route::delete('/requests/{request}', [RequestController::class, 'destroy'])->name('requests.destroy');

    // Transactions
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::patch('/transactions/{transaction}/approve', [TransactionController::class, 'approve'])->name('transactions.approve');
    Route::patch('/transactions/{transaction}/refuse', [TransactionController::class, 'refuse'])->name('transactions.refuse');
    Route::patch('/transactions/{transaction}/adjust', [TransactionController::class, 'adjust'])->name('transactions.adjust');
    Route::patch('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('transactions.cancel');
    Route::patch('/transactions/{transaction}/complete', [TransactionController::class, 'complete'])->name('transactions.complete');
    Route::patch('/transactions/{transaction}/confirm', [TransactionController::class, 'confirm'])->name('transactions.confirm');
    Route::patch('/transactions/{transaction}/contest', [TransactionController::class, 'contest'])->name('transactions.contest');

    // Reviews
    Route::post('/transactions/{transaction}/review', [ReviewController::class, 'store'])->name('reviews.store');

    // Messages
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{transaction}', [MessageController::class, 'show'])->name('messages.show');

    // Points history
    Route::get('/points', [PointController::class, 'index'])->name('points.index');

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('/favorites/{service}/toggle', [FavoriteController::class, 'toggle'])->name('favorites.toggle');

    // Reports
    Route::post('/reports/service/{service}', [ReportController::class, 'storeService'])->name('reports.service');
    Route::post('/reports/user/{user}', [ReportController::class, 'storeUser'])->name('reports.user');

    // Profile
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/availability', [ProfileController::class, 'toggleAvailability'])->name('profile.availability');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin routes
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
    Route::patch('/reports/{report}/dismiss', [AdminController::class, 'dismissReport'])->name('reports.dismiss');
    Route::patch('/reports/{report}/review', [AdminController::class, 'reviewReport'])->name('reports.review');
    Route::patch('/users/{user}/toggle-availability', [AdminController::class, 'toggleUserAvailability'])->name('users.toggle-availability');
});

require __DIR__.'/auth.php';
