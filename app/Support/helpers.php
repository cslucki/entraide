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
     * Currently wraps route() with transparent 'community' → 'organization'
     * parameter mapping. This enables future dual routing without changing
     * call sites: when '/org/{organization}' routes are activated, swap
     * the mapping here.
     *
     * Usage:
     *   organizationRoute('community.home', ['community' => $slug])
     *   organizationRoute('community.home', ['organization' => $slug])
     *
     * @param  string  $name  Route name (e.g. 'community.home')
     * @param  array  $parameters  Route parameters
     */
    function organizationRoute(string $name, array $parameters = []): string
    {
        if (isset($parameters['community']) && ! isset($parameters['organization'])) {
            $parameters['organization'] = $parameters['community'];
            unset($parameters['community']);
        }

        return route($name, $parameters);
    }
}
