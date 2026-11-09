<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminSystemEmailTemplatesController as Ecran;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1795 correctif 10 — `/admin/system-email-templates`.
 *
 * Cinq demandes de Cyril sur un meme ecran : defaut « Toutes les organisations »
 * et locale « FR », page responsive, colonne « Nom » APRES « Sujet », colonne
 * « LOCALE » supprimee, et « corriger la mention qui semble non traduite
 * {0}Aucun gabarit affiché|{1}1 gabarit affiché|[2,*]40 gabarits affichés ».
 *
 * ## La mention n'etait pas « non traduite »
 *
 * Le fichier de langue etait JUSTE. La cle est une chaine de PLURALISATION, et
 * la vue la rendait par `__()`, qui ne resout pas la syntaxe `{0}…|{1}…|[2,*]…`
 * — elle rendait donc les trois branches collees. Mesure du 09/11/2026 :
 * `__()` -> « {0}Aucun gabarit affiché|… » ; `trans_choice()` -> « 40 gabarits
 * affichés ».
 */
class TASK1795SystemEmailTemplatesViewTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create([
            'slug' => 'org-1795j',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $org->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function gabarit(string $slug, string $locale, string $nom, string $sujet): SystemEmailTemplate
    {
        return SystemEmailTemplate::query()->create([
            'slug' => $slug,
            'locale' => $locale,
            'organization_id' => null,
            'name' => $nom,
            'subject' => $sujet,
            'content_html' => '<p>corps</p>',
            'variables' => [],
            'enabled' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // Le pluriel
    // ---------------------------------------------------------------------

    /**
     * La table n'est PAS vide au depart.
     *
     * Plusieurs migrations inserent des gabarits (`add_*_email_template`), et
     * `RefreshDatabase` les rejoue : 20 lignes existent avant la premiere
     * ligne de ce test. Une premiere version assertait « 2 gabarits affichés »
     * sur une page qui en annoncait 22 — et elle PASSAIT, « 2 templates shown »
     * etant une sous-chaine de « 22 templates shown ». Un faux vert.
     *
     * On vide donc la table pour maitriser le compte, et on compare le
     * compteur RENDU a sa valeur exacte, attribut compris.
     */
    private function tableVide(): void
    {
        SystemEmailTemplate::query()->delete();
    }

    /** Le compteur rendu, lu sur son attribut et sur son texte. */
    private function compteurRendu(string $html): array
    {
        preg_match('#data-system-email-count-shown="(\d+)"[^>]*>(.*?)</span>#s', $html, $m);

        return [
            'valeur' => isset($m[1]) ? (int) $m[1] : null,
            'texte' => isset($m[2]) ? trim(strip_tags($m[2])) : null,
        ];
    }

    public function test_le_compteur_resout_la_pluralisation_au_lieu_de_la_rendre_brute(): void
    {
        $this->tableVide();
        $this->gabarit('a', 'fr', 'Gabarit A', 'Sujet A');
        $this->gabarit('b', 'fr', 'Gabarit B', 'Sujet B');

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates'))
            ->assertOk();

        $compteur = $this->compteurRendu($reponse->getContent());

        $this->assertSame(2, $compteur['valeur']);
        // La phrase attendue est demandee au TRADUCTEUR, pas reecrite ici : la
        // suite tourne en `en`, et coder le francais en dur ferait rougir un
        // test qui mesure la PLURALISATION, pas la langue du banc.
        $this->assertSame(
            trans_choice('admin.system_email_count_shown', 2, ['count' => 2]),
            $compteur['texte'],
        );

        // La marque du defaut : les separateurs de branches dans la page.
        $reponse->assertDontSee('{0}', false);
        $reponse->assertDontSee('[2,*]', false);
    }

    public function test_le_compteur_accorde_le_singulier_et_le_zero(): void
    {
        $this->tableVide();
        $this->gabarit('a', 'fr', 'Gabarit A', 'Sujet A');

        $singulier = $this->compteurRendu(
            $this->actingAs($this->superAdmin)
                ->get(route('admin.system-email-templates'))
                ->assertOk()
                ->getContent()
        );

        $this->assertSame(1, $singulier['valeur']);
        $this->assertSame(
            trans_choice('admin.system_email_count_shown', 1, ['count' => 1]),
            $singulier['texte'],
        );

        // Aucun gabarit dans cette langue : la branche {0}.
        $zero = $this->compteurRendu(
            $this->actingAs($this->superAdmin)
                ->get(route('admin.system-email-templates', ['locale' => 'en']))
                ->assertOk()
                ->getContent()
        );

        $this->assertSame(0, $zero['valeur']);
        $this->assertSame(
            trans_choice('admin.system_email_count_shown', 0, ['count' => 0]),
            $zero['texte'],
        );

        // Et les trois branches rendent bien TROIS phrases differentes : sans
        // cela, les assertions ci-dessus pourraient passer sur une constante.
        $this->assertCount(3, array_unique([
            trans_choice('admin.system_email_count_shown', 0, ['count' => 0]),
            trans_choice('admin.system_email_count_shown', 1, ['count' => 1]),
            trans_choice('admin.system_email_count_shown', 2, ['count' => 2]),
        ]));
    }

    // ---------------------------------------------------------------------
    // Les defauts de la vue
    // ---------------------------------------------------------------------

    public function test_l_ecran_s_ouvre_sur_le_francais(): void
    {
        $this->gabarit('a', 'fr', 'Gabarit FR', 'Sujet FR');
        $this->gabarit('a', 'en', 'Template EN', 'Subject EN');

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates'))
            ->assertOk();

        $this->assertSame('fr', $reponse->viewData('localeSelectionnee'));
        $this->assertSame(
            ['fr'],
            $reponse->viewData('templates')->pluck('locale')->unique()->values()->all(),
        );
    }

    public function test_l_ecran_s_ouvre_sur_toutes_les_organisations(): void
    {
        $autre = Organization::factory()->create(['slug' => 'autre-1795j', 'is_active' => true]);

        $global = $this->gabarit('a', 'fr', 'Gabarit global', 'Sujet global');
        $tenant = $this->gabarit('b', 'fr', 'Gabarit tenant', 'Sujet tenant');
        $tenant->forceFill(['organization_id' => $autre->id])->save();

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates'))
            ->assertOk();

        // Les deux portees sont la : aucun filtre d'Organization n'est pose.
        $ids = $reponse->viewData('templates')->pluck('id')->all();
        $this->assertContains($global->id, $ids);
        $this->assertContains($tenant->id, $ids);
    }

    /**
     * « Toutes les langues » reste ATTEIGNABLE malgre le defaut.
     *
     * C'est le piege de ce correctif : l'option poste `locale=` — presente mais
     * VIDE. Une garde ecrite avec `filled()` l'aurait confondue avec une absence
     * et serait retombee sur `fr`, laissant une option qui ne peut rien faire.
     */
    public function test_toutes_les_langues_reste_atteignable(): void
    {
        $this->gabarit('a', 'fr', 'Gabarit FR', 'Sujet FR');
        $this->gabarit('a', 'en', 'Template EN', 'Subject EN');

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['locale' => '']))
            ->assertOk();

        $this->assertSame('', $reponse->viewData('localeSelectionnee'));
        $this->assertSame(
            ['en', 'fr'],
            $reponse->viewData('templates')->pluck('locale')->unique()->sort()->values()->all(),
        );
    }

    public function test_une_langue_explicite_gouverne_toujours(): void
    {
        $this->gabarit('a', 'fr', 'Gabarit FR', 'Sujet FR');
        $this->gabarit('a', 'en', 'Template EN', 'Subject EN');

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['locale' => 'en']))
            ->assertOk();

        $this->assertSame('en', $reponse->viewData('localeSelectionnee'));
        $this->assertSame(
            ['en'],
            $reponse->viewData('templates')->pluck('locale')->unique()->values()->all(),
        );
        $this->assertSame(Ecran::LOCALE_PAR_DEFAUT, 'fr');
    }

    // ---------------------------------------------------------------------
    // Les colonnes
    // ---------------------------------------------------------------------

    public function test_la_colonne_nom_vient_apres_la_colonne_sujet(): void
    {
        $this->gabarit('a', 'fr', 'Gabarit A', 'Sujet A');

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates'))
            ->assertOk();

        // L'ORDRE, pas seulement la presence : c'est la demande.
        $reponse->assertSeeInOrder([
            __('admin.common_subject'),
            __('admin.common_name'),
        ]);
    }

    public function test_la_colonne_locale_a_disparu_de_l_en_tete(): void
    {
        $this->gabarit('a', 'fr', 'Gabarit A', 'Sujet A');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates'))
            ->assertOk()
            ->getContent();

        preg_match('#<thead.*?</thead>#s', $html, $entete);
        $this->assertNotEmpty($entete, 'en-tete de tableau introuvable');

        // Cinq colonnes, et plus aucune nommee « Langue ».
        $this->assertSame(5, preg_match_all('#<th[\s>]#', $entete[0]));
        $this->assertStringNotContainsString(__('admin.locale'), $entete[0]);
    }

    /**
     * La langue reste lisible QUAND elle departage, et seulement alors.
     *
     * Retirer la colonne sans rien d'autre aurait rendu deux lignes du meme
     * gabarit indiscernables des que le filtre autorise plusieurs langues.
     */
    public function test_la_langue_apparait_en_pastille_seulement_si_le_filtre_en_melange_plusieurs(): void
    {
        $this->gabarit('a', 'fr', 'Gabarit FR', 'Sujet partage');
        $this->gabarit('a', 'en', 'Template EN', 'Sujet partage');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates'))
            ->assertOk()
            ->assertDontSee('data-template-locale', false);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['locale' => '']))
            ->assertOk()
            ->assertSee('data-template-locale="fr"', false)
            ->assertSee('data-template-locale="en"', false);
    }
}
