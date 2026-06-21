<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('feed_posts', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('pinned_by_id');
            }

            if (! Schema::hasColumn('feed_posts', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('scheduled_at');
            }

            if (! Schema::hasColumn('feed_posts', 'loop_message')) {
                $table->text('loop_message')->nullable()->after('published_at');
            }

            $table->index(['organization_id', 'status', 'scheduled_at'], 'feed_posts_org_status_scheduled_index');
        });
    }

    public function down(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->dropIndex('feed_posts_org_status_scheduled_index');

            if (Schema::hasColumn('feed_posts', 'loop_message')) {
                $table->dropColumn('loop_message');
            }

            if (Schema::hasColumn('feed_posts', 'published_at')) {
                $table->dropColumn('published_at');
            }

            if (Schema::hasColumn('feed_posts', 'scheduled_at')) {
                $table->dropColumn('scheduled_at');
            }
        });
    }
};
