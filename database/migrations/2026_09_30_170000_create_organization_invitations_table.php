<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_first_name')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email');
            $table->string('token', 64)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['token', 'status']);
            $table->index(['recipient_email', 'organization_id']);

            // Deliberately not a partial unique index — same reasoning as
            // loop_invitations (2026_08_03_100000): "at most one pending
            // invitation per organization + recipient" is enforced in
            // OrganizationInvitationService under a transaction with
            // lockForUpdate, identically on SQLite and PostgreSQL.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
