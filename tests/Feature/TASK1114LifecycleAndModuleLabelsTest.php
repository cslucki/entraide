<?php

namespace Tests\Feature;

use App\Services\UserDataLifecycleRegistry;
use Tests\TestCase;

/**
 * Deux dettes transverses, traitees d'un coup chacune.
 *
 * 1. **`UserDataLifecycleRegistry`** ne classait pas trente cles etrangeres
 *    vers `users` — dont celles du Journal, des Decisions, de Demande-Offre et
 *    de toute la Formation. Son test de garde etait rouge sur `develop` depuis
 *    longtemps, et chaque nouvelle Card l'allongeait d'un cran.
 *
 * 2. **La matrice d'administration des permissions** n'avait de libelle que
 *    pour six modules sur dix-sept : onze affichaient leur cle brute —
 *    `journal`, `decisions`, `marketplace`… — parce que chaque Card ajoutait
 *    son module sans passer par la vue.
 *
 * Les deux avaient la meme cause : un endroit qu'il fallait penser a mettre a
 * jour, et que personne ne mettait a jour. La vue construit desormais sa liste
 * **depuis les modules reellement declares**, et ces tests refusent qu'un
 * module reste sans mot.
 */
class TASK1114LifecycleAndModuleLabelsTest extends TestCase
{
    // ── Le registre du cycle de vie ─────────────────────────────────────────

    public function test_every_policy_used_is_a_declared_one(): void
    {
        // Une politique inventee passerait le test de couverture sans qu'aucun
        // processus ne sache quoi en faire.
        $connues = [
            UserDataLifecycleRegistry::POLICY_TRANSFER,
            UserDataLifecycleRegistry::POLICY_DETACH,
            UserDataLifecycleRegistry::POLICY_DELETE,
            UserDataLifecycleRegistry::POLICY_ANONYMIZE,
            UserDataLifecycleRegistry::POLICY_RETAIN,
            UserDataLifecycleRegistry::POLICY_BLOCK,
        ];

        foreach (UserDataLifecycleRegistry::entries() as $entree) {
            $this->assertContains($entree['policy'], $connues, $entree['key']);
        }
    }

    public function test_every_entry_says_why(): void
    {
        // Une classification sans justification n'est qu'une valeur : le
        // prochain lecteur ne saura pas si elle a ete pesee ou posee au hasard.
        foreach (UserDataLifecycleRegistry::entries() as $entree) {
            $this->assertNotEmpty($entree['justification'] ?? '', $entree['key']);
            $this->assertGreaterThan(20, strlen($entree['justification']), $entree['key'].' : justification trop courte');
        }
    }

    public function test_every_key_is_unique(): void
    {
        $cles = collect(UserDataLifecycleRegistry::entries())->pluck('key');

        $this->assertSame($cles->count(), $cles->unique()->count(), 'deux entrees partagent une cle');
    }

    public function test_the_new_cards_are_classified(): void
    {
        // Les quatre Cards de cette serie, plus la Formation.
        // Toutes les entrees ne sont pas de type `sql` : certaines n'ont ni
        // table ni colonne.
        $paires = collect(UserDataLifecycleRegistry::entries())
            ->filter(fn (array $e) => isset($e['table'], $e['column']))
            ->map(fn (array $e) => $e['table'].'.'.$e['column'])
            ->values();

        foreach ([
            'loop_journal_entries.author_id',
            'loop_decisions.author_id',
            'loop_marketplace_links.added_by',
            'course_submissions.user_id',
            'course_quiz_attempts.user_id',
            'loop_poll_votes.user_id',
        ] as $attendue) {
            $this->assertContains($attendue, $paires, $attendue);
        }
    }

    public function test_what_needs_a_human_decision_is_marked_block(): void
    {
        // **Trois classifications que je n'ai pas prises seul.** Supprimer des
        // votes changerait retroactivement un resultat ; supprimer une copie
        // rendue effacerait le travail d'evaluation d'un tiers ; supprimer une
        // tentative de QCM pourrait rouvrir un parcours deja franchi.
        //
        // `BLOCK` est la façon dont le registre reclame une decision produit.
        $bloquees = collect(UserDataLifecycleRegistry::entries())
            ->where('policy', UserDataLifecycleRegistry::POLICY_BLOCK)
            ->filter(fn (array $e) => isset($e['table'], $e['column']))
            ->map(fn (array $e) => $e['table'].'.'.$e['column'])
            ->values();

        foreach ([
            'loop_poll_votes.user_id',
            'course_submissions.user_id',
            'course_quiz_attempts.user_id',
        ] as $attendue) {
            $this->assertContains($attendue, $bloquees, $attendue);
        }
    }

    // ── Les libelles de modules ─────────────────────────────────────────────

    public function test_every_declared_module_has_a_label_in_both_locales(): void
    {
        // Onze modules sur dix-sept affichaient leur cle brute dans la matrice
        // d'administration.
        $modules = collect(config('loop_permissions.permissions'))->pluck('module')->unique();

        $this->assertGreaterThanOrEqual(17, $modules->count(), 'moins de modules qu’attendu : la config a-t-elle change ?');

        foreach (['fr', 'en'] as $locale) {
            $traductions = require base_path("lang/{$locale}/loops.php");

            foreach ($modules as $module) {
                $this->assertArrayHasKey(
                    'permissions_module_'.$module,
                    $traductions,
                    "{$locale} : le module « {$module} » n’a pas de libelle",
                );
            }
        }
    }

    public function test_the_matrix_builds_its_labels_from_the_declared_modules(): void
    {
        // La liste etait ecrite a la main dans la vue : chaque Card ajoutait un
        // module sans passer par la, et le libelle manquait en silence.
        $vue = file_get_contents(resource_path('views/components/loops/permission-matrix.blade.php'));

        $this->assertStringContainsString("config('loop_permissions.permissions'", $vue);
    }

    public function test_no_module_label_is_left_as_a_raw_key(): void
    {
        // Le repli de la vue rend la cle quand la traduction manque. Aucun
        // module ne doit y arriver.
        foreach (collect(config('loop_permissions.permissions'))->pluck('module')->unique() as $module) {
            $cle = 'loops.permissions_module_'.$module;

            $this->assertNotSame($cle, __($cle), "le module « {$module} » afficherait sa cle brute");
            $this->assertNotSame($module, __($cle), "le module « {$module} » afficherait son identifiant");
        }
    }
}
