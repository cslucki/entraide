<?php

namespace App\Providers;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Events\LoopMessageCreated;
use App\Http\Middleware\ResolveOrganization;
use App\Jobs\GenerateAiAgentResponse;
use App\Listeners\LoginListener;
use App\Models\AiConfig;
use App\Models\BlogPost;
use App\Models\BugReport;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\EmailLog;
use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\ProfileAgentConversation;
use App\Models\Report;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Observers\BlogPostObserver;
use App\Observers\DossierFileObserver;
use App\Observers\DossierObserver;
use App\Observers\ServiceObserver;
use App\Observers\TransactionObserver;
use App\Observers\TranslationOverrideObserver;
use App\Policies\FeedPostPolicy;
use App\Policies\LoopPolicy;
use App\Policies\MessagePolicy;
use App\Policies\ProfileAgentConversationPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceRequestPolicy;
use App\Policies\TransactionPolicy;
use App\Scenarios\BoundedMemberScenario;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\AiScenarioFactory;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\FakeAIProvider;
use App\Services\Ai\Logging\AiBenchmarkLogger;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;
use App\Services\Ai\Providers\LoggingSupervisionProvider;
use App\Services\Ai\Providers\OllamaSupervisionProvider;
use App\Services\Ai\Providers\OpenAiSupervisionProvider;
use App\Services\Ai\Providers\OpenRouterSupervisionProvider;
use App\Services\Ai\Scenarios\ClarifyHelpRequestScenario;
use App\Services\Ai\Scenarios\ServiceOfferMasterScenario;
use App\Services\Ai\Scenarios\SupervisionContentScenario;
use App\Services\Ai\SupervisionProviderResolver;
use App\Services\LoopTypeSettingsService;
use App\Services\ReferralCodeGenerator;
use App\Services\RewardDispatcher;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReferralCodeGenerator::class);
        $this->app->singleton(RewardDispatcher::class);
        $this->app->singleton(SupervisionProviderResolver::class);
        $this->app->singleton(AdminAiInteractionPersistence::class);

        // Singleton for its per-request memo: card presets are asked for on
        // every workspace render, and a fresh instance per resolution would
        // query loop_type_settings each time.
        $this->app->singleton(LoopTypeSettingsService::class);

        // Meme raison, depuis que le catalogue de types ne vient plus seulement
        // du fichier de configuration : `exists()` et `definition()` sont sur
        // des chemins chauds, et les vues resolvent le registre a chaque appel.
        // Sans singleton, le memo du catalogue serait recree — donc inutile.
        $this->app->singleton(LoopTypeRegistry::class);
        $this->app->bind(AiProvider::class, function ($app) {
            return new ClarifyUserHelpRequestService(
                $app->make(SupervisionProviderResolver::class),
                $app->make(AiScenarioFactory::class),
                $app->make(FakeAIProvider::class),
                $app->make(CapabilityRegistry::class),
                $app->make(PromptRepository::class),
                $app->make(ProviderResolver::class),
                $app->make(ContextBuilder::class),
                $app->make(AiEconomicGuard::class),
                $app->make(AiProviderInvocationLedger::class),
            );
        });

        $this->app->singleton(SupervisionProvider::class, function ($app) {
            $config = $app['config']->get('ai.openai');

            // TASK-1132 : les tarifs ne sont plus injectés ici. Les défauts
            // `?? 0.15` / `?? 0.60` dupliquaient config/ai.php et masquaient
            // l'absence de tarif. Le provider interroge désormais le catalogue
            // versionné (config/ai_pricing.php).
            $inner = new OpenAiSupervisionProvider(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? 'https://api.openai.com/v1'),
                model: (string) ($config['model'] ?? ''),
                maxOutputTokens: (int) ($config['max_output_tokens'] ?? 900),
                timeout: (int) ($config['timeout'] ?? 15),
            );

            return new LoggingSupervisionProvider(
                $inner,
                $app->make(AiBenchmarkLogger::class),
                $app->make(AdminAiInteractionPersistence::class),
                'openai',
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

        $this->app->singleton(OpenRouterSupervisionProvider::class, function ($app) {
            $config = $app['config']->get('ai.openrouter');

            return new OpenRouterSupervisionProvider(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? 'https://openrouter.ai/api/v1'),
                model: (string) ($config['model'] ?? ''),
                maxOutputTokens: (int) ($config['max_output_tokens'] ?? 900),
                timeout: (int) ($config['timeout'] ?? 30),
                siteName: (string) ($config['site_name'] ?? ''),
                siteUrl: (string) ($config['site_url'] ?? ''),
            );
        });

        $this->app->singleton(AiScenarioFactory::class, function ($app) {
            $factory = new AiScenarioFactory;
            $factory->register(new SupervisionContentScenario);
            $factory->register(new ClarifyHelpRequestScenario);
            $factory->register(new ServiceOfferMasterScenario);
            $factory->register(new BoundedMemberScenario);

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

        Livewire::addPersistentMiddleware(ResolveOrganization::class);

        try {
            $dbProvider = AiConfig::get('default_provider');
            if ($dbProvider) {
                config(['ai.default_provider' => $dbProvider]);
            }
            $dbModel = AiConfig::get('default_model');
            if ($dbModel) {
                config(['ai.default_model' => $dbModel]);
            }
        } catch (\Exception) {
            // ai_configs table may not exist yet (migrations pending)
        }

        Transaction::observe(TransactionObserver::class);
        Service::observe(ServiceObserver::class);
        TranslationOverride::observe(TranslationOverrideObserver::class);
        BlogPost::observe(BlogPostObserver::class);
        Dossier::observe(DossierObserver::class);
        DossierFile::observe(DossierFileObserver::class);

        Event::listen(
            LoopMessageCreated::class,
            function (LoopMessageCreated $event) {
                $loop = Loop::with('memberAiProfile')
                    ->where('id', $event->loopId)
                    ->first();

                if (! $loop?->isAiAgent()) {
                    return;
                }

                $message = LoopMessage::find($event->id);

                if (! $message) {
                    return;
                }

                // Une projection de ServiceRequest est une annonce metier, pas
                // une question adressee a l'agent. Les help_request historiques
                // non lies conservent leur comportement : la garde est bornee
                // aux deux marqueurs explicites de TASK-1211.
                if ($message->isServiceRequestProjection()) {
                    return;
                }

                dispatch(new GenerateAiAgentResponse($loop, $message));
            },
        );

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api-auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // TASK-1227 : le bac a sable de la doctrine emet un appel IA REEL et
        // facture (credential de l'Organization) : quelques essais par minute
        // et par utilisateur suffisent a une recette, jamais a une rafale.
        RateLimiter::for('ai-doctrine-sandbox', function (Request $request) {
            return Limit::perMinute((int) config('ai.doctrine.sandbox_per_minute', 6))
                ->by('ai-doctrine-sandbox:'.($request->user()?->id ?: $request->ip()));
        });

        Event::listen(
            Login::class,
            LoginListener::class,
        );

        Event::listen(
            NotificationSent::class,
            function (NotificationSent $event) {
                if ($event->channel !== 'mail') {
                    return;
                }

                if (! str_starts_with($event->notification::class, 'App\\Notifications\\')) {
                    return;
                }

                $notifiable = $event->notifiable;

                if (! $notifiable instanceof User) {
                    return;
                }

                try {
                    $message = $event->notification->toMail($notifiable);

                    EmailLog::create([
                        'template_id' => null,
                        'user_id' => $notifiable->id,
                        'organization_id' => $notifiable->organization_id,
                        'to_email' => $notifiable->email,
                        'subject' => $message->subject,
                        'status' => 'sent',
                        'data' => [
                            'source' => class_basename($event->notification),
                        ],
                    ]);
                } catch (\Throwable) {
                    // Listener must not break the request.
                }
            },
        );

        Gate::policy(FeedPost::class, FeedPostPolicy::class);
        Gate::policy(Loop::class, LoopPolicy::class);
        Gate::policy(ProfileAgentConversation::class, ProfileAgentConversationPolicy::class);
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

        Route::bind('loop', function (string $value, $route = null) {
            // L'Organization se lit sur **la route en cours de resolution**, et
            // non sur `request()`.
            //
            // La difference n'est visible que sur une requete Livewire. Livero
            // rejoue les middlewares persistants de la page d'origine en
            // reconstruisant sa route ; mais `request()` reste, lui, le
            // `POST /livewire/update`, qui ne porte aucun parametre
            // `organization`. Le slug tombait donc dans la branche « pas
            // d'Organization » et repondait 404, tandis qu'un UUID passait
            // outre — d'ou une Boucle qui marchait par UUID et cassait toutes
            // ses interactions par slug.
            //
            // Le repli sur `request()` reste pour les appels qui n'ont pas de
            // route, et le filtre par `organization_id` est inchange : rien
            // n'est ouvert, c'est seulement la bonne source qui est lue.
            $orgSlug = $route->parameter('organization');

            // `ResolveOrganization` vient de resoudre la meme Organization
            // dans ce pipeline et l'a laissee dans le conteneur. La relire
            // coutait une requete de plus a chaque mise a jour Livewire, pour
            // le meme resultat.
            $org = null;

            if ($orgSlug) {
                $courante = currentOrganization();

                $org = ($courante && $courante->slug === $orgSlug)
                    ? $courante
                    : Organization::findBySlug($orgSlug);

                if (! $org) {
                    abort(404);
                }
            }

            if (Str::isUuid($value)) {
                $query = Loop::query();

                // Sans Organization dans la route — les chemins hors `/org/` —
                // l'UUID n'est pas filtre ici : ce sont les controleurs qui
                // verifient le tenant en aval. Avec une Organization, le filtre
                // s'applique, et c'est le **seul** garde-fou sur le chemin
                // Livewire, ou aucun controleur ne tourne.
                if ($org) {
                    $query->where('organization_id', $org->id);
                }

                return $query->findOrFail($value);
            }

            // Un slug n'a de sens que dans une Organization : le meme peut
            // exister dans plusieurs.
            if (! $org) {
                abort(404);
            }

            return Loop::where('slug', $value)
                ->where('organization_id', $org->id)
                ->firstOrFail();
        });

        Route::bind('user', function (string $value): User {
            return User::where('id', $value)->firstOrFail();
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
                            'brandLogoUrl' => $org->logo_url ?: asset('brand/bouclepro-symbol-64.png'),
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
                            'brandLogoUrl' => $defaultOrg?->logo_url ?: asset('brand/bouclepro-symbol-64.png'),
                        ];
                    }
                } catch (\Exception) {
                    $settings = [
                        'currentOrganization' => null,
                        'brandOrganizationName' => null,
                        'platformName' => config('app.name'),
                        'platformTagline' => 'Échangez vos talents',
                        'globalColorMode' => 'dark',
                        'brandLogoUrl' => asset('brand/bouclepro-symbol-64.png'),
                    ];
                }
            }
            $view->with($settings);
        });
    }
}
