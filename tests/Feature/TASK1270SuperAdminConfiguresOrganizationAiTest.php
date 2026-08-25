<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1270 — SuperAdmin : configurer l'IA d'une Organization.
 *
 * Le listing `/admin/ai-organizations` RELIE chaque Organization a sa surface
 * de reglage existante `/org/{organization}/admin/ai` (`organization.admin.ai`,
 * OrgAdminController::ai / updateAi). Aucun second formulaire de secret, aucune
 * route nouvelle : le listing ne rend ni ne recoit jamais une cle, et la
 * regle « champ api_key vide = cle conservee » est celle de updateAi(), deja
 * couverte par TASK1212OrganizationAiAdminTest — ici verifiee par le chemin
 * du SuperAdmin (lien -> formulaire -> budget seul -> cle intacte).
 *
 * TASK-1306 : « Configurer » ouvre desormais une modale inline (bouton, plus
 * un <a href>) qui INCLUT le meme partiel de formulaire que
 * /org/{organization}/admin/ai — meme route `organization.admin.ai.update`,
 * meme controleur, aucune deuxieme autorite. Les modales sont rendues APRES
 * le <table> (invariant TASK-1270 inchange : le tableau lui-meme ne porte
 * jamais de <form> ni de <input>) ; la garde ci-dessous en verifie toujours
 * la lettre.
 */
