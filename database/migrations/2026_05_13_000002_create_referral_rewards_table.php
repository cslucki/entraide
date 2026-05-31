<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('community_id')->nullable()->index();
            $table->uuid('organization_id')->nullable()->index();
            $table->uuid('referral_id');
            $table->uuid('user_id');
            $table->uuid('source_user_id')->nullable();
            $table->string('event_type');
            $table->unsignedInteger('level')->default(1);
            $table->integer('points');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('community_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('referral_id')->references('id')->on('referrals')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('source_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index('event_type');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
    }
};
