<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_ai_interactions', function (Blueprint $table) {
            $table->string('review_status', 20)->nullable()->after('metadata');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('review_status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');

            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('admin_ai_interactions', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropColumn(['review_status', 'reviewed_by', 'reviewed_at', 'review_notes']);
        });
    }
};
