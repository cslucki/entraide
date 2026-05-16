<?php

namespace App\Providers;

use App\Models\Report;
use App\Models\Service;
use App\Models\Setting;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Observers\ServiceObserver;
use App\Observers\TransactionObserver;
use App\Policies\MessagePolicy;
use App\Policies\ReviewPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceRequestPolicy;
use App\Policies\TransactionPolicy;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\FakeAIProvider;
use App\Services\ReferralCodeGenerator;
use App\Services\RewardDispatcher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReferralCodeGenerator::class);
        $this->app->singleton(RewardDispatcher::class);
        $this->app->bind(AiProvider::class, FakeAIProvider::class);
    }

    public function boot(): void
    {
        Paginator::useTailwind();

        Transaction::observe(TransactionObserver::class);
        Service::observe(ServiceObserver::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api-auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(ServiceRequest::class, ServiceRequestPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);

        // Message policy is keyed on Transaction since messages live in a transaction context
        Gate::define('view-transaction', [MessagePolicy::class, 'view']);
        Gate::define('store-message', [MessagePolicy::class, 'store']);
        Gate::define('create-review', [ReviewPolicy::class, 'create']);

        View::composer('layouts.admin', function ($view) {
            $view->with('pendingReportsCount', Report::where('status', 'pending')->count());
        });

        View::share('T', config('terms'));

        View::composer('*', function ($view) {
            static $settings;
            if (!isset($settings)) {
                try {
                    $settings = [
                        'platformName'    => Setting::get('platform_name', config('app.name')),
                        'platformTagline' => Setting::get('platform_tagline', 'Échangez vos talents'),
                        'globalColorMode' => Setting::get('global_color_mode', 'dark'),
                    ];
                } catch (\Exception) {
                    // Table absente (avant migration) : on utilise les valeurs par défaut
                    $settings = [
                        'platformName'    => config('app.name'),
                        'platformTagline' => 'Échangez vos talents',
                        'globalColorMode' => 'dark',
                    ];
                }
            }
            $view->with($settings);
        });
    }
}
