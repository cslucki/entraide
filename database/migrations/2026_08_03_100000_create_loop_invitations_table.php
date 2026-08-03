<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->foreignUuid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('token', 64)->unique();
            $table->text('message')->nullable();
            $table->string('invitation_type')->default('external');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['token', 'status']);
            $table->index(['recipient_email', 'loop_id']);

            // Deliberately not a partial unique index: "at most one pending
            // invitation per loop + recipient" cannot be expressed identically on
            // SQLite (tests) and PostgreSQL (dev/CI). The invariant is enforced in
            // LoopInvitationService under a transaction with lockForUpdate, which
            // also covers the accept path where the same row is mutated.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_invitations');
    }
};
