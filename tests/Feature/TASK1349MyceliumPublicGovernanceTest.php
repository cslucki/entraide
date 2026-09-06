<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiConstitution;
use App\Models\OrganizationAiDoctrine;
use App\Models\PlatformAiConstitution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1349 — Mycelium et transparence publique de la gouvernance IA.
 *
 * Preuves :
 *  A. PUBLIC — `/mycelium` est lisible sans authentification et montre le
 *     texte REELLEMENT compose.
 *  B. PRIVACY — ni doctrine, ni prompt, ni auteur, ni historique, ni reglage
 *     n'atteint jamais une surface publique.
 *  C. OPT-IN — prive par DEFAUT ; publique seulement sur choix explicite ET
 *     avec une version active.
 *  D. TENANT — la Constitution de A n'est jamais lisible via B.
 *  E. PERMISSIONS — le Mycelium n'appartient qu'au Super Admin.
 *  F. VOCABULAIRE — en francais, « organisation », jamais « Organization ».
 */
class TASK1349MyceliumPublicGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $adminA;

    private User $adminB;

    private User $memberA;

    private Organization $organizationA;

    private Organization $organizationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
        $this->adminA = User::factory()->create();
        $this->adminB = User::factory()->create();

        $this->organizationA = Organization::factory()->create(['is_active' => true, 'slug' => 'myc-a', 'name' => 'Mycelium A', 'admin_id' => $this->adminA->id]);
        $this->organizationB = Organization::factory()->create(['is_active' => true, 'slug' => 'myc-b', 'name' => 'Mycelium B', 'admin_id' => $this->adminB->id]);

        $this->adminA->update(['organization_id' => $this->organizationA->id]);
        $this->adminB->update(['organization_id' => $this->organizationB->id]);
        $this->superAdmin->update(['organization_id' => $this->organizationA->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->organizationA->id]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le Mycelium est public
    // =====================================================================

    public function test_the_mycelium_page_is_readable_without_authentication(): void
    {
        $this->get(route('mycelium'))
            ->assertOk()
            ->assertSee(__('mycelium.title'))
            ->assertSee(__('mycelium.subtitle'))
            ->assertSee('data-mycelium-root', false)
            ->assertSee('data-mycelium-tree', false);
    }

    /** La page montre le texte REELLEMENT compose, pas une copie figee. */
    public function test_the_public_page_shows_the_text_actually_composed(): void
    {
        PlatformAiConstitution::activate("Mycelium publie.\nSENTINELLE-MYCELIUM-PUBLIC", $this->superAdmin);

        $this->get(route('mycelium'))
            ->assertOk()
            ->assertSee('SENTINELLE-MYCELIUM-PUBLIC');

        // Retire : la graine du code reprend, et la page suit.
        PlatformAiConstitution::withdraw();

        $this->get(route('mycelium'))
            ->assertOk()
            ->assertDontSee('SENTINELLE-MYCELIUM-PUBLIC')
            ->assertSee(e("plateforme de pédagogie par l'entraide"), false);
    }

    // =====================================================================
    // B. Privacy — ce qui ne doit JAMAIS sortir
    // =====================================================================

    /**
     * Publier des principes de gouvernance n'est pas publier des donnees
     * d'exploitation. Ce test enumere ce qui doit rester dedans.
     */
    public function test_no_private_governance_data_ever_reaches_the_public_pages(): void
    {
        PlatformAiConstitution::activate('Mycelium actif.', $this->superAdmin);
        OrganizationAiConstitution::activate($this->organizationA, 'Nos principes publics.', $this->adminA);
        OrganizationAiDoctrine::activate($this->organizationA, 'DOCTRINE-STRICTEMENT-PRIVEE', $this->adminA);
        $this->organizationA->update(['ai_constitution_public' => true]);

        foreach ([
            route('mycelium'),
            route('organization.constitution', ['organization' => $this->organizationA->slug]),
        ] as $url) {
            $page = $this->get($url);
            $page->assertOk();

            $html = $page->getContent();

            // La doctrine est une preference metier, pas un principe public.
            $this->assertStringNotContainsString('DOCTRINE-STRICTEMENT-PRIVEE', $html, "Doctrine exposee sur {$url}");
            // L'auteur d'une version n'a pas a etre nomme publiquement.
            $this->assertStringNotContainsString($this->adminA->email, $html, "Email d'auteur expose sur {$url}");
            // Ni les prompts de capability, ni le bac a sable, ni les reglages.
            foreach (['clarify_help_request', 'loop_knowledge_answer', 'sandbox', 'api_key', 'monthly_budget'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $html, "[{$interdit}] expose sur {$url}");
            }
        }
    }

    /** L'historique des versions reste prive : seule l'active est publiee. */
    public function test_the_public_organization_page_never_exposes_history(): void
    {
        OrganizationAiConstitution::activate($this->organizationA, 'ANCIENNE-VERSION-PRIVEE', $this->adminA);
        OrganizationAiConstitution::activate($this->organizationA, 'VERSION-ACTIVE-PUBLIEE', $this->adminA);
        $this->organizationA->update(['ai_constitution_public' => true]);

        $this->get(route('organization.constitution', ['organization' => $this->organizationA->slug]))
            ->assertOk()
            ->assertSee('VERSION-ACTIVE-PUBLIEE')
            ->assertDontSee('ANCIENNE-VERSION-PRIVEE');
    }

    // =====================================================================
    // C. Opt-in : prive par defaut
    // =====================================================================

    public function test_an_organization_constitution_is_private_by_default(): void
    {
        $this->assertFalse((bool) $this->organizationA->fresh()->ai_constitution_public);

        OrganizationAiConstitution::activate($this->organizationA, 'Texte non publie.', $this->adminA);

        // 404, et non 403 : un 403 avouerait qu'il y a quelque chose ici.
        $this->get(route('organization.constitution', ['organization' => $this->organizationA->slug]))
            ->assertNotFound();
    }

    public function test_the_public_page_appears_once_the_organization_opts_in(): void
    {
        OrganizationAiConstitution::activate($this->organizationA, 'PRINCIPES-PUBLIABLES', $this->adminA);
        $this->organizationA->update(['ai_constitution_public' => true]);

        $this->get(route('organization.constitution', ['organization' => $this->organizationA->slug]))
            ->assertOk()
            ->assertSee('PRINCIPES-PUBLIABLES')
            ->assertSee($this->organizationA->name)
            ->assertSee(__('mycelium.org_inherits'));
    }

    /** Opt-in coche mais aucune version active : il n'y a rien a publier. */
    public function test_opting_in_without_an_active_constitution_stays_a_404(): void
    {
        $this->organizationA->update(['ai_constitution_public' => true]);

        $this->get(route('organization.constitution', ['organization' => $this->organizationA->slug]))
            ->assertNotFound();
    }

    /** Retirer la Constitution active referme la page publique. */
    public function test_withdrawing_the_constitution_closes_the_public_page(): void
    {
        OrganizationAiConstitution::activate($this->organizationA, 'Publie un temps.', $this->adminA);
        $this->organizationA->update(['ai_constitution_public' => true]);
        $this->get(route('organization.constitution', ['organization' => $this->organizationA->slug]))->assertOk();

        OrganizationAiConstitution::withdraw($this->organizationA);

        $this->get(route('organization.constitution', ['organization' => $this->organizationA->slug]))->assertNotFound();
    }

    /** Une organisation privee n'est JAMAIS enumeree dans le hub. */
    public function test_a_private_organization_is_never_listed_in_the_hub(): void
    {
        OrganizationAiConstitution::activate($this->organizationA, 'Publique.', $this->adminA);
        OrganizationAiConstitution::activate($this->organizationB, 'Privee.', $this->adminB);
        $this->organizationA->update(['ai_constitution_public' => true]);

        $page = $this->get(route('mycelium'));
        $page->assertOk()
            ->assertSee($this->organizationA->name)
            ->assertDontSee($this->organizationB->name);

        // Ni son nom, ni son slug, ni son texte.
        $html = $page->getContent();
        $this->assertStringNotContainsString($this->organizationB->slug, $html);
        $this->assertStringNotContainsString('Privee.', $html);
    }

    /** Une organisation publique SANS Constitution active n'est pas un noeud mort. */
    public function test_the_hub_never_lists_a_node_that_would_404(): void
    {
        $this->organizationA->update(['ai_constitution_public' => true]);

        $this->get(route('mycelium'))
            ->assertOk()
            ->assertDontSee($this->organizationA->name)
            ->assertSee(__('mycelium.organizations_empty'));
    }

    // =====================================================================
    // D. Tenant
    // =====================================================================

    public function test_organization_a_never_exposes_the_constitution_of_b(): void
    {
        OrganizationAiConstitution::activate($this->organizationA, 'SECRET-A', $this->adminA);
        OrganizationAiConstitution::activate($this->organizationB, 'SECRET-B', $this->adminB);
        $this->organizationA->update(['ai_constitution_public' => true]);
        $this->organizationB->update(['ai_constitution_public' => true]);

        $this->get(route('organization.constitution', ['organization' => $this->organizationA->slug]))
            ->assertOk()->assertSee('SECRET-A')->assertDontSee('SECRET-B');

        $this->get(route('organization.constitution', ['organization' => $this->organizationB->slug]))
            ->assertOk()->assertSee('SECRET-B')->assertDontSee('SECRET-A');
    }

    // =====================================================================
    // E. Permissions
    // =====================================================================

    public function test_only_a_super_admin_reaches_the_mycelium_admin_surface(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.mycelium'))->assertOk();

        $this->actingAs($this->adminA)->get(route('admin.mycelium'))->assertForbidden();
        $this->actingAs($this->adminA)->put(route('admin.mycelium.update'), ['body' => 'Tentative.'])->assertForbidden();
        $this->actingAs($this->memberA)->get(route('admin.mycelium'))->assertForbidden();
    }

    /** L'ancienne URL de T1348 redirige : une seule autorite, pas deux. */
    public function test_the_legacy_admin_url_redirects_to_the_mycelium(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/ai-constitution')
            ->assertRedirect(route('admin.mycelium'));
    }

    public function test_an_organization_admin_manages_publication_of_their_own_organization_only(): void
    {
        OrganizationAiConstitution::activate($this->organizationA, 'Nos principes.', $this->adminA);

        // Sans referer connu, on retombe sur le cockpit.
        $this->actingAs($this->adminA)
            ->put(route('organization.admin.constitution.publication', ['organization' => $this->organizationA->slug]), ['ai_constitution_public' => 1])
            ->assertRedirect(route('organization.admin.ai-behavior', ['organization' => $this->organizationA->slug]));

        $this->assertTrue((bool) $this->organizationA->fresh()->ai_constitution_public);

        // Depuis la page dediee, on y revient : basculer ne doit ejecter
        // personne de l'ecran ou il travaille.
        $this->actingAs($this->adminA)
            ->withHeader('referer', route('organization.admin.constitution', ['organization' => $this->organizationA->slug]))
            ->put(route('organization.admin.constitution.publication', ['organization' => $this->organizationA->slug]), ['ai_constitution_public' => 0])
            ->assertRedirect(route('organization.admin.constitution', ['organization' => $this->organizationA->slug]));

        $this->assertFalse((bool) $this->organizationA->fresh()->ai_constitution_public);
        $this->organizationA->update(['ai_constitution_public' => true]);

        // Viser B depuis A : la route n'existe pas pour lui.
        $this->actingAs($this->adminA)
            ->put(route('organization.admin.constitution.publication', ['organization' => $this->organizationB->slug]), ['ai_constitution_public' => 1])
            ->assertForbidden();

        $this->assertFalse((bool) $this->organizationB->fresh()->ai_constitution_public);
    }

    public function test_a_standard_member_can_never_change_publication(): void
    {
        $this->actingAs($this->memberA)
            ->put(route('organization.admin.constitution.publication', ['organization' => $this->organizationA->slug]), ['ai_constitution_public' => 1])
            ->assertForbidden();

        $this->assertFalse((bool) $this->organizationA->fresh()->ai_constitution_public);
    }

    /** La page dediee montre les trois blocs, sans dupliquer le versionnement. */
    public function test_the_dedicated_organization_page_shows_inheritance_text_and_publication(): void
    {
        OrganizationAiConstitution::activate($this->organizationA, 'Nos principes.', $this->adminA);

        $this->actingAs($this->adminA)
            ->get(route('organization.admin.constitution', ['organization' => $this->organizationA->slug]))
            ->assertOk()
            ->assertSee('data-org-constitution-inherited', false)
            ->assertSee('data-org-constitution-editor', false)
            ->assertSee('data-org-constitution-publication', false)
            ->assertSee('data-org-constitution-doctrine-note', false)
            ->assertSee('Nos principes.');
    }

    /**
     * La page dediee doit etre ATTEIGNABLE : une page sans entree de menu
     * n'existe que pour qui connait son URL.
     */
    public function test_the_organization_sidebar_exposes_the_constitution_entry(): void
    {
        $lien = route('organization.admin.constitution', ['organization' => $this->organizationA->slug]);

        $this->actingAs($this->adminA)
            ->get(route('organization.admin.ai-behavior', ['organization' => $this->organizationA->slug]))
            ->assertOk()
            ->assertSee($lien, false)
            ->assertSee(__('navigation.org_admin_ai_constitution'));
    }

    /**
     * Publier se decide depuis les DEUX ecrans qui portent la Constitution :
     * le cockpit « Comportement IA » et la page dediee. Meme route, meme
     * autorite — deux endroits pour agir, jamais deux logiques.
     */
    public function test_publication_is_decidable_from_the_behaviour_cockpit_too(): void
    {
        OrganizationAiConstitution::activate($this->organizationA, 'Nos principes.', $this->adminA);
        $cible = route('organization.admin.constitution.publication', ['organization' => $this->organizationA->slug]);

        $this->actingAs($this->adminA)
            ->get(route('organization.admin.ai-behavior', ['organization' => $this->organizationA->slug]))
            ->assertOk()
            ->assertSee('data-behavior-org-constitution-publication', false)
            ->assertSee('data-behavior-org-constitution-publication-toggle', false)
            ->assertSee($cible, false)
            ->assertSee(__('mycelium.publication_title'));
    }

    /**
     * Le hub propose DEUX destinations par organisation : son accueil et sa
     * Constitution. L'accueil gere lui-meme son acces — public, ou connexion
     * requise — exactement comme partout ailleurs dans le produit.
     */
    public function test_each_node_offers_both_the_organization_home_and_its_constitution(): void
    {
        OrganizationAiConstitution::activate($this->organizationA, 'Nos principes.', $this->adminA);
        $this->organizationA->update(['ai_constitution_public' => true, 'is_public' => false]);

        $slug = $this->organizationA->slug;
        $accueil = 'data-mycelium-organization-site="'.$slug.'"';
        $constitution = 'data-mycelium-organization-link="'.$slug.'"';

        // Organisation NON publique : les deux liens sont quand meme proposes.
        $this->get(route('mycelium'))->assertOk()
            ->assertSee($accueil, false)
            ->assertSee($constitution, false)
            ->assertSee('href="'.route('organization.home', ['organization' => $slug]).'"', false)
            ->assertSee(__('mycelium.org_visit_site'))
            ->assertSee(__('mycelium.org_open_page'));

        // Et la page dediee mene elle aussi a l'accueil.
        $this->get(route('organization.constitution', ['organization' => $slug]))->assertOk()
            ->assertSee($accueil, false)
            ->assertSee(__('mycelium.org_visit_site'));

        // Organisation publique : rien ne change, le lien etait deja la.
        $this->organizationA->update(['is_public' => true]);
        $this->get(route('mycelium'))->assertOk()->assertSee($accueil, false);
    }

    /** Le libelle du lien de Constitution est « Constitution », et rien d'autre. */
    public function test_the_constitution_link_is_labelled_constitution(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->assertSame(
                'Constitution',
                __('mycelium.org_open_page', [], $locale),
                "Le libelle [{$locale}] du lien de Constitution a change."
            );
        }
    }

    // =====================================================================
    // F. Vocabulaire francais
    // =====================================================================

    /**
     * Invariant T1349 : en francais, on ecrit « organisation ».
     * « Organization » est le nom du MODELE Laravel — il n'a rien a faire sous
     * les yeux d'un utilisateur francophone.
     *
     * Ce test juge ce que T1349 POSSEDE : ses chaines et l'habillage de ses
     * pages. Le corps des Constitutions est du texte ADMINISTRABLE — un
     * administrateur y ecrit ce qu'il veut, et aucun test ne peut le lui
     * interdire — il est donc soustrait avant la mesure.
     */
    public function test_the_french_surfaces_never_show_the_model_name(): void
    {
        $this->app->setLocale('fr');

        foreach (require base_path('lang/fr/mycelium.php') as $cle => $texte) {
            $this->assertStringNotContainsString(
                'Organization',
                $texte,
                "La cle [mycelium.{$cle}] affiche « Organization » au lieu de « organisation »."
            );
        }

        $corpsAdministrable = 'Notre principe : ORG-CORPS-ADMINISTRABLE.';
        OrganizationAiConstitution::activate($this->organizationA, $corpsAdministrable, $this->adminA);
        $this->organizationA->update(['ai_constitution_public' => true]);

        foreach ([
            route('mycelium'),
            route('organization.constitution', ['organization' => $this->organizationA->slug]),
        ] as $url) {
            // strip_tags laisse le contenu des <script>/<style> : c'est voulu,
            // un mot francais mal choisi y serait tout aussi visible.
            $habillage = strip_tags($this->get($url)->getContent());

            // Soustraction du texte administrable LIGNE PAR LIGNE : la mise
            // en page eclate le corps en paragraphes et en items, donc le bloc
            // entier ne se retrouve jamais tel quel dans le rendu. Chaque
            // ligne est retiree sous ses deux formes, brute et echappee.
            foreach ([PlatformAiConstitution::activeTextOrSeed(), $corpsAdministrable] as $administre) {
                foreach (preg_split('/\R/', $administre) as $ligne) {
                    $ligne = ltrim(trim($ligne), '-–—•* ');

                    if ($ligne === '') {
                        continue;
                    }

                    foreach ([$ligne, e($ligne)] as $forme) {
                        $habillage = str_replace($forme, '', $habillage);
                    }
                }
            }

            $this->assertStringNotContainsString('Organization', $habillage, "« Organization » visible sur {$url}");
            $this->assertStringContainsString('organisation', mb_strtolower($habillage), "« organisation » attendu sur {$url}");
        }
    }

    /**
     * Le lien de gouvernance remplace le credit de portage — a l'identique de
     * place, sur les DEUX pieds de page du produit : le partiel partage et le
     * pied autonome de la landing hero-v2.
     */
    public function test_the_governance_link_replaces_the_sponsorship_credit(): void
    {
        // 1. Le partiel partage (layout invite, accueil, accueil d'organisation).
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-footer-mycelium', false)
            ->assertSee(__('mycelium.footer_link'))
            ->assertDontSee(__('footer.by_amt'))
            ->assertDontSee('amteletravail.fr');

        // 2. Le pied autonome de la landing : c'est LUI qui portait « Un projet
        //    porte par l'AMT · en partenariat avec ArtSciLab ».
        $this->organizationA->update(['homepage_template' => 'bouclepro_hero_v2', 'is_public' => true]);

        $this->get(route('organization.home', ['organization' => $this->organizationA->slug]))
            ->assertOk()
            ->assertSee('data-footer-mycelium', false)
            ->assertSee(__('mycelium.footer_link'))
            ->assertDontSee(__('footer.by_amt'))
            ->assertDontSee(__('footer.partner_artscilab'))
            ->assertDontSee('amteletravail.fr');
    }
}
