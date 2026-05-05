<?php

use App\Http\Controllers\Admin\AdminCommunityController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminMessageController;
use App\Http\Controllers\Admin\AdminEmailController;
use App\Http\Controllers\Admin\AdminMetaCommunityController;
use App\Http\Controllers\Admin\AdminSettingController;
use App\Http\Controllers\CommunityLandingController;
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
use App\Http\Controllers\CommunityRequestController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// Auth routes (loaded early so they take priority over {community} prefix)
require __DIR__.'/auth.php';

// Public routes
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/explorer', [ExplorerController::class, 'index'])->name('explorer');
Route::get('/membres', [HomeController::class, 'members'])->name('members.index');
Route::get('/echanges', [HomeController::class, 'exchanges'])->name('exchanges.index');
Route::get('/boucles', [HomeController::class, 'boucles'])->name('boucles.index');
Route::get('/boucles/creer', [CommunityRequestController::class, 'create'])->name('boucles.request.create');
Route::post('/boucles/creer', [CommunityRequestController::class, 'store'])->name('boucles.request.store');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::view('/mentions-legales', 'mentions-legales')->name('mentions-legales');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Services
    Route::middleware('profile.complete')->group(function () {
        Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
        Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
    });
    Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
    Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    // Requests (demandes)
    Route::middleware('profile.complete')->group(function () {
        Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
        Route::post('/requests', [RequestController::class, 'store'])->name('requests.store');
    });
    Route::delete('/requests/{request}', [RequestController::class, 'destroy'])->name('requests.destroy');

    // Transactions
    Route::get('/transactions/export', [TransactionController::class, 'exportCsv'])->name('transactions.export');
    Route::post('/transactions', [TransactionController::class, 'store'])->middleware('throttle:10,1')->name('transactions.store');
    Route::patch('/transactions/{transaction}/approve', [TransactionController::class, 'approve'])->name('transactions.approve');
    Route::patch('/transactions/{transaction}/refuse', [TransactionController::class, 'refuse'])->name('transactions.refuse');
    Route::patch('/transactions/{transaction}/adjust', [TransactionController::class, 'adjust'])->name('transactions.adjust');
    Route::patch('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('transactions.cancel');
    Route::patch('/transactions/{transaction}/complete', [TransactionController::class, 'complete'])->name('transactions.complete');
    Route::patch('/transactions/{transaction}/confirm', [TransactionController::class, 'confirm'])->name('transactions.confirm');
    Route::patch('/transactions/{transaction}/contest', [TransactionController::class, 'contest'])->name('transactions.contest');

    // Reviews
    Route::post('/transactions/{transaction}/review', [ReviewController::class, 'store'])->middleware('throttle:5,1')->name('reviews.store');

    // Messages
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{transaction}', [MessageController::class, 'show'])->name('messages.show');

    // Points history
    Route::get('/points', [PointController::class, 'index'])->name('points.index');

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('/favorites/{service}/toggle', [FavoriteController::class, 'toggle'])->middleware('throttle:30,1')->name('favorites.toggle');

    // Reports
    Route::post('/reports/service/{service}', [ReportController::class, 'storeService'])->middleware('throttle:5,1')->name('reports.service');
    Route::post('/reports/request/{serviceRequest}', [ReportController::class, 'storeRequest'])->middleware('throttle:5,1')->name('reports.request');
    Route::post('/reports/user/{user}', [ReportController::class, 'storeUser'])->middleware('throttle:5,1')->name('reports.user');

    // Profile
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/availability', [ProfileController::class, 'toggleAvailability'])->name('profile.availability');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show')->whereUuid('service');
Route::get('/requests/{request}', [RequestController::class, 'show'])->name('requests.show');
Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');

