<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('community_id')->nullable()->index();
            $table->uuid('organization_id')->nullable()->index();
            $table->uuid('referrer_user_id');
            $table->uuid('referred_user_id');
            $table->uuid('parent_referral_id')->nullable()->index();
            $table->unsignedInteger('depth')->default(1);
            $table->string('status')->default('pending');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('community_id')->references('id')->on('communities')->cascadeOnDelete();
            $table->foreign('referrer_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('referred_user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['community_id', 'referrer_user_id', 'referred_user_id'], 'referrals_unique_pair');
            $table->index('referrer_user_id');
            $table->index('referred_user_id');
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->foreign('parent_referral_id')->references('id')->on('referrals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
