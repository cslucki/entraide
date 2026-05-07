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
        Schema::create('referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('referrer_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('referee_id')->constrained('users')->onDelete('cascade');
            $table->boolean('registration_reward_paid')->default(false);
            $table->boolean('first_transaction_reward_paid')->default(false);
            $table->timestamps();

            $table->unique(['referrer_id', 'referee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