// Admin routes
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');

    // Users
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::get('/users/create', [AdminController::class, 'createUser'])->name('users.create');
    Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
    Route::patch('/users/{user}/toggle-availability', [AdminController::class, 'toggleUserAvailability'])->name('users.toggle-availability');
    Route::patch('/users/{user}/toggle-admin', [AdminController::class, 'toggleUserAdmin'])->name('users.toggle-admin');
    Route::patch('/users/{user}/ban', [AdminController::class, 'banUser'])->name('users.ban');
    Route::patch('/users/{user}/unban', [AdminController::class, 'unbanUser'])->name('users.unban');
    Route::post('/users/{user}/adjust-points', [AdminController::class, 'adjustPoints'])->name('users.adjust-points');
    Route::post('/users/{user}/password', [AdminController::class, 'changePassword'])->name('users.password');
    Route::patch('/users/{user}/assign-community', [AdminController::class, 'assignCommunity'])->name('users.assign-community');

    // Services
    Route::get('/services', [AdminController::class, 'services'])->name('services');
    Route::get('/services/{service}/edit', [AdminController::class, 'editService'])->name('services.edit');
    Route::put('/services/{service}', [AdminController::class, 'updateService'])->name('services.update');
    Route::delete('/services/{id}/force', [AdminController::class, 'forceDeleteService'])->name('services.force-delete');
    Route::patch('/services/{id}/restore', [AdminController::class, 'restoreService'])->name('services.restore');

    // Transactions
    Route::get('/transactions', [AdminController::class, 'transactions'])->name('transactions');

    // Requests
    Route::get('/requests', [AdminController::class, 'requests'])->name('requests');
    Route::get('/requests/{serviceRequest}/edit', [AdminController::class, 'editRequest'])->name('requests.edit');
    Route::put('/requests/{serviceRequest}', [AdminController::class, 'updateRequest'])->name('requests.update');
    Route::patch('/requests/{serviceRequest}/close', [AdminController::class, 'closeRequest'])->name('requests.close');

    // Categories & Skills
    Route::get('/categories', [AdminController::class, 'categories'])->name('categories');
    Route::post('/categories', [AdminController::class, 'storeCategory'])->name('categories.store');
    Route::patch('/categories/{category}', [AdminController::class, 'updateCategory'])->name('categories.update');
    Route::delete('/categories/{category}', [AdminController::class, 'destroyCategory'])->name('categories.destroy');
    Route::post('/categories/{category}/skills', [AdminController::class, 'storeSkill'])->name('categories.skills.store');
    Route::delete('/skills/{skill}', [AdminController::class, 'destroySkill'])->name('skills.destroy');

    // Communities
    Route::get('/communities', [AdminCommunityController::class, 'index'])->name('communities');
    Route::get('/communities/create', [AdminCommunityController::class, 'create'])->name('communities.create');
    Route::post('/communities', [AdminCommunityController::class, 'store'])->name('communities.store');
    Route::get('/communities/{community}/edit', [AdminCommunityController::class, 'edit'])->name('communities.edit');
    Route::put('/communities/{community}', [AdminCommunityController::class, 'update'])->name('communities.update');
    Route::post('/communities/{community}/toggle-active', [AdminCommunityController::class, 'toggleActive'])->name('communities.toggle-active');
    Route::delete('/communities/{community}', [AdminCommunityController::class, 'destroy'])->name('communities.destroy');

    // Messages moderation
    Route::get('/messages', [AdminMessageController::class, 'index'])->name('messages');
    Route::get('/messages/{message}', [AdminMessageController::class, 'show'])->name('messages.show');
    Route::delete('/messages/{message}', [AdminMessageController::class, 'destroy'])->name('messages.destroy');

    // Reports
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
    Route::patch('/reports/{report}/dismiss', [AdminController::class, 'dismissReport'])->name('reports.dismiss');
    Route::patch('/reports/{report}/review', [AdminController::class, 'reviewReport'])->name('reports.review');

    // Settings
    Route::get('/settings', [AdminSettingController::class, 'index'])->name('settings');
    Route::post('/settings', [AdminSettingController::class, 'update'])->name('settings.update');

    // Meta-communauté (site global)
    Route::get('/meta_community', [AdminMetaCommunityController::class, 'index'])->name('meta-community');
    Route::post('/meta_community', [AdminMetaCommunityController::class, 'update'])->name('meta-community.update');

    Route::get('/email-test', [AdminEmailController::class, 'index'])->name('email-test');
    Route::post('/email-test', [AdminEmailController::class, 'send'])->name('email-test.send');
});

// Community landing page (with or without trailing slash)
// Negative lookahead excludes reserved global slugs so /login, /register, /admin etc. are never captured.
$communityConstraint = '(?!login|register|admin|api|sitemap|search|explorer|profile|password|membres|echanges|boucles)[a-z0-9][a-z0-9\-]*';

