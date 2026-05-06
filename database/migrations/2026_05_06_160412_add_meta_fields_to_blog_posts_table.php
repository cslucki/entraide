<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->foreignUuid('community_id')->nullable()->constrained()->nullOnDelete()->after('user_id');
            $table->string('meta_title', 255)->nullable()->after('content');
            $table->string('meta_description', 320)->nullable()->after('meta_title');
            $table->unsignedSmallInteger('read_time')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropForeign(['community_id']);
            $table->dropColumn(['community_id', 'meta_title', 'meta_description', 'read_time']);
        });
    }
};
