<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('membership_enabled')->default(false)->after('show_country');
            $table->string('membership_label_fr')->nullable()->after('membership_enabled');
            $table->string('membership_label_en')->nullable()->after('membership_label_fr');
        });

        Schema::create('organization_country_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('countries')->cascadeOnDelete();
            $table->unique(['organization_id', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_country_preferences');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'membership_enabled',
                'membership_label_fr',
                'membership_label_en',
            ]);
        });
    }
};
