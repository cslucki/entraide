<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IRREVERSIBLE: Drops blog_post_category pivot table.
     * Only the first category per post is preserved (category_id).
     * down() recreates the pivot but cannot restore lost multi-category associations.
     */
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->foreignUuid('category_id')->nullable()->constrained('categories')->nullOnDelete()->after('organization_id');
        });

        $posts = DB::table('blog_post_category')->select('blog_post_id', 'category_id')->get();
        $assigned = [];
        foreach ($posts as $pivot) {
            if (!isset($assigned[$pivot->blog_post_id])) {
                $assigned[$pivot->blog_post_id] = $pivot->category_id;
            }
        }
        foreach ($assigned as $blogPostId => $categoryId) {
            DB::table('blog_posts')->where('id', $blogPostId)->update(['category_id' => $categoryId]);
        }

        Schema::dropIfExists('blog_post_category');
    }

    public function down(): void
    {
        Schema::create('blog_post_category', function (Blueprint $table) {
            $table->foreignUuid('blog_post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->primary(['blog_post_id', 'category_id']);
        });

        $posts = DB::table('blog_posts')->whereNotNull('category_id')->select('id', 'category_id', 'organization_id')->get();
        foreach ($posts as $post) {
            DB::table('blog_post_category')->insert([
                'blog_post_id'    => $post->id,
                'category_id'     => $post->category_id,
                'organization_id' => $post->organization_id,
            ]);
        }

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
