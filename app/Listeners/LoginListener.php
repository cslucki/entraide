<?php

namespace App\Listeners;

use App\Models\LoginLog;
use Illuminate\Auth\Events\Login;

class LoginListener
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user || ! $user->organization_id) {
            return;
        }

        LoginLog::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
