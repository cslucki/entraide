<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1760 — « Prestation » devient « Action ».
 *
 * Arbitrage Cyril du 05/11 : « Prestation » fait MARCHAND, on parle d'ACTIONS
 * d'entraide. C'etait la derniere valeur affichee portant le vocabulaire banni
 * par le critere n°1 de la TASK A — et elle avait echappe a tout le chantier
 * parce qu'elle vit dans `member_ai_profile`, hors de l'inventaire §2 du CDC.
 *
 * ## La cle reste `prestation`, et c'est deliberé
 *
 * Elle est STOCKEE dans le `help_types` des profils deja remplis, et sert
 * d'index dans `MemberProfileAgentResponder` et `InlineMemberAgent`. La
 * renommer demanderait une reprise de donnees pour zero gain : l'utilisateur ne
 * voit jamais la cle. Meme doctrine que §3.5 du CDC, qui refuse de renommer les
 * colonnes et les routes.
 */
class TASK1760LexiqueActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le corps de l'email, rendu par la notification.
     *
     * La base de test ne contient aucun gabarit administrable, donc
     * `resolveTemplate()` ne trouve rien et c'est le REPLI Blade qui est rendu —
     * exactement ce que ces tests mesurent.
     */
    private function rendu(\App\Models\Transaction $echange, \App\Models\User $destinataire): string
    {
        // ⚠️ Le REPLI doit etre FORCE, et c'est une lecon : la base de test est
        // SEMEE (28 gabarits), donc `transaction_status_changed` existe et gagne.
        // Ma premiere version de ce test rendait donc le gabarit de BASE tout en
        // pretendant mesurer le repli — il cherchait « 30 min » dans un texte
        // qui ne pouvait pas en contenir.
        \Illuminate\Support\Facades\DB::table('system_email_templates')
            ->where('slug', 'transaction_status_changed')
            ->delete();

        return (string) (new \App\Notifications\TransactionStatusChanged($echange))
            ->toMail($destinataire)
            ->render();
    }

    /**
     * 🔴 LA GARDE QUI REND LES AUTRES TESTS HONNETES.
     *
     * Si un jour `resolveTemplate()` cessait de trouver le gabarit de base, les
     * tests du repli passeraient toujours — mais ils ne mesureraient plus un
     * REPLI, ils mesureraient le chemin normal. Ce test fixe la premisse :
     * **le gabarit de base existe et gagne**.
     */
    public function test_le_gabarit_de_base_est_bien_celui_qui_part_normalement(): void
    {
        $existe = \Illuminate\Support\Facades\DB::table('system_email_templates')
            ->where('slug', 'transaction_status_changed')->count();

        $this->assertGreaterThan(0, $existe,
            'Le gabarit administrable doit exister : c\'est lui qui part, le Blade n\'est qu\'un repli.');
    }

    /** Le libelle affiche, dans les DEUX langues. */
    public function test_le_type_d_aide_se_dit_action_et_non_prestation(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            $options = __('member_ai_profile.help_type_options');

            $this->assertIsArray($options, "Le catalogue doit exister en « {$locale} ».");
            $this->assertArrayHasKey('prestation', $options, 'La CLE ne change pas : elle est stockee en base.');
            $this->assertSame('Action', $options['prestation'], "Libelle attendu en « {$locale} ».");
        }
    }

    /**
     * 🔴 Et plus aucune VALEUR du vocabulaire marchand dans les fichiers de
     * langue — la reciproque, qui transforme ce test en garde.
     *
     * On mesure les valeurs, pas les cles : une cle n'est jamais affichee, et
     * les surfaces d'administration gardent le vocabulaire technique (critere
     * n°1 de la TASK A l'autorise explicitement).
     */
    public function test_aucun_fichier_de_langue_n_affiche_plus_le_vocabulaire_marchand(): void
    {
        $fautifs = [];

        foreach (['fr', 'en'] as $locale) {
            foreach (glob(lang_path($locale.'/*.php')) as $fichier) {
                // `admin.php` EST le lexique d'administration : le critere n°1
                // de la TASK A autorise explicitement le vocabulaire technique
                // sur ces surfaces (« Échanges en tant qu'acheteur » y est
                // juste, et plus clair qu'une periphrase).
                if (basename($fichier) === 'admin.php') {
                    continue;
                }

                $contenu = file_get_contents($fichier);

                // On capture la CLE et la VALEUR : les cles d'administration
                // vivent aussi dans des fichiers mixtes — `navigation.php`
                // porte la navigation membre ET les libelles `org_admin_*`.
                // Exclure le fichier entier aurait aveugle la garde sur ses
                // cles membre.
                preg_match_all("/'([a-z0-9_.]+)'\s*=>\s*'([^']*)'/i", $contenu, $m, PREG_SET_ORDER);

                foreach ($m as [, $cle, $valeur]) {
                    if (preg_match('/^(org_)?admin/i', $cle)) {
                        continue;
                    }

                    if (preg_match('/\b(prestataire|prestation|acheteur|vendeur)\b/i', $valeur)) {
                        $fautifs[] = basename($fichier).' : '.$cle.' = '.$valeur;
                    }
                }
            }
        }

        $this->assertSame([], $fautifs,
            "Vocabulaire marchand encore affiche :\n".implode("\n", $fautifs));
    }
    // ── Correctif 2 : le repli de l'email de statut ──────────────────────────

    /**
     * 🔴 LE REPLI PARLE LES DEUX LANGUES, et ne dit plus « acheteur ».
     *
     * Le gabarit administrable en base reste le chemin normal ; celui-ci ne part
     * que s'il est desactive. Mais il etait **entierement** en francais code en
     * dur — pas un `__()` — et disait « L'acheteur a declare la prestation
     * terminee ». Un membre anglophone recevait du francais, avec deux mots
     * bannis dedans.
     */
    public function test_le_repli_de_l_email_de_statut_est_traduit_et_sans_vocabulaire_marchand(): void
    {
        $org = Organization::factory()->create(['slug' => 'org-1760', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $org);

        $aidant = User::factory()->create(['organization_id' => $org->id, 'first_name' => 'Ottoline']);
        $aide = User::factory()->create(['organization_id' => $org->id, 'first_name' => 'Barnabe']);

        $annonce = Service::factory()->create([
            'user_id' => $aidant->id,
            'organization_id' => $org->id,
            'category_id' => Category::factory()->create(['organization_id' => $org->id])->id,
            'status' => 'active',
            'points_cost' => 30,
        ]);

        $echange = Transaction::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $annonce->id,
            'seller_id' => $aidant->id,
            'buyer_id' => $aide->id,
            'status' => 'buyer_done',
            'points_proposed' => 30,
            'points_agreed' => 30,
        ]);

        foreach (['fr' => 'Ottoline', 'en' => 'Ottoline'] as $locale => $_) {
            app()->setLocale($locale);

            // Rendu par la NOTIFICATION et non par `view()` : le namespace
            // `mail::` n'est enregistre que sur ce chemin, et surtout c'est le
            // VRAI chemin — la base de test n'a aucun gabarit administrable,
            // donc le repli est bien celui qui est exerce.
            $html = $this->rendu($echange, $aidant);

            foreach (['acheteur', 'vendeur', 'prestation'] as $banni) {
                $this->assertStringNotContainsStringIgnoringCase($banni, $html,
                    "Le repli dit encore « {$banni} » en « {$locale} ».");
            }

            $this->assertStringContainsString('30 min', $html,
                "Le temps partage doit apparaitre en « {$locale} » (§9.1).");
        }

        // 🔴 LA RECIPROQUE qui prouve la traduction : les deux rendus DIFFERENT.
        app()->setLocale('fr');
        $fr = $this->rendu($echange, $aidant);
        app()->setLocale('en');
        $en = $this->rendu($echange, $aidant);

        $this->assertNotSame($fr, $en,
            'Les deux langues rendent le MEME texte : le gabarit n\'est pas traduit.');
        $this->assertStringContainsString('Hello', $en);
        $this->assertStringContainsString('Bonjour', $fr);
    }

    /**
     * La phrase du repli est ORIENTEE ROLE, comme dans l'application.
     *
     * Elle vient de `CarteEntraide::phrase()` — la definition canonique de
     * T1755 — et non de nouveaux libelles. L'aidant et l'aide ne lisent donc
     * pas la meme chose, ce que l'ancienne phrase neutre (« L'acheteur a
     * declare… ») ne pouvait pas faire.
     */
    public function test_le_repli_dit_une_phrase_differente_a_l_aidant_et_a_l_aide(): void
    {
        $org = Organization::factory()->create(['slug' => 'org-1760b', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $org);
        app()->setLocale('fr');

        $aidant = User::factory()->create(['organization_id' => $org->id, 'first_name' => 'Ottoline']);
        $aide = User::factory()->create(['organization_id' => $org->id, 'first_name' => 'Barnabe']);

        $annonce = Service::factory()->create([
            'user_id' => $aidant->id,
            'organization_id' => $org->id,
            'category_id' => Category::factory()->create(['organization_id' => $org->id])->id,
            'status' => 'active',
            'points_cost' => 30,
        ]);

        $echange = Transaction::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $annonce->id,
            'seller_id' => $aidant->id,
            'buyer_id' => $aide->id,
            'status' => 'buyer_done',
            'points_proposed' => 30,
            'points_agreed' => 30,
        ]);

        $vuParLAidant = $this->rendu($echange, $aidant);
        $vuParLAide = $this->rendu($echange, $aide);

        $this->assertStringContainsString('Barnabe', $vuParLAidant, 'L\'aidant lit le prenom de l\'autre.');
        $this->assertStringContainsString('Ottoline', $vuParLAide, 'L\'aide lit le prenom de l\'autre.');
        $this->assertNotSame($vuParLAidant, $vuParLAide, 'La phrase doit etre orientee role.');
    }
}
