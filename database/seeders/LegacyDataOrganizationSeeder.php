<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LegacyDataOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $community = $this->resolveDefaultOrganization();

        if (! $community) {
            $this->command->warn('No active organization found. Skipping legacy data backfill.');

            return;
        }

        $this->command->info("Default organization: {$community->name} ({$community->id})");

        $alreadySet = Setting::get('default_organization_id');
        if ($alreadySet === (string) $community->id) {
            $this->command->warn('Default organization already configured. Checking for remaining NULL records...');
        }

        $tables = $this->getTablesWithOrganizationColumns();

        $totalUpdated = 0;

        foreach ($tables as $table) {
            $count = DB::table($table)
                ->whereNull('community_id')
                ->whereNull('organization_id')
                ->count();

            if ($count === 0) {
                continue;
            }

            $updated = DB::table($table)
                ->whereNull('community_id')
                ->whereNull('organization_id')
                ->update([
                    'organization_id' => $community->id,
                ]);

            $totalUpdated += $updated;
            $this->command->info("  {$table}: {$updated} records backfilled");
        }

        Setting::set('default_organization_id', (string) $community->id);

        $this->command->info("Total legacy records backfilled: {$totalUpdated}");
        $this->command->info("Default organization ID set to: {$community->id}");
    }

    private function resolveDefaultOrganization(): ?Organization
    {
        $defaultId = Setting::get('default_organization_id');
        if ($defaultId) {
            $community = Organization::find($defaultId);
            if ($community) {
                return $community;
            }
        }

        $community = Organization::where('slug', 'main')->first();
        if ($community) {
            return $community;
        }

        $community = Organization::where('is_public', true)
            ->where('is_active', true)
            ->first();

        if ($community) {
            return $community;
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
