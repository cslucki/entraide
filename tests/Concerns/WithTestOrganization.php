<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\User;

trait WithTestOrganization
{
    protected Organization $testOrganization;

    protected function setUpOrganization(): void
    {
        $this->testOrganization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->testOrganization);
    }

    protected function orgUser(array $overrides = []): User
    {
        return User::factory()->complete()->create(array_merge([
            'organization_id' => $this->testOrganization->id,
        ], $overrides));
    }
}
