<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'pinned_at')) {
                $table->timestamp('pinned_at')->nullable()->after('metadata');
            }

            if (! Schema::hasColumn('messages', 'pinned_by_id')) {
                $table->uuid('pinned_by_id')->nullable()->after('pinned_at');
                $table->foreign('pinned_by_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'pinned_by_id')) {
                $table->dropForeign(['pinned_by_id']);
                $table->dropColumn('pinned_by_id');
            }

            if (Schema::hasColumn('messages', 'pinned_at')) {
                $table->dropColumn('pinned_at');
            }
        });
    }
};
