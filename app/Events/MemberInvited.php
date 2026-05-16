<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class MemberInvited
{
    use Dispatchable;

    public function __construct(
        public User $referrer,
        public User $referred,
        public ?string $organizationId = null,
        public ?array $metadata = null,
    ) {}
}
