<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // services — community_id absent on fresh install (migration was no-op'd)
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'community_id')) {
                $table->dropForeign(['community_id']);
                $table->dropColumn('community_id');
            }
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // service_requests — community_id absent on fresh install
        Schema::table('service_requests', function (Blueprint $table) {
            if (Schema::hasColumn('service_requests', 'community_id')) {
                $table->dropForeign(['community_id']);
                $table->dropColumn('community_id');
            }
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // transactions — community_id absent on fresh install
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'community_id')) {
                $table->dropForeign(['community_id']);
                $table->dropColumn('community_id');
            }
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // users — community_id absent on fresh install
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'community_id')) {
                $table->dropForeign(['community_id']);
                $table->dropColumn('community_id');
            }
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // blog_posts — community_id absent on fresh install; organization_id FK already set by blog_posts migration
        if (Schema::hasColumn('blog_posts', 'community_id')) {
            Schema::table('blog_posts', function (Blueprint $table) {
                $table->dropForeign(['community_id']);
                $table->dropColumn('community_id');
                $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
            });
        }

        // loops — has unique composite index to drop and recreate
        if (Schema::hasColumn('loops', 'community_id')) {
            Schema::table('loops', function (Blueprint $table) {
                $table->dropUnique(['community_id', 'slug']);
                $table->dropForeign(['community_id']);
                $table->dropColumn('community_id');
                $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->unique(['organization_id', 'slug'], 'loops_organization_id_slug_unique');
            });
        }

        // referrals — has index + unique composite to drop and recreate
        if (Schema::hasColumn('referrals', 'community_id')) {
            Schema::table('referrals', function (Blueprint $table) {
                $table->dropIndex(['community_id']);
                $table->dropUnique('referrals_unique_pair');
                $table->dropForeign(['community_id']);
                $table->dropColumn('community_id');
                $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->unique(['organization_id', 'referrer_user_id', 'referred_user_id'], 'referrals_unique_pair');
            });
        }

        // referral_rewards — has index to drop
        if (Schema::hasColumn('referral_rewards', 'community_id')) {
            Schema::table('referral_rewards', function (Blueprint $table) {
                $table->dropIndex(['community_id']);
                $table->dropForeign(['community_id']);
                $table->dropColumn('community_id');
                $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        // Reverse order: services (last dropped, first restored)
        // We do NOT drop organization_id — we re-add community_id as nullable

        // services
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->uuid('community_id')->nullable()->after('id');
            $table->foreign('community_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // service_requests
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->uuid('community_id')->nullable()->after('id');
            $table->foreign('community_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // transactions
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->uuid('community_id')->nullable()->after('id');
            $table->foreign('community_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // users
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->uuid('community_id')->nullable()->after('id');
            $table->foreign('community_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // blog_posts.organization_id is owned by the blog_posts migration on fresh installs.
        // After up(), this migration cannot reliably distinguish fresh installs from legacy
        // databases, so it must not drop or recreate blog_posts tenant columns in down().

        // loops
        Schema::table('loops', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropUnique('loops_organization_id_slug_unique');
            $table->uuid('community_id');
            $table->foreign('community_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['community_id', 'slug']);
            $table->uuid('organization_id')->nullable()->change();
        });

        // referrals
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropUnique('referrals_unique_pair');
            $table->uuid('community_id')->nullable()->index();
            $table->foreign('community_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['community_id', 'referrer_user_id', 'referred_user_id'], 'referrals_unique_pair');
            $table->uuid('organization_id')->nullable()->change();
        });

        // referral_rewards
        Schema::table('referral_rewards', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->uuid('community_id')->nullable()->index();
            $table->foreign('community_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->uuid('organization_id')->nullable()->change();
        });
    }
};
