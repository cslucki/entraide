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
        // T075.2 : middleware créé, testé, alias « url.organization » disponible.
        // Web group integration deferred to T075.3+ : 36 tests fail because they
        // hit business routes without setting up an Organization.
        $middleware->appendToGroup('web', [
            EnsureUserIsNotBanned::class,
            ResolveUrlOrganization::class,
        ]);
        $middleware->appendToGroup('api', [
            ResolveApiOrganization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
