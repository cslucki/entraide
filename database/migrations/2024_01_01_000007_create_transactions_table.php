<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_id')->nullable();
            $table->uuid('request_id')->nullable();
            $table->uuid('buyer_id');
            $table->uuid('seller_id');
            $table->integer('points_proposed');
            $table->integer('points_agreed')->nullable();
            $table->enum('status', ['pending', 'accepted', 'buyer_done', 'completed', 'refused', 'cancelled'])->default('pending');
            $table->timestamp('buyer_confirmed_at')->nullable();
            $table->timestamp('seller_confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('service_id')->references('id')->on('services')->onDelete('set null');
            $table->foreign('request_id')->references('id')->on('service_requests')->onDelete('set null');
            $table->foreign('buyer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('seller_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
