<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * « Mes documents » — une vraie racine personnelle (TASK-1130, decision finale).
 *
 * Jusqu'ici un utilisateur pouvait porter autant de racines personnelles que de
 * clics sur « Creer un dossier » : `owner_id` n'etait qu'indexe, jamais unique.
 * L'ecran devait donc afficher un catalogue de racines avant tout contenu, et
 * un fichier depose « en haut » n'avait aucune destination possible.
 *
 * `system_role` nomme les lignes que le produit possede plutot que
 * l'utilisateur. Une seule valeur aujourd'hui, `personal_documents`.
 *
 * ## Ce que cette migration ne fait PAS
 *
 * - Elle ne touche pas `dossiers_holder_xor` : une racine personnelle reste
 *   `parent_id IS NULL, owner_id = <user>, loop_id IS NULL`, ce que la
 *   contrainte accepte deja. La racine systeme est une racine ordinaire a qui
 *   on donne un nom de role.
 * - Elle ne fait **aucun backfill** : aucune racine existante n'est renommee,
 *   deplacee, nestee ni marquee. Les CAS B (`shared_with_loop_id`) restent
 *   intacts — c'est precisement ce qui avait fait echouer la piste « ranger les
 *   racines existantes sous une racine systeme » (audit du 12/08).
 * - Elle ne cree aucune ligne : la racine nait a la premiere visite du module,
 *   par `PersonalDocumentsRoot::resolve()`.
 *
 * ## L'invariant
 *
 * Index unique **partiel** : une seule racine `personal_documents` par couple
 * (Organization, utilisateur), et seulement parmi les lignes vivantes — une
 * racine soft-supprimee ne doit pas empecher d'en recreer une.
 *
 * PostgreSQL et SQLite acceptent tous deux `CREATE UNIQUE INDEX ... WHERE`,
 * donc l'invariant existe sur le runtime de reference **et** sous les tests.
 * C'est l'index qui garantit l'unicite, pas la discipline des appelants —
 * meme raisonnement que `unique('loop_id')` pour la racine d'une Boucle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->string('system_role')->nullable()->after('name');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dossiers_personal_documents_unique
            ON dossiers (organization_id, owner_id)
            WHERE system_role = 'personal_documents' AND deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS dossiers_personal_documents_unique');

        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropColumn('system_role');
        });
    }
};
