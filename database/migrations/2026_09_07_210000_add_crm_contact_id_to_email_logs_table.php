<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1421 — CRM-7b : un email envoye a un Contact laisse une preuve reliee
 * au Contact, sans faux User. `user_id` reste nullable (le Contact peut
 * n'avoir aucun compte) ; `crm_contact_id` relie la preuve a la fiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->foreignUuid('crm_contact_id')->nullable()->after('user_id')->constrained('crm_contacts')->nullOnDelete();
            $table->index(['crm_contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['crm_contact_id', 'created_at']);
            $table->dropConstrainedForeignId('crm_contact_id');
        });
    }
};
