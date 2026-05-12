<?php

use App\Support\Tenancy\CurrentOrganization;

if (! function_exists('currentOrganization')) {
    function currentOrganization()
    {
        return CurrentOrganization::get();
    }
}
