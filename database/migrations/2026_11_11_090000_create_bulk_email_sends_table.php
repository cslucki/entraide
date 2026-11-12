<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1803 — un envoi d'email en masse depuis le superadmin.
 *
 * `organization_id` NULL est une valeur a part entiere : « toutes les
 * Organizations ». C'est pour cela que le modele n'utilise PAS le trait
 * `HasOrganizationId` — son hook de creation remplacerait silencieusement ce
 * NULL par l'Organization courante de l'admin, qui n'a pourtant rien
 * demande de tel.
 *
 * Pas de vraie file d'attente dans cette application (`QUEUE_CONNECTION=sync`) :
 * l'envoi se fait par LOTS, d'ou `total_recipients`/`sent_count`/`failed_count`
 * qui suivent la progression entre deux clics de l'admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_email_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->string('member_filter');
            $table->string('content_source');
            $table->uuid('email_template_id')->nullable();
            $table->string('subject')->nullable();
            $table->string('content_format')->nullable();
            $table->longText('content_raw')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('email_template_id')->references('id')->on('email_templates')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_email_sends');
    }
};
