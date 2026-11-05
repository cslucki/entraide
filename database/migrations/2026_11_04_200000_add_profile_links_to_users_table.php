<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1750 — plusieurs liens web sur le profil.
 *
 * ## Pourquoi une colonne JSON, et non une table
 *
 * Les liens vivaient dans DEUX colonnes de chaine fixes — `website` et
 * `linkedin_url` — ou une liste de longueur variable n'entre pas. Et aucune
 * colonne JSON n'existait sur `users` pour la loger : sur ses 36 colonnes, la
 * seule en texte libre est `bio`, la biographie.
 *
 * Une table `user_links` aurait ouvert un arbitrage multi-tenant (quelle
 * `organization_id` ?), une policy, un seeder et un ordre explicite — pour
 * cinq liens. Ici la ligne est deja celle du membre, donc deja celle de son
 * locataire : il n'y a rien a trancher.
 *
 * ## Additive et reversible
 *
 * `nullable`, sans valeur par defaut : les 41 comptes existants ne sont pas
 * touches et aucune reprise de donnees n'est necessaire. `website` et
 * `linkedin_url` sont LAISSES EN PLACE — ils restent les deux champs mis en
 * avant, et rien de ce qui les lit ne change.
 *
 * `down()` ne retire que cette colonne. C'est un vrai retour en arriere : la
 * seule perte est la liste elle-meme, qui n'existait pas avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'profile_links')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('profile_links')->nullable()->after('linkedin_url');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'profile_links')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_links');
        });
    }
};
