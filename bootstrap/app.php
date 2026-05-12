<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureUserIsNotBanned;
use App\Http\Middleware\ResolveCommunity;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'community' => ResolveCommunity::class,
            'organization' => ResolveOrganization::class,
            'profile.complete' => EnsureProfileComplete::class,
        ]);
        $middleware->appendToGroup('web', [
            EnsureUserIsNotBanned::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
