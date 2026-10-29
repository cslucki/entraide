<?php

use App\Providers\AppServiceProvider;
use App\Providers\PasswordResetServiceProvider;
use App\Providers\VersionServiceProvider;

return [
    AppServiceProvider::class,
    PasswordResetServiceProvider::class,
    VersionServiceProvider::class,
];
