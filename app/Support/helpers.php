<?php

use App\Support\Tenancy\CurrentOrganization;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;

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
     *   organizationRoute('organization.home', ['organization' => $slug])
     *
     * @param  string  $name  Route name (e.g. 'organization.home')
     * @param  array  $parameters  Route parameters
     */
    function organizationRoute(string $name, array $parameters = []): string
    {
        return route($name, $parameters);
    }
}

if (! function_exists('markdown')) {
    function markdown(string $text): string
    {
        $converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $converter->getEnvironment()->addExtension(new GithubFlavoredMarkdownExtension);

        return (string) $converter->convert($text);
    }
}
