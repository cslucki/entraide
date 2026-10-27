<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiSurfaceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AU — l'assistant « BouclePro IA » s'éteint PAR Organization.
 *
 * ## Le manque
 *
 * Il n'avait qu'un interrupteur PLATEFORME (`AI_SHELL_ENABLED`, une variable
 * d'environnement) : impossible de le couper pour UNE Organization sans le
 * couper pour toutes.
 *
 * ## Ce que ce réglage N'EST PAS
 *
 * Ce n'est **pas** l'interrupteur IA de l'Organization, et c'est toute sa
 * raison d'être : disposer de l'IA — reformulation, synthèse, agents — **sans**
 * imposer l'assistant flottant par-dessus chaque page. Les tests le mesurent
 * explicitement : IA allumée, assistant coupé, les autres surfaces restent.
 *
 * Et ce n'est pas le « Shell Welcome », qui s'adresse aux VISITEURS.
 */
class TASK1693AiShellPerOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693au',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );

        app()->instance('current_organization', $this->organization);
        app()->forgetInstance(AiSurfaceGate::class);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function tableauDeBord(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    private function eteindreLAssistant(): void
    {
        $this->organization->forceFill(['ai_shell_enabled' => false])->save();
        app()->instance('current_organization', $this->organization->fresh());
    }

    /** Par defaut, rien ne change : l'assistant est la — bouton ET panneau. */
    public function test_the_assistant_is_there_by_default(): void
    {
        $html = $this->tableauDeBord();

        $this->assertStringContainsString('data-ai-shell', $html);
        $this->assertStringContainsString('data-ai-fab-toggle', $html);
    }

    /**
     * LE manque comble : on peut l'eteindre pour CETTE Organization.
     *
     * TASK-1693 bug AV — la mesure porte sur les DEUX objets. Ma premiere
     * version ne visait que `data-ai-shell`, le PANNEAU : le bouton flottant
     * restait, et le test passait vert pendant que la recette voyait la
     * pastille. Deux objets, deux assertions.
     */
    public function test_the_assistant_can_be_turned_off_for_one_organization(): void
    {
        $this->eteindreLAssistant();

        $html = $this->tableauDeBord();

        $this->assertStringNotContainsString('data-ai-shell', $html, 'le panneau doit disparaitre');
        $this->assertStringNotContainsString('data-ai-fab-toggle', $html, 'le bouton flottant aussi');
    }

    /** Et sur une AUTRE page que le tableau de bord — la recette parlait de l'Explorer. */
    public function test_it_is_gone_on_the_explorer_too(): void
    {
        $this->eteindreLAssistant();

        $html = $this->actingAs($this->membre)
            ->get(route('organization.explorer', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-ai-fab-toggle', $html);
        $this->assertStringNotContainsString('data-ai-shell', $html);
    }

    /**
     * Et l'IA, elle, RESTE disponible.
     *
     * C'est toute la raison d'etre de ce reglage : disposer de l'IA sans
     * imposer l'assistant flottant. Sans cette mesure, le correctif pourrait
     * eteindre l'IA entiere sans qu'aucun test ne rougisse.
     */
    public function test_the_rest_of_the_ai_is_untouched(): void
    {
        $this->eteindreLAssistant();

        $this->assertTrue(
            app(AiSurfaceGate::class)->availableFor($this->organization->id),
            'l\'IA de l\'Organization ne doit pas tomber avec l\'assistant',
        );

        $html = $this->actingAs($this->membre)
            ->get(route('organization.services.create', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ai-formulate-block', $html,
            'l\'aide a reformuler ne depend pas de l\'assistant');
    }

    /** Une AUTRE Organization n'est pas touchee. */
    public function test_another_organization_keeps_its_assistant(): void
    {
        $this->eteindreLAssistant();

        $autre = Organization::factory()->create(['slug' => 'org-1693au-bis', 'is_active' => true]);
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $autre->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );
        $membreAilleurs = User::factory()->complete()->create([
            'organization_id' => $autre->id,
            'email_verified_at' => now(),
        ]);

        app()->instance('current_organization', $autre);
        app()->forgetInstance(AiSurfaceGate::class);

        $html = $this->actingAs($membreAilleurs)
            ->get(route('organization.dashboard', ['organization' => $autre->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ai-shell', $html);
        $this->assertStringContainsString('data-ai-fab-toggle', $html);
    }

    // ── L'ecran de reglage ───────────────────────────────────────────────────

    public function test_the_checkbox_is_on_the_platform_ai_config(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ai-shell-form="'.$this->organization->slug.'"', $html);
        $this->assertStringContainsString('data-ai-shell-enabled="1"', $html);
    }

    /**
     * TASK-1693 bug AV — la case vit dans SA PROPRE section.
     *
     * Au bug AU je l'avais glissee dans le bloc « Agents profil IA » : elle
     * paraissait appartenir a ce reglage-la. La mesure porte donc sur
     * l'ORDRE : la section de l'assistant commence APRES la fin du bloc des
     * profils.
     */
    public function test_the_checkbox_is_not_inside_the_profiles_block(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $section = strpos($html, 'data-ai-shell-section');
        $this->assertNotFalse($section, 'l\'assistant doit avoir sa propre section');

        $dernierProfil = strrpos($html, 'data-ai-config-row="profile:');
        $this->assertNotFalse($dernierProfil);

        $this->assertGreaterThan($dernierProfil, $section,
            'la section de l\'assistant ne doit pas vivre dans le bloc des profils');

        $this->assertGreaterThan($section, strpos($html, 'data-ai-shell-form='),
            'et la case vit DANS cette section');
    }

    /** La case dit l'etat REEL, pas le repli. */
    public function test_the_checkbox_reflects_the_stored_state(): void
    {
        $this->eteindreLAssistant();

        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ai-shell-enabled="0"', $html);
    }

    // ── TASK-1693 bug AW : le tableau Organizations x surfaces ───────────────

    private function refuser(array $surfaces): void
    {
        $this->organization->forceFill(['ai_shell_disabled_surfaces' => $surfaces])->save();
        app()->instance('current_organization', $this->organization->fresh());
    }

    private function pageExplorer(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.explorer', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    /** Refuser UNE surface n'eteint QUE celle-la. */
    public function test_refusing_one_surface_only_touches_that_surface(): void
    {
        $this->refuser(['exchanges']);

        $this->assertStringNotContainsString('data-ai-fab-toggle', $this->pageExplorer(),
            'l\'assistant doit disparaitre des Echanges');

        $this->assertStringContainsString('data-ai-fab-toggle', $this->tableauDeBord(),
            'et rester sur le tableau de bord, qui n\'a pas ete refuse');
    }

    /** Aucune surface refusee : tout reste, `null` compris. */
    public function test_no_refused_surface_means_everything_stays(): void
    {
        foreach ([null, []] as $valeur) {
            $this->refuser($valeur ?? []);

            $this->assertStringContainsString('data-ai-fab-toggle', $this->pageExplorer());
            $this->assertStringContainsString('data-ai-fab-toggle', $this->tableauDeBord());
        }
    }

    /**
     * Une surface que la table ne nomme pas n'est JAMAIS refusee.
     *
     * L'inconnu ne se coche pas dans l'ecran d'administration : le refuser
     * eteindrait l'assistant sur des pages que personne n'a choisies.
     */
    public function test_an_unnamed_surface_is_never_refused(): void
    {
        // La valeur `unknown` ne peut pas entrer par l'ecran — la validation
        // n'accepte que les clefs du catalogue. On l'ECRIT donc directement :
        // c'est exactement ce contre quoi la garde protege — une donnee
        // ancienne, un seed, une main sur la base.
        //
        // Ma premiere version refusait `['exchanges', 'dashboard']` et visitait
        // une page inconnue : elle restait VERTE la garde retiree, puisque
        // `unknown` n'etait de toute facon pas dans la liste. Un test qui ne
        // peut pas rougir ne prouve rien.
        $this->refuser(['unknown']);

        // Les favoris ne figurent pas dans `SURFACE_ROUTES` : leur surface est
        // `unknown`.
        $html = $this->actingAs($this->membre)
            ->get(route('organization.favorites.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ai-fab-toggle', $html);
    }

    /** L'interrupteur general PRIME : coupe, aucune case ne rallume rien. */
    public function test_the_master_switch_wins_over_the_boxes(): void
    {
        $this->organization->forceFill([
            'ai_shell_enabled' => false,
            'ai_shell_disabled_surfaces' => [],
        ])->save();
        app()->instance('current_organization', $this->organization->fresh());

        $this->assertStringNotContainsString('data-ai-fab-toggle', $this->tableauDeBord());
    }

    /**
     * Les exclusions EN DUR restent au-dessus du tableau.
     *
     * Cocher « partout » ne doit pas rouvrir la Messagerie (bug AT), qui est
     * un arbitrage produit et non un reglage.
     */
    public function test_the_hard_exclusions_stay_above_the_table(): void
    {
        $this->refuser([]);

        $html = $this->actingAs($this->membre)
            ->get(route('organization.messages.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-ai-fab-toggle', $html);
    }

    /** Le tableau montre une case par surface, et dit l'etat stocke. */
    public function test_the_table_shows_one_box_per_surface(): void
    {
        $this->refuser(['exchanges']);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ai-shell-table', $html);

        foreach (array_keys(\App\Support\Ai\AiShellPageContext::SURFACE_ROUTES) as $surface) {
            $this->assertStringContainsString(
                'data-ai-shell-surface="'.$this->organization->slug.':'.$surface.'"',
                $html,
                "la surface {$surface} doit avoir sa case",
            );
        }

        // Et l'etat STOCKE se lit : « Echanges » decochee, le reste coche.
        $this->assertMatchesRegularExpression(
            '/value="exchanges"[^>]*data-ai-shell-surface="'.$this->organization->slug.':exchanges"(?![^>]*checked)/',
            $html,
        );
    }

    /**
     * Un « tout / rien » par ligne, et l'interrupteur general NOMME.
     *
     * Dix cases a cocher une par une etaient un travail de scribe. Et
     * « Partout » se lisait comme une selection globale alors que c'est
     * l'interrupteur general : la colonne s'appelle desormais « Active ».
     *
     * Le geste est PUREMENT CLIENT — il coche des cases, il n'enregistre rien.
     * Un test serveur ne peut donc verifier que sa PRESENCE sur chaque ligne ;
     * il ne prouvera jamais qu'il coche vraiment. Je le dis plutot que de
     * laisser croire le contraire.
     */
    public function test_each_row_offers_a_select_all(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $lignes = substr_count($html, 'data-ai-shell-row=');

        $this->assertGreaterThan(0, $lignes);
        $this->assertSame($lignes, substr_count($html, 'data-ai-shell-select-all'));
        $this->assertSame($lignes, substr_count($html, 'data-ai-shell-select-none'));

        $this->assertStringContainsString(e(__('admin.ai_shell_surface_everywhere')), $html);
    }

    /** Ecrire le tableau enregistre le COMPLEMENT des cases cochees. */
    public function test_writing_the_table_stores_the_refused_surfaces(): void
    {
        $toutes = array_keys(\App\Support\Ai\AiShellPageContext::SURFACE_ROUTES);
        $allumees = array_values(array_diff($toutes, ['exchanges', 'blog']));

        $this->actingAs($this->superAdmin)
            ->post(route('admin.ai-config.shell'), [
                'organization_id' => $this->organization->id,
                'ai_shell_enabled' => '1',
                'surfaces' => $allumees,
            ])
            ->assertRedirect(route('admin.ai-config'));

        $refusees = $this->organization->fresh()->ai_shell_disabled_surfaces;

        sort($refusees);
        $this->assertSame(['blog', 'exchanges'], $refusees);
    }

    /** Une surface inventee est REFUSEE par la validation. */
    public function test_an_invented_surface_is_rejected(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.ai-config.shell'), [
                'organization_id' => $this->organization->id,
                'ai_shell_enabled' => '1',
                'surfaces' => ['exchanges', 'surface-inventee'],
            ])
            ->assertSessionHasErrors('surfaces.1');
    }

    public function test_the_superadmin_can_write_it(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.ai-config.shell'), [
                'organization_id' => $this->organization->id,
                'ai_shell_enabled' => '0',
            ])
            ->assertRedirect(route('admin.ai-config'))
            ->assertSessionHas('success');

        $this->assertFalse((bool) $this->organization->fresh()->ai_shell_enabled);
    }

    /** L'ecran reste l'administration PLATEFORME. */
    public function test_a_simple_member_cannot_write_it(): void
    {
        $this->actingAs($this->membre)
            ->post(route('admin.ai-config.shell'), [
                'organization_id' => $this->organization->id,
                'ai_shell_enabled' => '0',
            ])
            ->assertForbidden();

        $this->assertTrue((bool) $this->organization->fresh()->ai_shell_enabled);
    }
}
