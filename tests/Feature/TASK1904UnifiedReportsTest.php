<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1904 — « Signalement » : dispositif UNIQUE (table `bug_reports`),
 * categorise et CONFIDENTIEL.
 *
 * Decisions MASTER : aucune liste publique (D6) ; un membre ne lit que SES
 * signalements ; « Securite des enfants » lu par le seul superadmin (D3) ;
 * `/org/{slug}/report` remplace `/org/{slug}/bugs` (redirection sous la meme
 * garde membre).
 */
class TASK1904UnifiedReportsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $membreA;

    private User $autreMembreA;

    private User $adminOrgA;

    private User $membreB;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 't1904-a', 'is_active' => true, 'is_public' => true, 'is_default' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 't1904-b', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);

        $this->membreA = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
        $this->autreMembreA = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
        $this->adminOrgA = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
        $this->orgA->update(['admin_id' => $this->adminOrgA->id]);
        $this->membreB = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => false]);
        $this->superadmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true]);
    }

    private function signalement(Organization $organization, User $auteur, string $details, string $category = 'technical_bug', string $status = 'pending'): BugReport
    {
        return BugReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $auteur->id,
            'category' => $category,
            'reason' => $category,
            'details' => $details,
            'status' => $status,
        ]);
    }

    private function oublierOrganisation(): void
    {
        app()->forgetInstance('current_organization');
    }

    // ── Confidentialite des listes ─────────────────────────────────────────

    public function test_un_invite_ne_lit_aucun_signalement_ni_en_global_ni_dans_l_organisation(): void
    {
        $this->signalement($this->orgA, $this->membreA, 'SECRET GLOBAL 1904');

        $this->get('/report')->assertOk()
            ->assertSee('data-report-guest', false)
            ->assertDontSee('SECRET GLOBAL 1904')
            ->assertDontSee('data-my-reports', false);

        $this->oublierOrganisation();
        $this->get('/org/t1904-a/report')->assertRedirect(route('organization.login', ['organization' => 't1904-a']));

        $this->oublierOrganisation();
        $this->get('/bugs')->assertRedirect('/report');
    }

    public function test_l_ancienne_url_d_organisation_redirige_sous_la_meme_garde_et_jamais_vers_une_liste(): void
    {
        // Invite : la garde membre s'applique avant la redirection.
        $this->get('/org/t1904-a/bugs')->assertRedirect(route('organization.login', ['organization' => 't1904-a']));

        // Membre d'une autre organisation : 404, comme la nouvelle URL.
        $this->oublierOrganisation();
        $this->actingAs($this->membreB)->get('/org/t1904-a/bugs')->assertNotFound();

        // Membre : redirection permanente vers /report.
        $this->oublierOrganisation();
        $this->actingAs($this->membreA)->get('/org/t1904-a/bugs')
            ->assertStatus(301)
            ->assertRedirect('/org/t1904-a/report');
    }

    public function test_un_membre_ne_lit_que_ses_signalements(): void
    {
        $this->signalement($this->orgA, $this->membreA, 'LE MIEN 1904');
        $this->signalement($this->orgA, $this->autreMembreA, 'CELUI D UN AUTRE MEMBRE 1904');
        $this->signalement($this->orgB, $this->membreB, 'CELUI D UNE AUTRE ORGANISATION 1904');

        $this->actingAs($this->membreA)->get(route('organization.bug-reports.index', $this->orgA))
            ->assertOk()
            ->assertSee('LE MIEN 1904')
            ->assertDontSee('CELUI D UN AUTRE MEMBRE 1904')
            ->assertDontSee('CELUI D UNE AUTRE ORGANISATION 1904');
    }

    public function test_un_membre_ne_lit_pas_les_signalements_d_une_autre_organisation_par_son_url(): void
    {
        $this->signalement($this->orgB, $this->membreB, 'FUITE INTER ORG 1904');

        $reponse = $this->actingAs($this->membreA)->get(route('organization.bug-reports.index', $this->orgB));

        $reponse->assertNotFound();
        $this->assertStringNotContainsString('FUITE INTER ORG 1904', $reponse->getContent());
    }

    // ── Soumission ─────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function categories(): array
    {
        return array_combine(BugReport::CATEGORIES, array_map(fn ($c) => [$c], BugReport::CATEGORIES));
    }

    #[DataProvider('categories')]
    public function test_un_membre_soumet_dans_chaque_categorie(string $category): void
    {
        $this->actingAs($this->membreA)
            ->post(route('organization.bug-reports.store', $this->orgA), [
                'category' => $category,
                'details' => "Detail {$category}",
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bug_reports', [
            'organization_id' => $this->orgA->id,
            'reporter_id' => $this->membreA->id,
            'category' => $category,
            'details' => "Detail {$category}",
            'status' => 'pending',
        ]);
    }

    public function test_une_categorie_inconnue_ou_absente_est_refusee(): void
    {
        foreach ([['category' => 'piratage', 'details' => 'x'], ['details' => 'x']] as $charge) {
            $this->actingAs($this->membreA)
                ->post(route('organization.bug-reports.store', $this->orgA), $charge)
                ->assertSessionHasErrors('category');
        }

        $this->assertSame(0, BugReport::count());
    }

    public function test_un_membre_ne_soumet_pas_dans_une_autre_organisation(): void
    {
        $this->actingAs($this->membreA)
            ->post('/org/t1904-b/report', ['category' => 'other', 'details' => 'INJECTION 1904'])
            ->assertNotFound();

        $this->assertDatabaseMissing('bug_reports', ['details' => 'INJECTION 1904']);
    }

    public function test_seule_une_url_interne_est_conservee_comme_contenu_concerne(): void
    {
        $interne = 'http://localhost/org/t1904-a/loops';

        foreach (['INTERNE' => $interne, 'EXTERNE' => 'https://phishing.example/login', 'SCRIPT' => 'javascript:alert(1)'] as $cas => $url) {
            $this->actingAs($this->membreA)->post(route('organization.bug-reports.store', $this->orgA), [
                'category' => 'inappropriate_content', 'details' => "URL {$cas}", 'page_url' => $url,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame($interne, BugReport::where('details', 'URL INTERNE')->value('page_url'));
        $this->assertNull(BugReport::where('details', 'URL EXTERNE')->value('page_url'));
        $this->assertNull(BugReport::where('details', 'URL SCRIPT')->value('page_url'));
    }

    public function test_la_page_d_origine_interne_est_reprise_du_referer(): void
    {
        $this->actingAs($this->membreA)
            ->withHeader('referer', 'http://localhost/org/t1904-a/flux')
            ->get(route('organization.bug-reports.index', $this->orgA))
            ->assertOk()
            ->assertSee('name="page_url" value="http://localhost/org/t1904-a/flux"', false);

        $this->oublierOrganisation();
        $this->actingAs($this->membreA)
            ->withHeader('referer', 'https://ailleurs.example/page')
            ->get(route('organization.bug-reports.index', $this->orgA))
            ->assertOk()
            ->assertSee('name="page_url" value=""', false);
    }

    // ── Back-office ────────────────────────────────────────────────────────

    public function test_le_superadmin_voit_la_securite_des_enfants_en_tete_et_filtre(): void
    {
        $this->signalement($this->orgA, $this->membreA, 'BUG ANCIEN 1904');
        $enfant = $this->signalement($this->orgB, $this->membreB, 'ENFANT 1904', BugReport::CHILD_SAFETY);
        $enfant->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->signalement($this->orgA, $this->membreA, 'SPAM RECENT 1904', 'spam_fraud');

        $html = $this->actingAs($this->superadmin)->get(route('admin.bug-reports'))->assertOk()->getContent();

        // Identifiable, et en tete malgre son anciennete.
        $this->assertStringContainsString('data-report-category="child_safety"', $html);
        $this->assertStringContainsString('data-child-safety-pending', $html);
        $this->assertLessThan(strpos($html, 'SPAM RECENT 1904'), strpos($html, 'ENFANT 1904'));
        $this->assertLessThan(strpos($html, 'BUG ANCIEN 1904'), strpos($html, 'ENFANT 1904'));

        $filtre = $this->actingAs($this->superadmin)->get(route('admin.bug-reports', ['category' => 'spam_fraud', 'status' => 'pending']))->assertOk();
        $filtre->assertSee('SPAM RECENT 1904')->assertDontSee('ENFANT 1904')->assertDontSee('BUG ANCIEN 1904');

        $this->actingAs($this->superadmin)->get(route('admin.bug-reports', ['category' => 'inconnue']))->assertSessionHasErrors('category');
    }

    public function test_un_membre_ou_un_admin_d_organisation_n_ouvre_pas_le_back_office_global(): void
    {
        $this->actingAs($this->membreA)->get(route('admin.bug-reports'))->assertForbidden();
        $this->actingAs($this->adminOrgA)->get(route('admin.bug-reports'))->assertForbidden();
    }

    public function test_l_admin_d_organisation_ne_lit_ni_ne_resout_la_securite_des_enfants(): void
    {
        $this->signalement($this->orgA, $this->membreA, 'COMPORTEMENT ORG A 1904', 'inappropriate_behavior');
        $enfant = $this->signalement($this->orgA, $this->membreA, 'ENFANT ORG A 1904', BugReport::CHILD_SAFETY);
        $this->signalement($this->orgB, $this->membreB, 'COMPORTEMENT ORG B 1904', 'inappropriate_behavior');

        $html = $this->actingAs($this->adminOrgA)->get(route('organization.admin.reports', $this->orgA))->assertOk()->getContent();

        $this->assertStringContainsString('COMPORTEMENT ORG A 1904', $html);
        $this->assertStringNotContainsString('ENFANT ORG A 1904', $html);
        $this->assertStringNotContainsString('COMPORTEMENT ORG B 1904', $html);
        $this->assertStringNotContainsString('value="child_safety"', $html, 'le filtre ne propose pas la categorie');

        // Meme en la demandant explicitement.
        $this->actingAs($this->adminOrgA)
            ->get(route('organization.admin.reports', [$this->orgA, 'category' => 'child_safety']))
            ->assertOk()->assertDontSee('ENFANT ORG A 1904');

        $this->actingAs($this->adminOrgA)
            ->patch(route('organization.admin.reports.resolve', [$this->orgA, $enfant]))
            ->assertNotFound();
        $this->assertSame('pending', $enfant->fresh()->status);
    }

    // ── Libelles et traductions ────────────────────────────────────────────

    public function test_les_categories_sont_traduites_en_francais_et_en_anglais(): void
    {
        $fr = require lang_path('fr/bugs.php');
        $en = require lang_path('en/bugs.php');

        $this->assertSame(array_keys($fr), array_keys($en));
        $this->assertSame(BugReport::CATEGORIES, array_keys($fr['categories']));
        $this->assertSame(BugReport::CATEGORIES, array_keys($en['categories']));
        $this->assertSame('Sécurité des enfants', $fr['categories']['child_safety']);
        $this->assertSame('Child safety', $en['categories']['child_safety']);
    }

    /** @return array<string, array{string, string}> */
    public static function langues(): array
    {
        return ['fr' => ['fr', 'Signalement'], 'en' => ['en', 'Report']];
    }

    #[DataProvider('langues')]
    public function test_le_formulaire_et_l_entree_portent_le_libelle_signalement(string $langue, string $libelle): void
    {
        $this->orgA->update(['locale' => $langue]);
        app()->setLocale($langue);

        $html = $this->actingAs($this->membreA)->get(route('organization.bug-reports.index', $this->orgA))->assertOk()->getContent();

        $this->assertStringContainsString($libelle, $html);
        foreach (BugReport::CATEGORIES as $category) {
            $this->assertStringContainsString('<option value="'.$category.'"', $html);
            $this->assertStringContainsString(e(__('bugs.categories.'.$category)), $html);
        }

        $this->oublierOrganisation();
        auth()->logout();
        $accueil = $this->get(route('organization.home', $this->orgA))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('organization.bug-reports.index', $this->orgA), '#').'"[^>]*>\s*'.$libelle.'\s*<#', $accueil);
    }
}
