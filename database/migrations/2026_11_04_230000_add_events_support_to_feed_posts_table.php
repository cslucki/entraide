<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1752 — ce qu'il manque a `feed_posts` pour porter des EVENEMENTS.
 *
 * LA migration de la TASK. La condition d'arret posee a l'ouverture dit qu'une
 * SECONDE migration oblige a revenir vers MASTER : tout ce que le CDC a
 * identifie entre donc ici.
 *
 * ## 1. L'audience — la seule chose vraiment obligatoire
 *
 * Le Flux n'avait **aucune** notion de visibilite restreinte.
 * `scopeVisibleInFeed()` ne filtrait que `status = published`.
 *
 * Piege de lecture signale par le CDC : `FeedPost::loops()` ressemble a une
 * audience, mais n'en est pas une. Cette relation n'apparait dans **aucune**
 * requete de lecture ; son unique usage est `broadcastToAssociatedLoops()`,
 * qui RECOPIE l'annonce en message ChatLoop. C'est de la diffusion sortante.
 *
 * La forme retenue est celle de `loop_events`, qui porte **deja** exactement
 * la distinction demandee : `visibility` + `loop_id`. Transposer un modele
 * eprouve plutot qu'en inventer un.
 *
 * ## 2. La reference d'objet — ce qui rend l'idempotence STRUCTURELLE
 *
 * Sans `object_type`/`object_id`, quatre choses sont impossibles : garantir
 * qu'on n'annonce la presentation de quelqu'un qu'une fois, faire suivre une
 * promotion de visibilite d'un Evenement, offrir un lien honnete, nettoyer.
 *
 * Et l'unicite doit etre une contrainte de BASE, pas une convention. Le
 * docblock de `MemberNotification` l'a deja paye : « un `create()` direct
 * suffisait a tout contourner. Une convention n'est pas une frontiere. »
 *
 * ## 3. `content` devient `nullable`
 *
 * Le libelle d'un evenement est CALCULE a l'affichage — sinon on gele la
 * langue (produit FR+EN) et le nom (un changement de nom, une desactivation,
 * et la phrase devient fausse). Il n'y a donc rien a ecrire dans `content`.
 *
 * Y mettre un repli non affiche serait pire : une colonne qui contient un
 * texte que personne ne lit finit par etre affichee par accident.
 *
 * ## 4. L'interrupteur par Organization (§8.5)
 *
 * `feed_auto_events_enabled`, defaut **true**. Arbitrage Cyril : il existe
 * pour pouvoir ETEINDRE, pas pour attendre qu'on l'allume. Les evenements
 * automatiques changent la nature de l'ecran pour toutes les Organizations
 * d'un coup ; ce drapeau rend le retour en arriere possible **sans
 * deploiement**.
 *
 * Il vit dans CETTE migration et non dans une seconde : la condition d'arret
 * de la TASK interdit une seconde migration, et une migration peut
 * legitimement toucher deux tables du meme chantier.
 *
 * ## Pas de contrainte CHECK, et c'est delibere
 *
 * Les valeurs legales de `type` et de `visibility` sont tenues par un registre
 * applicatif fail-closed. Une `CHECK` aurait ete tentante, mais **SQLite ne
 * les applique pas** : tout litteral invente dans un test passerait en local
 * et rougirait en CI PostgreSQL (lecon payee en T1749). Le registre, lui,
 * refuse sur les deux moteurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('feed_posts', 'object_type')) {
                $table->string('object_type')->nullable()->after('type');
            }

            if (! Schema::hasColumn('feed_posts', 'object_id')) {
                $table->uuid('object_id')->nullable()->after('object_type');
            }

            if (! Schema::hasColumn('feed_posts', 'visibility')) {
                $table->string('visibility')->default('organization')->after('status');
            }

            if (! Schema::hasColumn('feed_posts', 'loop_id')) {
                $table->foreignUuid('loop_id')->nullable()->after('visibility')
                    ->constrained('loops')->nullOnDelete();
            }
        });

        // `content` devient facultatif. Les annonces humaines continuent de le
        // remplir — rien ne change pour elles.
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->text('content')->nullable()->change();
        });

        // L'IDEMPOTENCE, en contrainte de base.
        //
        // Portee sur les quatre colonnes ensemble : deux evenements de types
        // differents peuvent viser le meme objet (une Boucle creee puis
        // rejointe), et les annonces humaines — `object_type` et `object_id`
        // a NULL — ne sont pas contraintes entre elles : PostgreSQL comme
        // SQLite considerent deux NULL comme distincts dans un index unique.
        // C'est exactement le comportement voulu.
        $nom = 'feed_posts_event_unique';

        if (! $this->indexExiste($nom)) {
            Schema::table('feed_posts', function (Blueprint $table) use ($nom) {
                $table->unique(['organization_id', 'type', 'object_type', 'object_id'], $nom);
            });
        }

        if (! Schema::hasColumn('organizations', 'feed_auto_events_enabled')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->boolean('feed_auto_events_enabled')->default(true);
            });
        }

        $index = 'feed_posts_org_visibility_published_index';

        if (! $this->indexExiste($index)) {
            Schema::table('feed_posts', function (Blueprint $table) use ($index) {
                $table->index(['organization_id', 'visibility', 'published_at'], $index);
            });
        }
    }

    public function down(): void
    {
        $nom = 'feed_posts_event_unique';
        $index = 'feed_posts_org_visibility_published_index';

        Schema::table('feed_posts', function (Blueprint $table) use ($nom, $index) {
            if ($this->indexExiste($nom)) {
                $table->dropUnique($nom);
            }

            if ($this->indexExiste($index)) {
                $table->dropIndex($index);
            }
        });

        if (Schema::hasColumn('organizations', 'feed_auto_events_enabled')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('feed_auto_events_enabled');
            });
        }

        Schema::table('feed_posts', function (Blueprint $table) {
            if (Schema::hasColumn('feed_posts', 'loop_id')) {
                $table->dropConstrainedForeignId('loop_id');
            }
        });

        Schema::table('feed_posts', function (Blueprint $table) {
            foreach (['object_type', 'object_id', 'visibility'] as $colonne) {
                if (Schema::hasColumn('feed_posts', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });

        // `content` REDEVIENT obligatoire — mais seulement si plus aucune
        // ligne n'est nulle, sinon le retour arriere echouerait sur la donnee.
        // Les lignes d'evenement sont justement celles qui n'en ont pas : on
        // les retire d'abord, elles n'existaient pas avant cette TASK.
        DB::table('feed_posts')->whereNull('content')->delete();

        Schema::table('feed_posts', function (Blueprint $table) {
            $table->text('content')->nullable(false)->change();
        });
    }

    private function indexExiste(string $nom): bool
    {
        return collect(Schema::getIndexes('feed_posts'))
            ->contains(fn (array $index): bool => $index['name'] === $nom);
    }
};
