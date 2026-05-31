<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('communities') && ! Schema::hasTable('organizations')) {
            Schema::rename('communities', 'organizations');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('organizations') && ! Schema::hasTable('communities')) {
            Schema::rename('organizations', 'communities');
        }
    }
};
