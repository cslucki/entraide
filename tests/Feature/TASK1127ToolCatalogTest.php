<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopPresetConfigurator;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le catalogue premium des outils : identite visuelle et apercus.
 *
 * Ce que TASK-1127 ajoute est presque entierement visuel ; ce qui se teste
 * est ce qui peut se defaire sans bruit :
 *
 * - l'unicite des icones — le point de depart etait cinq outils partageant
 *   le meme dessin, et rien n'empechait d'y revenir ;
 * - l'exposition de l'icone par `describe()` — sans elle la vue retombe sur
 *   le dessin par defaut pour tout le monde, silencieusement ;
 * - le vocabulaire humain, deja verrouille par TASK-1125, que les apercus ne
 *   doivent pas percer ;
 * - les invariants metier (non-destruction, prerequis) que le redesign ne
 *   doit pas avoir deplaces.
 */
class TASK1127ToolCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        // Organization non-default : le catalogue doit fonctionner ailleurs
        // que sur `main`, et une fuite de composition se verrait ici.
        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create([
            'is_active' => true,
            'loops_enabled' => true,
            'loop_composition_policy' => 'owner_allowed',
        ]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->owner->id,
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->loop);
        LoopMember::factory()->owner()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->owner->id, 'joined_at' => now(),
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function pageOutils(): string
    {
        return $this->actingAs($this->owner)
            ->get(route('organization.loops.tools', ['organization' => $this->org->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->getContent();
    }

    // ── Identite visuelle ───────────────────────────────────────────────────

    public function test_every_tool_has_its_own_icon(): void
    {
        $cards = config('loop_cards.cards');

        $icones = collect($cards)->map(fn ($c) => $c['icon'] ?? null);

        $this->assertNotContains(null, $icones->all(), 'un outil sans icone retombe sur le dessin generique');

        $doublons = $icones->countBy()->filter(fn ($n) => $n > 1);

        $this->assertSame(
            [],
            $doublons->all(),
            'des outils partagent une icone : '.$doublons->keys()->implode(', ')
            .' — le catalogue redevient illisible sans lire les titres',
        );
    }

    public function test_every_declared_icon_is_actually_drawable(): void
    {
        // Le composant dessine ce qu'il connait et retombe sur `document`
        // sinon. Une icone declaree mais inconnue passerait inapercue : tout
        // s'affiche, en generique.
        $composant = file_get_contents(resource_path('views/components/loops/card-icon.blade.php'));

        foreach (config('loop_cards.cards') as $cle => $card) {
            $this->assertStringContainsString(
                "'".$card['icon']."' =>",
                $composant,
                "l'icone `{$card['icon']}` de {$cle} n'a pas de trace dans x-loops.card-icon",
            );
        }
    }

    public function test_describe_exposes_the_icon_of_each_tool(): void
    {
        $composition = app(LoopPresetConfigurator::class)->describe($this->loop);

        foreach (['primary', 'secondary', 'available'] as $zone) {
            foreach ($composition[$zone] as $outil) {
                $this->assertArrayHasKey('icon', $outil, "pas d'icone exposee en zone {$zone}");
                $this->assertNotNull($outil['icon'], "icone nulle pour {$outil['key']}");
            }
        }
    }

    public function test_the_registry_resolves_an_icon_and_survives_an_unknown_key(): void
    {
        $registry = app(LoopCardRegistry::class);

        $this->assertSame('bars', $registry->iconOf('core.polls'));
        $this->assertNull($registry->iconOf('core.inexistant'));
    }

    // ── Le catalogue rendu ──────────────────────────────────────────────────

    public function test_the_page_shows_previews_in_the_available_zone(): void
    {
        // Des outils reellement disponibles dans le preset `general` — les
        // Sondages, eux, y sont deja actifs et n'ont donc pas d'apercu. Et
        // `assertSee`, pas une comparaison brute : « Lancer l'idee » porte une
        // apostrophe, que Blade echappe (lecon de TASK-1125).
        $this->actingAs($this->owner)
            ->get(route('organization.loops.tools', ['organization' => $this->org->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->assertSee(__('loops.tool_previews.roadmap_step_1'))
            ->assertSee(__('loops.tool_previews.decisions_title'))
            ->assertSee(__('loops.tool_previews.quiz_question'));
    }

    public function test_previews_are_decorative_and_inert(): void
    {
        $html = $this->pageOutils();

        // Chaque silhouette est declaree decorative et insensible au clic :
        // le sens reste porte par le titre et la description.
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('pointer-events-none', $html);

        // Et une silhouette ne contient aucun bouton ni lien : ce qui se
        // clique vit sur la carte, jamais dans l'apercu.
        preg_match_all('/aria-hidden="true"[^>]*class="[^"]*pointer-events-none[^"]*"(.*?)<\/div>/s', $html, $captures);
        foreach ($captures[1] as $silhouette) {
            $this->assertStringNotContainsString('<button', $silhouette);
            $this->assertStringNotContainsString('<a ', $silhouette);
            $this->assertStringNotContainsString('<form', $silhouette);
        }
    }

    public function test_the_human_vocabulary_lock_still_holds_with_previews(): void
    {
        // Meme verrou que TASK-1125, sur la page enrichie : apres retrait des
        // attributs HTML, aucune cle technique ne s'affiche.
        $html = $this->pageOutils();
        $visible = preg_replace('/<[^>]+>/', ' ', $html);

        foreach (['primary_rank', 'grid_slots', 'core.polls', 'documentary', 'pedagogy', 'rhythm'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $visible, "« {$interdit} » s'affiche");
        }
    }

    public function test_a_blocked_tool_stays_visible_and_says_why(): void
    {
        $html = $this->pageOutils();

        // Progression exige Support de cours : la carte reste au catalogue,
        // dit le prerequis en toutes lettres, et son bouton est inerte.
        $this->assertStringContainsString(__('loops.cards.progression.label'), $html);
        $this->assertStringContainsString(__('loops.tools_catalog_state_blocked'), $html);
        $this->assertStringContainsString(__('loops.cards.course_material.label'), $html);
        $this->assertStringNotContainsString('training.course_material</', $html);
    }

    public function test_a_deactivated_tool_with_content_says_it_waits(): void
    {
        $configurator = app(LoopPresetConfigurator::class);
        $configurator->enable($this->owner, $this->loop, 'core.polls');

        $poll = \App\Models\LoopPoll::create([
            'loop_id' => $this->loop->id,
            'created_by' => $this->owner->id,
            'question' => 'Le contenu qui attend',
            'status' => 'open',
        ]);

        $configurator->disable($this->owner, $this->loop, 'core.polls');

        // La carte du catalogue le dit : rien n'a ete perdu.
        $this->assertStringContainsString(
            trans_choice('loops.tools_catalog_has_content', 1, ['count' => 1]),
            $this->pageOutils(),
        );

        $this->assertDatabaseHas('loop_polls', ['id' => $poll->id, 'question' => 'Le contenu qui attend']);
    }

    // ── Les gestes n'ont pas bouge ──────────────────────────────────────────

    public function test_the_four_actions_still_go_through_the_same_endpoint(): void
    {
        $url = route('organization.loops.tools.update', ['organization' => $this->org->slug, 'loop' => $this->loop->id]);

        $this->actingAs($this->owner)->post($url, ['action' => 'enable', 'tool' => 'core.polls'])
            ->assertRedirect()->assertSessionHas('success');

        $this->actingAs($this->owner)->post($url, ['action' => 'promote', 'tool' => 'core.polls'])
            ->assertRedirect()->assertSessionHas('success');

        $this->actingAs($this->owner)->post($url, ['action' => 'demote', 'tool' => 'core.polls'])
            ->assertRedirect();

        $this->actingAs($this->owner)->post($url, ['action' => 'disable', 'tool' => 'core.polls'])
            ->assertRedirect()->assertSessionHas('success');
    }

    public function test_the_demote_wording_no_longer_reads_like_a_deletion(): void
    {
        // « Retirer des outils principaux » se lisait comme une suppression.
        $this->assertSame('Ne plus mettre en avant', __('loops.tools_demote', [], 'fr'));
    }

    public function test_a_refusal_lands_in_the_card_of_the_gesture(): void
    {
        $url = route('organization.loops.tools.update', ['organization' => $this->org->slug, 'loop' => $this->loop->id]);
        $cfg = app(LoopPresetConfigurator::class);
        $cfg->enable($this->owner, $this->loop, 'core.roadmap');
        $cfg->enable($this->owner, $this->loop, 'core.journal');
        // Cinq actifs, trois derives mis en avant : en promouvoir un quatrieme
        // doit refuser — et le refus doit s'ancrer sur l'outil du geste.
        $reponse = $this->actingAs($this->owner)->post($url, ['action' => 'promote', 'tool' => 'core.journal']);

        $reponse->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHas('error_tool', 'core.journal');

        // Et la page le rend en alerte accessible, dans la carte, pas en
        // banniere anonyme en haut de page.
        // Le driver `array` des tests ne fait pas survivre la flash au
        // redirect suivi : on seme la session explicitement pour verifier le
        // contrat de la vue — l'alerte se rend dans la carte du geste.
        $html = $this->actingAs($this->owner)
            ->withSession(['error' => 'Le refus du service', 'error_tool' => 'core.journal'])
            ->get(route('organization.loops.tools', ['organization' => $this->org->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Le refus du service', $html);

        // Et l'alerte vit bien dans la carte de Journal : dans le fragment
        // entre son titre et l'article suivant.
        $position = strpos($html, __('loops.cards.journal.label'));
        $fragment = substr($html, $position, strpos($html, '</article>', $position) - $position);
        $this->assertStringContainsString('role="alert"', $fragment);
    }

    public function test_a_success_signals_the_card_that_changed(): void
    {
        $url = route('organization.loops.tools.update', ['organization' => $this->org->slug, 'loop' => $this->loop->id]);

        $reponse = $this->actingAs($this->owner)->post($url, ['action' => 'enable', 'tool' => 'core.journal']);

        $reponse->assertRedirect()
            ->assertSessionHas('success_tool', 'core.journal')
            ->assertSessionHas('success_action', 'enable');

        // Le halo emeraude s'attache a la carte arrivee dans sa zone —
        // session semee explicitement, meme raison que ci-dessus.
        $html = $this->actingAs($this->owner)
            ->withSession(['success' => 'ok', 'success_tool' => 'core.journal', 'success_action' => 'enable'])
            ->get(route('organization.loops.tools', ['organization' => $this->org->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ring-emerald-400', $html);
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_the_catalogue_of_another_organization_is_out_of_reach(): void
    {
        $autreOrg = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true,
            'loop_composition_policy' => 'owner_allowed',
        ]);
        $autreLoop = Loop::factory()->create([
            'organization_id' => $autreOrg->id, 'status' => 'active', 'type' => 'general',
        ]);

        // La Boucle d'une autre Organization n'existe pas depuis la mienne,
        // meme pour son URL d'outils, meme en forgeant l'action.
        $this->actingAs($this->owner)
            ->get(route('organization.loops.tools', ['organization' => $this->org->slug, 'loop' => $autreLoop->id]))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->post(route('organization.loops.tools.update', ['organization' => $this->org->slug, 'loop' => $autreLoop->id]), [
                'action' => 'enable', 'tool' => 'core.polls',
            ])
            ->assertNotFound();
    }
}
