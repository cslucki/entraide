<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description');
            $table->string('icon', 10);
            $table->string('color', 7)->default('#6366f1');
            $table->timestamps();
        });

        Schema::create('badge_user', function (Blueprint $table) {
            $table->uuid('badge_id');
            $table->uuid('user_id');
            $table->timestamp('earned_at');
            $table->primary(['badge_id', 'user_id']);
            $table->foreign('badge_id')->references('id')->on('badges')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_user');
        Schema::dropIfExists('badges');
    }
};
