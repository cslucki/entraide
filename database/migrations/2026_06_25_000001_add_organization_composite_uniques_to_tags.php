<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique('tags_slug_unique');
            $table->dropUnique('tags_name_unique');

            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'slug']);
            $table->dropUnique(['organization_id', 'name']);

            $table->unique('slug', 'tags_slug_unique');
            $table->unique('name', 'tags_name_unique');
        });
    }
};
