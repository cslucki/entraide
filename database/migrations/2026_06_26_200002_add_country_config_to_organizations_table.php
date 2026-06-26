<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('default_country_code', 2)->nullable()->after('locale');
            $table->boolean('show_country')->default(true)->after('default_country_code');

            $table->foreign('default_country_code')->references('code')->on('countries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['default_country_code']);
            $table->dropColumn(['default_country_code', 'show_country']);
        });
    }
};
