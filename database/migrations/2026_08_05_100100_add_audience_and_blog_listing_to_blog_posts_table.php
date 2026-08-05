<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two axes an article never had, kept deliberately separate.
 *
 * `status` already says where an article is in its editorial life — draft,
 * published, and so on. It says nothing about *who* may read it, nor about
 * whether it belongs in the Blog listing. Those are three different questions,
 * and folding them into one enum would make every future combination
 * impossible to express.
 *
 *   status          draft | published | …      editorial state, unchanged
 *   audience        public | organization | loop    who may read it
 *   listed_in_blog  true | false                    does it appear in the Blog
 *
 * A Loop's root document is `published` (it is live for its Loop from the
 * start), `audience = loop` (its confidentiality is the Loop's), and
 * `listed_in_blog = false` (it is not a blog article).
 *
 * Both columns are backfilled to today's behaviour: every existing article is
 * public and listed, which is exactly what it was before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            // Plain string, validated in the model. No enum: adding a fourth
            // audience must never require an ALTER TYPE on PostgreSQL.
            $table->string('audience', 20)->default('public')->after('status')->index();

            $table->boolean('listed_in_blog')->default(true)->after('audience')->index();
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropIndex(['listed_in_blog']);
            $table->dropIndex(['audience']);
            $table->dropColumn(['audience', 'listed_in_blog']);
        });
    }
};
