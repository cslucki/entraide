<?php

use App\Console\Commands\CheckAiBudgets;
use App\Console\Commands\FeedPublishScheduled;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:check-budgets', function () {
    $this->call(CheckAiBudgets::class);
})->purpose('Check AI monthly budgets and alert admins if exceeded');

Artisan::command('feed:publish-scheduled', function () {
    $this->call(FeedPublishScheduled::class);
})->purpose('Publish scheduled feed announcements whose date has passed');

Schedule::command('feed:publish-scheduled')->everyMinute();

// TASK-1433 — SW-3 : la retention des visiteurs du Shell Welcome est une promesse.
Schedule::command('guest:purge-expired')->daily();
