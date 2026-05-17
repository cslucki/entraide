<?php

namespace Tests\Concerns;

use App\Models\Community;
use App\Models\User;

trait WithTestOrganization
{
    protected Community $testOrganization;

    protected function setUpOrganization(): void
    {
        $this->testOrganization = Community::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->testOrganization);
    }

    protected function orgUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'community_id' => $this->testOrganization->id,
        ], $overrides));
    }
}
