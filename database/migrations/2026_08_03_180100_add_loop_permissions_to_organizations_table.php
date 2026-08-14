<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Sparse overrides, shaped {type: {role: {permission: bool}}}.
            // An absent value means "inherit"; returning to inheritance deletes
            // the key rather than writing a default.
            //
            // A json column rather than a table, following the homepage_settings
            // precedent: it is tenant-scoped by construction. The generic
            // `settings` table tried in 2026_05_03 was dropped a month later —
            // it had a `key` primary key and no organization_id at all.
            $table->json('loop_permissions')->nullable()->after('loops_naming');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('loop_permissions');
        });
    }
};
