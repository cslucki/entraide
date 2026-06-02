<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('blog_comments', 'organization_id')) {
            Schema::table('blog_comments', function (Blueprint $table) {
                $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('point_ledger', 'organization_id')) {
            Schema::table('point_ledger', function (Blueprint $table) {
                $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('favorites', 'organization_id')) {
            Schema::table('favorites', function (Blueprint $table) {
                $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('likes', 'organization_id')) {
            Schema::table('likes', function (Blueprint $table) {
                $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('service_images', 'organization_id')) {
            Schema::table('service_images', function (Blueprint $table) {
                $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('request_attachments', 'organization_id')) {
            Schema::table('request_attachments', function (Blueprint $table) {
                $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        DB::statement('UPDATE blog_comments SET organization_id = (SELECT organization_id FROM blog_posts WHERE blog_posts.id = blog_comments.blog_post_id) WHERE organization_id IS NULL');

        DB::statement('UPDATE point_ledger SET organization_id = (SELECT organization_id FROM users WHERE users.id = point_ledger.user_id) WHERE organization_id IS NULL');

        DB::statement('UPDATE favorites SET organization_id = (SELECT organization_id FROM services WHERE services.id = favorites.service_id) WHERE organization_id IS NULL');

        DB::statement('UPDATE likes SET organization_id = (SELECT organization_id FROM blog_posts WHERE blog_posts.id = likes.likeable_id) WHERE likes.likeable_type = ? AND likes.organization_id IS NULL', ['App\\Models\\BlogPost']);
        DB::statement('UPDATE likes SET organization_id = (SELECT organization_id FROM blog_comments WHERE blog_comments.id = likes.likeable_id) WHERE likes.likeable_type = ? AND likes.organization_id IS NULL', ['App\\Models\\BlogComment']);
        DB::statement('UPDATE likes SET organization_id = (SELECT organization_id FROM services WHERE services.id = likes.likeable_id) WHERE likes.likeable_type = ? AND likes.organization_id IS NULL', ['App\\Models\\Service']);

        DB::statement('UPDATE service_images SET organization_id = (SELECT organization_id FROM services WHERE services.id = service_images.service_id) WHERE organization_id IS NULL');

        DB::statement('UPDATE request_attachments SET organization_id = (SELECT organization_id FROM service_requests WHERE service_requests.id = request_attachments.service_request_id) WHERE organization_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('blog_comments', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
        Schema::table('point_ledger', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
        Schema::table('favorites', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
        Schema::table('likes', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
        Schema::table('service_images', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
        Schema::table('request_attachments', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
    }
};
