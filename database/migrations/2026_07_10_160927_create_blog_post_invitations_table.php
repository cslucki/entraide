<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignUuid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('token', 64)->unique();
            $table->text('message')->nullable();
            $table->enum('invitation_type', ['existing_member', 'external'])->default('external');
            $table->enum('status', ['pending', 'accepted', 'expired'])->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['token', 'status']);
            $table->index(['recipient_email', 'blog_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_invitations');
    }
};
