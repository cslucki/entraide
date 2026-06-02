<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LegacyDataOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $organization = $this->resolveDefaultOrganization();

        if (! $organization) {
            $this->command->warn('No active organization found. Skipping legacy data backfill.');

            return;
        }

        $this->command->info("Default organization: {$organization->name} ({$organization->id})");

        $tables = $this->getTablesWithOrganizationColumns();

        $totalUpdated = 0;

        foreach ($tables as $table) {
            $count = DB::table($table)
                ->whereNull('organization_id')
                ->count();

            if ($count === 0) {
                continue;
            }

            $updated = DB::table($table)
                ->whereNull('organization_id')
                ->update([
                    'organization_id' => $organization->id,
                ]);

            $totalUpdated += $updated;
            $this->command->info("  {$table}: {$updated} records backfilled");
        }

        $organization->update(['is_default' => true]);

        $this->command->info("Total legacy records backfilled: {$totalUpdated}");
        $this->command->info("Default organization set to: {$organization->name} ({$organization->id})");
    }

    private function resolveDefaultOrganization(): ?Organization
    {
        $organization = Organization::where('is_default', true)->first();
        if ($organization) {
            return $organization;
        }

        $organization = Organization::where('slug', 'main')->first();
        if ($organization) {
            return $organization;
        }

        $organization = Organization::where('is_public', true)
            ->where('is_active', true)
            ->first();

        if ($organization) {
            return $organization;
        }

        return Organization::where('is_active', true)->first();
    }

    private function getTablesWithOrganizationColumns(): array
    {
        return [
            'users',
            'services',
            'service_requests',
            'blog_posts',
            'transactions',
            'referrals',
            'referral_rewards',
        ];
    }
}
