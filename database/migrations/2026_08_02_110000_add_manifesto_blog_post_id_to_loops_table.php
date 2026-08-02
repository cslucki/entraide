<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('loops', 'manifesto_blog_post_id')) {
            return;
        }

        Schema::table('loops', function (Blueprint $table) {
            $table->uuid('manifesto_blog_post_id')->nullable();
            $table->index('manifesto_blog_post_id');
            $table->foreign('manifesto_blog_post_id')
                ->references('id')->on('blog_posts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // SQLite cannot drop columns/foreign keys reliably on older engines.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasColumn('loops', 'manifesto_blog_post_id')) {
            return;
        }

        Schema::table('loops', function (Blueprint $table) {
            $table->dropForeign(['manifesto_blog_post_id']);
            $table->dropColumn('manifesto_blog_post_id');
        });
    }
};
