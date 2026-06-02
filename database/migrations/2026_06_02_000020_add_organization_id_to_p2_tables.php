<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['categories', 'skills', 'tags', 'badges', 'point_guidelines',
                    'email_templates', 'email_logs'];

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'organization_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
                    $t->index('organization_id');
                });
            }
        }

        $pivots = ['badge_user', 'blog_post_category', 'blog_post_tag',
                    'service_skill', 'service_tag'];

        foreach ($pivots as $table) {
            if (! Schema::hasColumn($table, 'organization_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
                    $t->index('organization_id');
                });
            }
        }

        $defaultOrg = DB::table('organizations')->where('is_default', true)->value('id');
        if ($defaultOrg) {
            $allTables = array_merge($tables, $pivots);
            foreach ($allTables as $table) {
                DB::table($table)->whereNull('organization_id')->update(['organization_id' => $defaultOrg]);
            }
        }
    }

    public function down(): void
    {
        $tables = ['categories', 'skills', 'tags', 'badges', 'point_guidelines',
                    'email_templates', 'email_logs',
                    'badge_user', 'blog_post_category', 'blog_post_tag',
                    'service_skill', 'service_tag'];

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'organization_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['organization_id']);
                    $t->dropForeign(['organization_id']);
                    $t->dropColumn('organization_id');
                });
            }
        }
    }
};
