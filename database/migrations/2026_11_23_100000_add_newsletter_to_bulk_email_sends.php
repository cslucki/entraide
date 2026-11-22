<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1883 (demande Cyril, 22/11) — newsletter hebdo des demandes et
 * propositions d'aide, envoyee par le circuit BulkEmailSend.
 *
 * - `users.newsletter_unsubscribed_at` : NULL = abonne (arbitrage : activee
 *   par defaut), une date = desabonne depuis ;
 * - `bulk_email_sends` : le gabarit systeme et les deux nombres, le contenu
 *   etant calcule a l'envoi pour chaque destinataire ;
 * - `bulk_email_send_organizations` : les organisations ciblees (une ou
 *   plusieurs) ; aucune ligne = toutes, comme `organization_id` NULL.
 *
 * Additive et idempotente : nouvelles colonnes et nouvelle table seulement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'newsletter_unsubscribed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('newsletter_unsubscribed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('bulk_email_sends', 'system_template_slug')) {
            Schema::table('bulk_email_sends', function (Blueprint $table) {
                $table->string('system_template_slug', 100)->nullable();
                $table->unsignedSmallInteger('demand_count')->nullable();
                $table->unsignedSmallInteger('offer_count')->nullable();
            });
        }

        if (! Schema::hasTable('bulk_email_send_organizations')) {
            Schema::create('bulk_email_send_organizations', function (Blueprint $table) {
                $table->foreignUuid('bulk_email_send_id')->constrained('bulk_email_sends')->cascadeOnDelete();
                $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->primary(['bulk_email_send_id', 'organization_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_email_send_organizations');

        if (Schema::hasColumn('bulk_email_sends', 'system_template_slug')) {
            Schema::table('bulk_email_sends', function (Blueprint $table) {
                $table->dropColumn(['system_template_slug', 'demand_count', 'offer_count']);
            });
        }

        if (Schema::hasColumn('users', 'newsletter_unsubscribed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('newsletter_unsubscribed_at');
            });
        }
    }
};
