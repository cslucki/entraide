<?php

use App\Console\Commands\CheckAiBudgets;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:check-budgets', function () {
    $this->call(CheckAiBudgets::class);
})->purpose('Check AI monthly budgets and alert admins if exceeded');
