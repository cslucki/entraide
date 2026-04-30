<?php

namespace App\Providers;

use App\Models\Report;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Policies\MessagePolicy;
use App\Policies\ReviewPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceRequestPolicy;
use App\Policies\TransactionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::useTailwind();

        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(ServiceRequest::class, ServiceRequestPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(\App\Models\Message::class, MessagePolicy::class);
        Gate::policy(\App\Models\Review::class, ReviewPolicy::class);

        View::composer('layouts.admin', function ($view) {
            $view->with('pendingReportsCount', Report::where('status', 'pending')->count());
        });
    }
}
