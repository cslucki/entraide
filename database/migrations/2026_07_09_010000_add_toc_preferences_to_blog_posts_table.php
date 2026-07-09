<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->unsignedTinyInteger('toc_max_level')->default(4)->after('show_toc');
            $table->boolean('toc_navigation_enabled')->default(false)->after('toc_max_level');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropColumn(['toc_max_level', 'toc_navigation_enabled']);
        });
    }
};