Route::get('/{community}', function ($community) {
    return redirect("/{$community}/");
})->where('community', $communityConstraint)->name('community.redirect');

// Community-prefixed routes (/{community}/...)
Route::prefix('/{community}')
    ->middleware(['web', 'community'])
    ->where(['community' => $communityConstraint])
    ->name('community.')
    ->group(function () {
        // Page d'accueil de la communauté
        Route::get('/', [CommunityLandingController::class, '__invoke'])->name('home');

        // Routes guest (auth)
        Route::middleware('guest')->group(function () {
            Route::get('/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'create'])->name('login');
            Route::post('/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'store']);
            Route::get('/register', [\App\Http\Controllers\Auth\RegisteredUserController::class, 'create'])->name('register');
            Route::post('/register', [\App\Http\Controllers\Auth\RegisteredUserController::class, 'store']);
            Route::get('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'create'])->name('password.request');
            Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'store'])->name('password.email');
            Route::get('/reset-password/{token}', [\App\Http\Controllers\Auth\NewPasswordController::class, 'create'])->name('password.reset');
            Route::post('/reset-password', [\App\Http\Controllers\Auth\NewPasswordController::class, 'store'])->name('password.store');
        });

        // Routes authentifiées
        Route::middleware('auth')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::post('/logout', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'destroy'])->name('logout');

            // Services
            Route::middleware('profile.complete')->group(function () {
                Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
                Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
            });
            Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
            Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
            Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

            // Requests (demandes)
            Route::middleware('profile.complete')->group(function () {
                Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
                Route::post('/requests', [RequestController::class, 'store'])->name('requests.store');
            });
            Route::delete('/requests/{request}', [RequestController::class, 'destroy'])->name('requests.destroy');

            // Transactions
            Route::get('/transactions/export', [TransactionController::class, 'exportCsv'])->name('transactions.export');
            Route::post('/transactions', [TransactionController::class, 'store'])->middleware('throttle:10,1')->name('transactions.store');
            Route::patch('/transactions/{transaction}/approve', [TransactionController::class, 'approve'])->name('transactions.approve');
            Route::patch('/transactions/{transaction}/refuse', [TransactionController::class, 'refuse'])->name('transactions.refuse');
            Route::patch('/transactions/{transaction}/adjust', [TransactionController::class, 'adjust'])->name('transactions.adjust');
            Route::patch('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('transactions.cancel');
            Route::patch('/transactions/{transaction}/complete', [TransactionController::class, 'complete'])->name('transactions.complete');
            Route::patch('/transactions/{transaction}/confirm', [TransactionController::class, 'confirm'])->name('transactions.confirm');
            Route::patch('/transactions/{transaction}/contest', [TransactionController::class, 'contest'])->name('transactions.contest');

            // Reviews
            Route::post('/transactions/{transaction}/review', [ReviewController::class, 'store'])->middleware('throttle:5,1')->name('reviews.store');

            // Messages
            Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
            Route::get('/messages/{transaction}', [MessageController::class, 'show'])->name('messages.show');

            // Points history
            Route::get('/points', [PointController::class, 'index'])->name('points.index');

            // Favorites
            Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
            Route::post('/favorites/{service}/toggle', [FavoriteController::class, 'toggle'])->middleware('throttle:30,1')->name('favorites.toggle');

            // Reports
            Route::post('/reports/service/{service}', [ReportController::class, 'storeService'])->middleware('throttle:5,1')->name('reports.service');
            Route::post('/reports/request/{serviceRequest}', [ReportController::class, 'storeRequest'])->middleware('throttle:5,1')->name('reports.request');
            Route::post('/reports/user/{user}', [ReportController::class, 'storeUser'])->middleware('throttle:5,1')->name('reports.user');

            // Profile
            Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
            Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::patch('/profile/availability', [ProfileController::class, 'toggleAvailability'])->name('profile.availability');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        });

        // Routes publiques communauté
        Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show')->whereUuid('service');
        Route::get('/requests/{request}', [RequestController::class, 'show'])->name('requests.show');
        Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');
        Route::get('/explorer', [ExplorerController::class, 'index'])->name('explorer');
        Route::get('/membres', [HomeController::class, 'members'])->name('members.index');
        Route::get('/echanges', [HomeController::class, 'exchanges'])->name('exchanges.index');
    });
