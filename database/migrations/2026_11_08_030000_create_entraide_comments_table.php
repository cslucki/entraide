<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1786 — les commentaires deposes sous une proposition de service ou une
 * demande d'aide.
 *
 * ## Une seule table pour les DEUX types d'annonce
 *
 * Le depot porte deja deux tables de commentaires mono-type (`blog_comments`,
 * `feed_post_comments`). En ajouter DEUX de plus, une par type d'annonce, aurait
 * duplique la meme colonne sept fois pour rien.
 *
 * Le polymorphisme n'est pas une nouveaute introduite ici : `Service::reports()`
 * est deja un `morphMany` sur `reportable`. `uuidMorphs('commentable')` pose
 * `commentable_type` + `commentable_id` et leur index composite, qui est
 * exactement l'acces dont la fiche a besoin.
 *
 * ## `organization_id` est NOT NULL, et c'est une lecon du depot
 *
 * `blog_comments` a recu sa colonne `organization_id` APRES coup, **nullable**,
 * et le code ne la remplissait pas : il a fallu une migration de backfill
 * (`2026_06_14_203254_backfill_organization_id_on_legacy_likes_and_blog_comments`)
 * pour reparer des lignes nees sans tenant.
 *
 * Cette table nait avec la colonne, donc elle nait NOT NULL. Une frontiere de
 * tenant qui admet `NULL` n'est pas une frontiere — elle laisse passer toute
 * ligne ecrite par un chemin distrait.
 *
 * ## Pourquoi AUCUNE cle etrangere sur l'annonce
 *
 * Une relation polymorphique ne peut pas en porter : `commentable_id` vise deux
 * tables. La disparition d'une annonce est donc traitee dans le code
 * (`Service`/`ServiceRequest`), et non par la base. C'est une contrainte de la
 * forme choisie, pas un oubli — et elle est dite ici pour qu'on ne cherche pas
 * la contrainte manquante.
 *
 * Additive et non destructive : une seule table creee, aucune table existante
 * touchee. Les tables historiques des transactions ne sont pas effleurees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entraide_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // NOT NULL volontairement : voir le docbloc.
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // `commentable_type` + `commentable_id` + index composite.
            $table->uuidMorphs('commentable');

            // NULLABLE et `nullOnDelete`, et c'est le PRECEDENT qui le dicte.
            //
            // `blog_comments.user_id` et `feed_post_comments.user_id` sont tous
            // deux nullable en SET NULL (poses ainsi par
            // `2026_09_24_213000_user_lifecycle_set_null_foreign_keys`), et
            // `UserDataLifecycleRegistry` classe l'auteur d'un commentaire en
            // **ANONYMIZE** : « comment authorship is never transferred;
            // retained content needs anonymized attribution ».
            //
            // J'avais ecrit `cascadeOnDelete()`, donc le commentaire DISPARAISSAIT
            // a la suppression du compte — ce qui contredit la politique et la
            // vue, qui rend deja `__('profile.deactivated_user')` sur un auteur
            // nul. Defaut trouve par `UserDataLifecycleRegistryTest`, qui exige
            // que toute FK vers `users` soit declaree.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('content');

            // Meme forme que `blog_comments` : un commentaire masque par la
            // moderation reste en base, il cesse seulement d'etre rendu.
            $table->boolean('is_approved')->default(true);

            // Les informations de MODERATION demandees au CDC §3.
            //
            // Elles distinguent deux gestes que `deleted_at` seul confondrait :
            // l'auteur qui retire son propre commentaire, et un SuperAdmin qui
            // le moderne. Sans cette distinction, aucune trace ne dit lequel
            // des deux s'est produit — et c'est precisement la question qu'on
            // se pose apres coup.
            $table->foreignUuid('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // L'acces de la fiche : tous les commentaires approuves d'une
            // annonce, dans l'ordre chronologique. L'index de `uuidMorphs` ne
            // couvre pas `organization_id`, qui est reinterroge a chaque lecture
            // comme frontiere de tenant.
            $table->index(['organization_id', 'commentable_type', 'commentable_id'], 'entraide_comments_tenant_cible_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entraide_comments');
    }
};
