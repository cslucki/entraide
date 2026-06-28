<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_requests', function (Blueprint $table) {
            $table->string('contact_phone', 30)->nullable()->after('contact_email');
            $table->string('website_url', 255)->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('organization_requests', function (Blueprint $table) {
            $table->dropColumn(['contact_phone', 'website_url']);
        });
    }
};
