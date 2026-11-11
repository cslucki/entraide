<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1803 — l'ensemble des destinataires d'un envoi en masse, FIGE une
 * seule fois a la creation (voir `BulkEmailSendService::resolveRecipients()`).
 *
 * Pourquoi une ligne par destinataire plutot que re-resoudre le filtre a
 * chaque lot : entre deux clics de l'admin, un membre peut s'inscrire,
 * changer d'Organization ou remplir sa bio — re-interroger le MEME filtre
 * decalerait silencieusement « le lot suivant ». Figer une fois rend l'ordre
 * des lots deterministe et la reprise (si l'onglet se ferme) triviale : il
 * suffit de reprendre les lignes encore `pending`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_email_send_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bulk_email_send_id');
            $table->uuid('user_id')->nullable();
            $table->string('status')->default('pending');
            $table->uuid('email_log_id')->nullable();
            $table->timestamps();

            $table->foreign('bulk_email_send_id')->references('id')->on('bulk_email_sends')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('email_log_id')->references('id')->on('email_logs')->nullOnDelete();
            $table->unique(['bulk_email_send_id', 'user_id']);
            $table->index(['bulk_email_send_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_email_send_recipients');
    }
};
