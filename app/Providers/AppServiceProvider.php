<?php

namespace App\Providers;

use App\Models\Loop;
use App\Models\BugReport;
use App\Models\Report;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\Transaction;

use App\Observers\ServiceObserver;
use App\Observers\TransactionObserver;
use App\Policies\MessagePolicy;
use App\Policies\ReviewPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceRequestPolicy;
use App\Policies\TransactionPolicy;
use App\Services\Ai\AiScenarioFactory;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\FakeAIProvider;
use App\Services\Ai\Providers\OpenAiSupervisionProvider;
use App\Services\Ai\Providers\OllamaSupervisionProvider;
use App\Services\Ai\Logging\AiBenchmarkLogger;
use App\Services\Ai\Providers\LoggingSupervisionProvider;
use App\Services\Ai\Scenarios\ClarifyHelpRequestScenario;
use App\Services\Ai\Scenarios\SupervisionContentScenario;
use App\Services\ReferralCodeGenerator;
use App\Services\RewardDispatcher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReferralCodeGenerator::class);
        $this->app->singleton(RewardDispatcher::class);
        $this->app->bind(AiProvider::class, FakeAIProvider::class);

        $this->app->singleton(SupervisionProvider::class, function ($app) {
            $config = $app['config']->get('ai.openai');

            $inner = new OpenAiSupervisionProvider(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? 'https://api.openai.com/v1'),
                model: (string) ($config['model'] ?? 'gpt-4o-mini'),
                maxOutputTokens: (int) ($config['max_output_tokens'] ?? 900),
                timeout: (int) ($config['timeout'] ?? 15),
                inputPricePer1M: (float) ($config['input_price_per_1m'] ?? 0.15),
                outputPricePer1M: (float) ($config['output_price_per_1m'] ?? 0.60),
            );

            return new LoggingSupervisionProvider(
                $inner,
                $app->make(AiBenchmarkLogger::class),
            );
        });

        $this->app->singleton(OllamaSupervisionProvider::class, function ($app) {
            $config = $app['config']->get('ai.ollama');
            return new OllamaSupervisionProvider(
                baseUrl: (string) ($config['base_url'] ?? ''),
                model: (string) ($config['model'] ?? 'llama3.2'),
                timeout: (int) ($config['timeout'] ?? 30),
            );
        });

        $this->app->singleton(AiScenarioFactory::class, function ($app) {
            $factory = new AiScenarioFactory();
            $factory->register(new SupervisionContentScenario());
            $factory->register(new ClarifyHelpRequestScenario());
            return $factory;
        });
    }

    protected function resolveOrganizationFromRequest(): ?Organization
    {
        try {
            $request = request();
            if ($request && $request->segment(1) === 'org' && $request->segment(2)) {
                return Organization::where('slug', $request->segment(2))->first();
            }
        } catch (\Exception $e) {
            //
        }

        return null;
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
            $view->with('pendingBugReportsCount', BugReport::where('status', 'pending')->count());
            $view->with('pendingOrganizationRequestsCount', OrganizationRequest::where('status', 'pending')->count());
        });

        Route::bind('loop', function (string $value) {
            $currentOrg = app()->bound('current_organization') ? app('current_organization') : null;
            $orgId = $currentOrg?->id
                ?? auth()->user()?->organization_id;

            if ($orgId) {
                return Loop::where('organization_id', $orgId)->findOrFail($value);
            }

            return Loop::findOrFail($value);
        });

        View::share('T', config('terms'));

        View::composer('*', function ($view) {
            static $settings;
            if (! isset($settings)) {
                try {
                    $org = app()->bound('current_organization') ? app('current_organization') : null;
                    if (! $org) {
                        $org = $this->resolveOrganizationFromRequest();
                    }
                    if ($org) {
                        $settings = [
                            'currentOrganization' => $org,
                            'brandOrganizationName' => $org->name,
                            'platformName' => $org->platform_name ?: config('app.name'),
                            'platformTagline' => $org->platform_tagline ?: 'Échangez vos talents',
                            'globalColorMode' => $org->global_color_mode ?: 'dark',
                        ];
                    } else {
                        $userOrganizationName = auth()->user()?->organization?->name;
                        $defaultOrg = Organization::where('is_default', true)->first();
                        $settings = [
                            'currentOrganization' => $defaultOrg,
                            'brandOrganizationName' => $userOrganizationName ?: $defaultOrg?->name ?: config('app.name'),
                            'platformName' => $defaultOrg?->platform_name ?: config('app.name'),
                            'platformTagline' => $defaultOrg?->platform_tagline ?: 'Échangez vos talents',
                            'globalColorMode' => $defaultOrg?->global_color_mode ?: 'dark',
                        ];
                    }
                } catch (\Exception) {
                    $settings = [
                        'currentOrganization' => null,
                        'brandOrganizationName' => null,
                        'platformName' => config('app.name'),
                        'platformTagline' => 'Échangez vos talents',
                        'globalColorMode' => 'dark',
                    ];
                }
            }
            $view->with($settings);
        });
    }
}
