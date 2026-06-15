<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill organization_id on legacy likes and blog_comments.
     *
     * These tables had organization_id added post-creation via a P1 migration
     * (2026_06_02_000010). Records created after that migration ran may have
     * organization_id = NULL because the code never set it. This migration
     * backfills any remaining NULLs idempotently from the parent model.
     *
     * This is the final hardening step before code-level tenant safety is
     * enforced via HasOrganizationId trait + scoped controller queries.
     *
     * @deprecated Legacy blog likes — use Reaction instead.
     */
    public function up(): void
    {
        DB::statement('UPDATE likes SET organization_id = (SELECT organization_id FROM blog_posts WHERE blog_posts.id = likes.likeable_id) WHERE likes.likeable_type = ? AND likes.organization_id IS NULL', ['App\\Models\\BlogPost']);
        DB::statement('UPDATE likes SET organization_id = (SELECT organization_id FROM blog_comments WHERE blog_comments.id = likes.likeable_id) WHERE likes.likeable_type = ? AND likes.organization_id IS NULL', ['App\\Models\\BlogComment']);
        DB::statement('UPDATE blog_comments SET organization_id = (SELECT organization_id FROM blog_posts WHERE blog_posts.id = blog_comments.blog_post_id) WHERE blog_comments.organization_id IS NULL');
    }

    public function down(): void
    {
        // Irreversible — NULL values cannot be restored.
    }
};
