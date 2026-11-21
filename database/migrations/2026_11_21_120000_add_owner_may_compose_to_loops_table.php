<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1872 correctif 07 — deleguer la composition BOUCLE PAR BOUCLE.
 *
 * ## Le constat
 *
 * Cyril, 21/11 : « actuellement uniquement l'administrateur de l'organisation
 * peut modifier les outils de la boucle. Il faudrait que le proprietaire
 * d'une BouclePro fasse ca aussi. »
 *
 * Diagnostic : ni le menu, ni `$peutOutils`, ni un droit serveur.
 * `LoopPresetConfigurator::canConfigure()` laissait DEJA passer le
 * proprietaire — a condition que son Organization l'autorise par
 * `loop_composition_policy`. Or cette colonne vaut `'locked'` par defaut, les
 * dix Organizations locales y sont, et **aucune vue ne poste vers l'endpoint
 * qui l'ecrit**. La fonctionnalite etait morte-nee, pas absente.
 *
 * ## Pourquoi une colonne sur `loops` plutot qu'un basculement en masse
 *
 * La premiere version de ce correctif inversait le defaut de l'Organization
 * et mettait a jour les dix lignes existantes. Cyril a prefere un
 * INTERRUPTEUR, et par BOUCLE — c'est le grain le plus fin, et surtout le
 * seul qui ne decide rien a la place de personne : aucune donnee existante
 * n'est reecrite ici, la migration est purement additive.
 *
 * ## Trois etats, pas deux — et le `null` est le plus important
 *
 *   `null`   la Boucle HERITE du reglage de son Organization. C'est le
 *            defaut, et c'est pour cela que cette migration ne change le
 *            comportement de personne tant qu'un administrateur n'a pas
 *            bascule l'interrupteur ;
 *   `true`   le proprietaire de CETTE Boucle compose, quelle que soit la
 *            politique de l'Organization ;
 *   `false`  il ne compose pas, meme si l'Organization l'autorise ailleurs.
 *
 * Un booleen NON nullable n'aurait pas su dire « rien decide » : il aurait
 * fallu choisir une valeur pour les milliers de Boucles existantes, c'est-a-dire
 * reintroduire exactement le basculement en masse qu'on vient d'ecarter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loops', function (Blueprint $table) {
            $table->boolean('owner_may_compose')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('loops', function (Blueprint $table) {
            $table->dropColumn('owner_may_compose');
        });
    }
};
