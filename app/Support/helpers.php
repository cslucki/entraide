<?php

use App\Support\Tenancy\CurrentOrganization;

if (! function_exists('currentOrganization')) {
    function currentOrganization()
    {
        return CurrentOrganization::get();
    }
}

if (! function_exists('organizationRoute')) {
    /**
     * Generate a URL for an organization route.
     *
     * Usage:
     *   organizationRoute('community.home', ['organization' => $slug])
     *
     * @param  string  $name  Route name (e.g. 'community.home')
     * @param  array  $parameters  Route parameters
     */
    function organizationRoute(string $name, array $parameters = []): string
    {
        return route($name, $parameters);
    }
}
