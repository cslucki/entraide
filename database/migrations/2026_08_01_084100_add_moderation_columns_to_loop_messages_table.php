<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('loop_messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('updated_at');
            }

            if (! Schema::hasColumn('loop_messages', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('edited_at');
            }

            if (! Schema::hasColumn('loop_messages', 'deleted_by')) {
                $table->foreignUuid('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('loop_messages', function (Blueprint $table) {
            if (Schema::hasColumn('loop_messages', 'deleted_by')) {
                $table->dropForeign(['deleted_by']);
                $table->dropColumn('deleted_by');
            }

            if (Schema::hasColumn('loop_messages', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }

            if (Schema::hasColumn('loop_messages', 'edited_at')) {
                $table->dropColumn('edited_at');
            }
        });
    }
};
