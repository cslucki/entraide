<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_post_annotations', function (Blueprint $table) {
            $table->string('origin', 30)->default('human')->after('status')->index();
            $table->string('method_key', 30)->nullable()->after('origin')->index();
            $table->uuid('ai_interaction_id')->nullable()->after('method_key')->index();

            $table->foreign('ai_interaction_id')
                ->references('id')
                ->on('ai_interactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('blog_post_annotations', function (Blueprint $table) {
            $table->dropForeign(['ai_interaction_id']);
            $table->dropIndex(['origin']);
            $table->dropIndex(['method_key']);
            $table->dropIndex(['ai_interaction_id']);
            $table->dropColumn(['origin', 'method_key', 'ai_interaction_id']);
        });
    }
};
