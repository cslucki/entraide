<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feed_post_loop') || ! Schema::hasColumn('feed_post_loop', 'id')) {
            return;
        }

        DB::statement('ALTER TABLE feed_post_loop ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        if (! Schema::hasTable('feed_post_loop') || ! Schema::hasColumn('feed_post_loop', 'id')) {
            return;
        }

        DB::statement('ALTER TABLE feed_post_loop ALTER COLUMN id DROP DEFAULT');
    }
};