class TASK1270SuperAdminConfiguresOrganizationAiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_TEST = 'sk-or-task1270-test20260822-never-rendered';

    private const KEY_OTHER = 'sk-or-task1270-other-never-rendered';

    private Organization $test20260822;

    private Organization $other;

    private Organization $unconfigured;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->test20260822 = Organization::factory()->create(['name' => 'test20260822', 'slug' => 'test20260822']);
        $this->other = Organization::factory()->create(['name' => 'Autre Org 1270', 'slug' => 'autre-org-1270']);
        $this->unconfigured = Organization::factory()->create(['name' => 'Sans reglage 1270', 'slug' => 'sans-reglage-1270']);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->test20260822->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => self::KEY_TEST,
            'monthly_budget_usd' => 5.00,
        ]);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->other->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => self::KEY_OTHER,
            'monthly_budget_usd' => 12.00,
        ]);

        // Le SuperAdmin n'est membre d'AUCUNE des Organizations testees.
        $platform = Organization::factory()->create(['name' => 'Plateforme 1270', 'slug' => 'plateforme-1270']);
        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $platform->id]);
    }

    // (a) chaque Organization du listing expose une action Configurer (bouton)
    // et sa modale INCLUT le formulaire de la surface canonique
    public function test_the_listing_links_every_organization_to_its_existing_ai_settings_surface(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.ai-organizations'));

        $response->assertOk();
        $html = $response->getContent();

        foreach (Organization::query()->get() as $organization) {
            $response->assertSee('data-platform-org-configure="'.$organization->slug.'"', false);
            $response->assertSee('data-platform-org-modal="'.$organization->slug.'"', false);

            $modal = $this->modalOf($html, $organization);
            $expected = route('organization.admin.ai.update', ['organization' => $organization->slug]);
            $this->assertStringContainsString('action="'.$expected.'"', $modal, 'la modale de '.$organization->slug.' poste vers la surface canonique');
            $this->assertStringContainsString('data-ai-credential-form="'.$organization->slug.'"', $modal);
        }

        // Une Organization SANS reglage a aussi son action et sa modale :
        // c'est la que le SuperAdmin va creer le reglage, sur la meme surface.
        $this->assertSame(
            Organization::query()->count(),
            substr_count($html, 'data-platform-org-configure='),
            'exactement une action « Configurer » par Organization vivante',
        );
        $this->assertSame(
            Organization::query()->count(),
            substr_count($html, 'data-ai-credential-form="'),
            'exactement un formulaire de credential par Organization vivante',
        );
    }

    // (b) tenant : la modale test20260822 poste sur test20260822, pas ailleurs
    public function test_the_test20260822_row_links_to_test20260822_and_to_no_other_organization(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();

        $row = $this->rowOf($html, $this->test20260822);
        $this->assertStringContainsString('data-platform-org-configure="test20260822"', $row);
        $this->assertStringNotContainsString('autre-org-1270', $row);
        $this->assertStringNotContainsString('sans-reglage-1270', $row);

        $modal = $this->modalOf($html, $this->test20260822);
        $expected = route('organization.admin.ai.update', ['organization' => 'test20260822']);
        $this->assertStringContainsString('action="'.$expected.'"', $modal);
        $this->assertStringEndsWith('/org/test20260822/admin/ai', route('organization.admin.ai', ['organization' => 'test20260822']));
        $this->assertStringNotContainsString('autre-org-1270', $modal);

        $otherRow = $this->rowOf($html, $this->other);
        $this->assertStringContainsString('data-platform-org-configure="autre-org-1270"', $otherRow);
        $this->assertStringNotContainsString('test20260822', $otherRow);

        $otherModal = $this->modalOf($html, $this->other);
        $this->assertStringNotContainsString('test20260822', $otherModal);
    }

    // (c) aucune cle dans le HTML du listing — ni en clair, ni chiffree, ni par le nom du champ
    public function test_the_listing_renders_no_key_at_all(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.ai-organizations'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString(self::KEY_TEST, $html);
        $this->assertStringNotContainsString(self::KEY_OTHER, $html);
        $this->assertStringNotContainsString('sk-or-', $html);

        // Le texte chiffre en base ne sort pas non plus.
        foreach (DB::table('organization_ai_settings')->pluck('api_key') as $cipher) {
            $this->assertNotSame('', (string) $cipher);
            $this->assertStringNotContainsString((string) $cipher, $html);
        }

        // TASK-1306 : le NOM du champ api_key apparait desormais legitimement
        // (une modale de credential par Organization) — ce qui reste absolu,
        // c'est que sa VALEUR est toujours vide, pour CHAQUE Organization.
        $matched = preg_match_all('/name="api_key"[^>]*value="([^"]*)"/', $html, $apiKeyMatches);
        $this->assertSame(Organization::query()->count(), $matched, 'un champ api_key par Organization, jamais plus');
        foreach ($apiKeyMatches[1] as $value) {
            $this->assertSame('', $value, 'le champ api_key est toujours rendu vide');
        }

        // Le tableau des Organizations lui-meme ne porte aucun formulaire ni
        // champ : la surface d'ecriture (les modales) est rendue APRES le
        // </table>, jamais dedans.
        $table = substr($html, strpos($html, '<table'), strpos($html, '</table>') - strpos($html, '<table'));
        $this->assertStringNotContainsString('<form', $table);
        $this->assertStringNotContainsString('<input', $table);

        // La seule information transmise a la vue sur le credential : pret ou
        // non, defini ou non (jamais sa valeur), mis a jour quand, gere par qui.
        $response->assertViewHas('settings', function (array $settings): bool {
            foreach ($settings as $setting) {
                if (array_key_exists('api_key', $setting)) {
                    return false;
                }
            }

            return array_keys($settings[(string) $this->test20260822->id]) === [
                'provider', 'model', 'monthly_budget_usd', 'ready', 'has_credential', 'api_key_updated_at', 'credential_management_mode',
            ];
        });
    }

    // (d) le chemin du SuperAdmin : lien -> formulaire sans secret -> budget seul -> cle intacte
    public function test_the_super_admin_follows_the_link_changes_only_the_budget_and_the_key_stays_intact(): void
    {
        $before = DB::table('organization_ai_settings')->where('organization_id', $this->test20260822->id)->first();
        $otherBefore = DB::table('organization_ai_settings')->where('organization_id', $this->other->id)->first();

        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();

        // TASK-1306 : la modale « Configurer » de test20260822 poste bien vers
        // la surface canonique — c'est cette URL que le SuperAdmin « suit ».
        $modal = $this->modalOf($html, $this->test20260822);
        $href = route('organization.admin.ai', ['organization' => 'test20260822']);
        $this->assertStringContainsString('action="'.route('organization.admin.ai.update', ['organization' => 'test20260822']).'"', $modal);

        // 1. Le formulaire s'ouvre pour le SuperAdmin (OrgAdminMiddleware : is_admin).
        $form = $this->actingAs($this->superAdmin)->get($href);
        $form->assertOk();
        $formHtml = $form->getContent();

        // 2. Reglages visibles SANS aucun secret : champ cle vide, badge seulement.
        $form->assertSee('data-ai-settings-status="ready"', false);
        $form->assertSee('name="api_key" value=""', false);
        $form->assertSee('value="5.00"', false);
        $this->assertStringNotContainsString(self::KEY_TEST, $formHtml);
        $this->assertStringNotContainsString((string) $before->api_key, $formHtml);

        // 3. Modification d'une valeur NON secrete, champ api_key vide (= conserver).
        $this->actingAs($this->superAdmin)
            ->put(route('organization.admin.ai.update', ['organization' => 'test20260822']), [
                'provider' => 'openrouter',
                'model' => 'openai/gpt-4o-mini',
                'api_key' => '',
                'monthly_budget_usd' => '7.00',
                'is_enabled' => 1,
            ])
            ->assertRedirect($href)
            ->assertSessionHas('success');

        $after = DB::table('organization_ai_settings')->where('organization_id', $this->test20260822->id)->first();
        $this->assertSame('7.00', number_format((float) $after->monthly_budget_usd, 2, '.', ''));
        // La cle est conservee OCTET POUR OCTET (texte chiffre identique) et
        // son horodatage n'a pas bouge : updateAi() n'a pas touche le credential.
        $this->assertSame($before->api_key, $after->api_key);
        $this->assertSame($before->api_key_updated_at, $after->api_key_updated_at);
        $this->assertSame(self::KEY_TEST, OrganizationAiSetting::query()->where('organization_id', $this->test20260822->id)->value('api_key'));

        // 4. L'autre Organization n'a pas bouge d'un octet.
        $this->assertEquals($otherBefore, DB::table('organization_ai_settings')->where('organization_id', $this->other->id)->first());

        // 5. Le listing reflete le nouveau budget, la cle toujours absente.
        $listing = $this->actingAs($this->superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();
        $this->assertStringContainsString('$7.00', $this->rowOf($listing, $this->test20260822));
        $this->assertStringNotContainsString(self::KEY_TEST, $listing);

        // 6. Retour a 5.00, toujours sans toucher le champ cle.
        $this->actingAs($this->superAdmin)
            ->put(route('organization.admin.ai.update', ['organization' => 'test20260822']), [
                'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => '', 'monthly_budget_usd' => '5.00', 'is_enabled' => 1,
            ])
            ->assertRedirect($href);

        $restored = DB::table('organization_ai_settings')->where('organization_id', $this->test20260822->id)->first();
        $this->assertSame('5.00', number_format((float) $restored->monthly_budget_usd, 2, '.', ''));
        $this->assertSame($before->api_key, $restored->api_key);
        $this->assertSame($before->api_key_updated_at, $restored->api_key_updated_at);
    }

    // Le lien ne donne aucun droit : la cible reste protegee par OrgAdminMiddleware.
    public function test_the_link_target_stays_forbidden_to_a_foreign_organization_admin_and_to_a_member(): void
    {
        $foreignAdmin = User::factory()->create(['organization_id' => $this->other->id]);
        $this->other->update(['admin_id' => $foreignAdmin->id]);
        $member = User::factory()->create(['organization_id' => $this->test20260822->id]);

        $target = route('organization.admin.ai', ['organization' => 'test20260822']);

        $this->actingAs($foreignAdmin)->get(route('admin.ai-organizations'))->assertForbidden();
        $this->actingAs($foreignAdmin)->get($target)->assertForbidden();
        $this->actingAs($member)->get($target)->assertForbidden();
        $this->actingAs($member)
            ->put(route('organization.admin.ai.update', ['organization' => 'test20260822']), [
                'provider' => 'openrouter', 'model' => 'x', 'monthly_budget_usd' => '99', 'is_enabled' => 1,
            ])
            ->assertForbidden();

        $this->assertSame('5.00', number_format((float) OrganizationAiSetting::query()->where('organization_id', $this->test20260822->id)->value('monthly_budget_usd'), 2, '.', ''));
    }

    /** Le HTML de la ligne `<tr data-platform-org="{id}">…</tr>` de l'Organization. */
    private function rowOf(string $html, Organization $organization): string
    {
        $start = strpos($html, 'data-platform-org="'.$organization->id.'"');
        $this->assertNotFalse($start, 'ligne de '.$organization->slug.' presente');
        $end = strpos($html, '</tr>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * TASK-1306 : le HTML de la modale « Configurer » de cette Organization
     * (rendue apres le </table>, `data-platform-org-modal="{slug}"`).
     */
    private function modalOf(string $html, Organization $organization): string
    {
        $needle = 'data-platform-org-modal="'.$organization->slug.'"';
        $tagStart = strrpos(substr($html, 0, strpos($html, $needle) ?: 0), '<div');
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, 'modale de '.$organization->slug.' presente');
        $this->assertNotFalse($tagStart);

        // Modale suivante (ou fin du corps) : borne la recherche pour ne
        // jamais deborder sur la modale d'une autre Organization.
        $nextModal = strpos($html, 'data-platform-org-modal="', $start + strlen($needle));
        $end = $nextModal !== false ? $nextModal : strlen($html);

        return substr($html, $tagStart, $end - $tagStart);
    }
}
