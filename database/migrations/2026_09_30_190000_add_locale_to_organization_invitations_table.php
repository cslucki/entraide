<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            // The language of the invitation e-mail, chosen by the SuperAdmin
            // at creation time. Carried by the invitation rather than derived
            // from the Organization: the same Organization can legitimately
            // welcome people who read different languages.
            $table->string('locale', 5)->default('fr')->after('recipient_email');
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
