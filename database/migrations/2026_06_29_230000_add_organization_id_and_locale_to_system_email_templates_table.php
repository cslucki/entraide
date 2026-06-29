<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_email_templates', function (Blueprint $table) {
            $table->dropUnique(['slug']);

            $table->foreignUuid('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();

            $table->string('locale', 5)->nullable();

            $table->unique(['organization_id', 'locale', 'slug']);
            $table->index('organization_id');
            $table->index('locale');
        });
    }

    public function down(): void
    {
        Schema::table('system_email_templates', function (Blueprint $table) {
            $table->dropIndex(['locale']);
            $table->dropIndex(['organization_id']);
            $table->dropUnique(['organization_id', 'locale', 'slug']);

            $table->dropColumn(['organization_id', 'locale']);

            $table->string('slug')->unique()->change();
        });
    }
};
