<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $user) {
            $user->string('referral_code')->nullable()->unique()->after('email');
            $user->foreignUuid('referrer_id')->nullable()->constrained('users')->nullOnDelete()->after('referral_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $user) {
            $user->dropForeign(['referrer_id']);
            $user->dropColumn(['referral_code', 'referrer_id']);
        });
    }
};
