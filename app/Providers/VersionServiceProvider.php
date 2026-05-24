<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class VersionServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $versionFile = base_path('VERSION');
        $version = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : 'v0.0-alpha';
        config(['app.version' => $version]);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
