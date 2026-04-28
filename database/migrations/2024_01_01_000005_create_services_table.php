<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('title', 255);
            $table->text('description');
            $table->uuid('category_id');
            $table->enum('delivery_mode', ['remote', 'onsite', 'both']);
            $table->integer('points_cost');
            $table->enum('status', ['active', 'paused', 'deleted'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories');
        });

        Schema::create('service_skill', function (Blueprint $table) {
            $table->uuid('service_id');
            $table->uuid('skill_id');
            $table->primary(['service_id', 'skill_id']);

            $table->foreign('service_id')->references('id')->on('services')->onDelete('cascade');
            $table->foreign('skill_id')->references('id')->on('skills')->onDelete('cascade');
        });

        Schema::create('service_tag', function (Blueprint $table) {
            $table->uuid('service_id');
            $table->uuid('tag_id');
            $table->primary(['service_id', 'tag_id']);

            $table->foreign('service_id')->references('id')->on('services')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_tag');
        Schema::dropIfExists('service_skill');
        Schema::dropIfExists('services');
    }
};
