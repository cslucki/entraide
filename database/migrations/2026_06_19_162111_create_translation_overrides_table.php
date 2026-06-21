<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('locale', 5);
            $table->string('group');
            $table->string('key');
            $table->text('value');
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX translation_overrides_org_locale_group_key_unique ON translation_overrides (organization_id, locale, "group", "key") WHERE organization_id IS NOT NULL');

        DB::statement('CREATE UNIQUE INDEX translation_overrides_global_locale_group_key_unique ON translation_overrides (locale, "group", "key") WHERE organization_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');
    }
};
