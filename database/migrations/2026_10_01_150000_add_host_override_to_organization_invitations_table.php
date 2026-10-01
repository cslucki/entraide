<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            // TASK-1659 — « Host de test », optionnel et reserve aux
            // environnements local/testing : l'origin publiquement joignable
            // (tunnel) qui remplace la base de l'URL d'invitation, pour qu'un
            // lien recu sur un vrai telephone atteigne la machine de dev.
            //
            // Stocke sur l'INVITATION, et pas lu dans le formulaire au moment
            // de l'envoi : une relance doit reproduire le meme type de lien,
            // et l'historique doit rester lisible des mois plus tard.
            //
            // Seulement l'origin (`https://host[:port]`), jamais un path —
            // le chemin et le jeton restent generes par BouclePro.
            $table->string('host_override', 255)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropColumn('host_override');
        });
    }
};
