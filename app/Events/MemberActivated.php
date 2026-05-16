<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class MemberActivated
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?array $metadata = null,
    ) {}
}
