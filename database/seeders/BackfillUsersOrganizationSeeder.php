<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Database\Seeder;
use RuntimeException;

class BackfillUsersOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $organization = DefaultOrganizationResolver::resolve();

        if (! $organization) {
            throw new RuntimeException('BackfillUsersOrganizationSeeder requires an active default organization.');
        }

        $updated = User::whereNull('organization_id')->update([
            'organization_id' => $organization->id,
        ]);

        if ($this->command) {
            $this->command->info("Backfilled {$updated} users to organization {$organization->slug}.");
        }
    }
}
