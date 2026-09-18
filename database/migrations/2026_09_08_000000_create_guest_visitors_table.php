<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1433 — SW-3 : le visiteur du Shell Welcome (Addendum V2 §6).
 *
 * Identite pseudonyme : un cookie first-party opaque, dont seule l'EMPREINTE
 * (sha256) est stockee ; jamais l'IP, jamais de fingerprint. Une ligne par
 * (Organization, visiteur) : deux Organizations = deux lignes, jamais de
 * melange. `expires_at` porte la retention de la politique de l'Organization
 * (defaut 90 j) ; `claimed_user_id` sera pose par SW-11 (jamais de User
 * cree automatiquement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('visitor_key_hash', 64);
            $table->string('locale', 5)->nullable();
            $table->string('declared_first_name', 100)->nullable();
            $table->string('declared_role', 100)->nullable();
            $table->string('declared_interest', 255)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('shortcut', 100)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at');
            $table->foreignUuid('claimed_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'visitor_key_hash']);
            $table->index('expires_at');
            $table->index(['organization_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_visitors');
    }
};
