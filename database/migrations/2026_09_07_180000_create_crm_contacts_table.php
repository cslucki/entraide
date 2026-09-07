<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1413 — CRM-1, le Contact d'Organization.
 *
 * Un Contact est un objet de RELATION appartenant a une Organization (le
 * tenant). Il existe AVANT tout compte : `user_id` n'est qu'un lien optionnel,
 * pose quand le prospect ouvre un compte dans la MEME Organization. Aucun
 * faux User n'est jamais cree pour un prospect.
 *
 * `organization_id` est NOT NULL : un Contact sans Organization est une
 * erreur de programmation, pas une valeur par defaut.
 *
 * Unicites tenant-scoped : un email par Organization, un membre par
 * Organization. Les NULL sont distincts dans un index unique (PostgreSQL et
 * SQLite) : des Contacts sans email, ou non relies, coexistent librement.
 *
 * `phone` est conserve BRUT ; `phone_normalized` n'est qu'une forme de
 * comparaison strictement syntaxique (arbitrage MASTER 07/09, Q3) — pas du
 * E.164, pas de pays devine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 30)->nullable();
            $table->string('company', 150)->nullable();
            $table->string('source', 40)->default('manual');
            $table->string('source_ref')->nullable();
            $table->timestamp('do_not_contact_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'email']);
            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'phone_normalized']);
            $table->index(['organization_id', 'last_interaction_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
    }
};
