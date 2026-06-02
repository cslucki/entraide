<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reviews', 'organization_id')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
                $table->index('organization_id');
            });

            DB::statement('UPDATE reviews SET organization_id = (SELECT organization_id FROM transactions WHERE transactions.id = reviews.transaction_id)');
        }

        if (! Schema::hasColumn('messages', 'organization_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
                $table->index('organization_id');
            });

            DB::statement('UPDATE messages SET organization_id = (SELECT organization_id FROM transactions WHERE transactions.id = messages.transaction_id)');
        }
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
