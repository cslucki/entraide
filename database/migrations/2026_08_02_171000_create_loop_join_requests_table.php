<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TASK-1075 — join requests for access_mode=request Loops. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_join_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['loop_id', 'status']);
            $table->index(['loop_id', 'user_id', 'status']);
            $table->index(['organization_id', 'loop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_join_requests');
    }
};
