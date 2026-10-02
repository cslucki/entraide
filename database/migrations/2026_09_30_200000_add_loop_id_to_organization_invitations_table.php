<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            // TASK-1659 — Boucle CIBLE, optionnelle : la personne y est
            // ajoutee a l'acceptation, et y atterrit une fois son mot de
            // passe pose. Quand la Boucle est privee, l'invitation vaut
            // AUTORISATION d'y entrer — c'est un SuperAdmin qui l'a
            // designee.
            //
            // `nullOnDelete` : une Boucle supprimee ne doit pas emporter la
            // trace de l'invitation ni le compte qu'elle a cree. L'invitation
            // redevient simplement « sans Boucle cible ».
            $table->foreignUuid('loop_id')->nullable()->after('organization_id')
                ->constrained('loops')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loop_id');
        });
    }
};
