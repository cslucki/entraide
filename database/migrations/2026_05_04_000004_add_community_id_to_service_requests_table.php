<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->uuid('community_id')->nullable()->after('id');
            $table->foreign('community_id')->references('id')->on('communities')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropForeign(['community_id']);
            $table->dropColumn('community_id');
        });
    }
};
