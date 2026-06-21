<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feed_posts')) {
            return;
        }

        Schema::table('feed_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('feed_posts', 'image_path')) {
                $table->string('image_path')->nullable()->after('content');
            }

            if (! Schema::hasColumn('feed_posts', 'url_preview')) {
                $table->json('url_preview')->nullable()->after('image_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('feed_posts')) {
            return;
        }

        Schema::table('feed_posts', function (Blueprint $table) {
            if (Schema::hasColumn('feed_posts', 'url_preview')) {
                $table->dropColumn('url_preview');
            }

            if (Schema::hasColumn('feed_posts', 'image_path')) {
                $table->dropColumn('image_path');
            }
        });
    }
};
