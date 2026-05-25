<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureUserIsNotBanned;
use App\Http\Middleware\ResolveApiOrganization;
use App\Http\Middleware\ResolveCommunity;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\ResolveUrlOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'community' => ResolveCommunity::class,
            'organization' => ResolveOrganization::class,
            'profile.complete' => EnsureProfileComplete::class,
            'url.organization' => ResolveUrlOrganization::class,
            'api.organization' => ResolveApiOrganization::class,
        ]);
        // TASK-145: Reorder web group so ResolveUrlOrganization runs BEFORE
        // SubstituteBindings. With appendToGroup, ResolveUrlOrganization ran AFTER
        // SubstituteBindings, meaning route model binding (Service $service, etc.)
        // fired BEFORE the Organization was resolved. BelongsToTenantScope then
        // blocked every query with whereRaw('0=1'), causing 404 on model-bound
        // routes like /services/{service}/edit.
        $middleware->group('web', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            EnsureUserIsNotBanned::class,
            ResolveUrlOrganization::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
        $middleware->appendToGroup('api', [
            ResolveApiOrganization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
