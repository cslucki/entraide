<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('messages', 'metadata')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('image_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages', 'metadata')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
