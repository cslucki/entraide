<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiSurfaceGate;
use App\Support\Loops\LoopCardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AP — ce que le bug AF avait laissé derrière lui.
 *
 * Trois surfaces IA continuaient de s'offrir, interrupteur eteint :
 * « Mes usages IA » dans le menu avatar, la Card « Resume IA » et
 * « Rattrape-moi » dans les Boucles. Meme famille que les six du bug AF :
 * le serveur refusait deja, c'etaient des poignees sur une porte muree.
 *
 * ## Ou la garde est posee, et pourquoi
 *
 * Pour la Card, dans `LoopCardRegistry::visibleCardsFor()` — l'autorite
 * UNIQUE des Cards. `frameCardsFor()`, `chatActionCardsFor()` et
 * `AiFabContext` la lisent tous les trois : la poser dans la vue des actions
 * aurait laisse la Card atteignable par les deux autres chemins.
 *
 * Et le critere est la CATEGORIE declaree au catalogue, pas une liste de
 * clefs : une Card IA ajoutee demain sera gardee sans qu'on y pense.
 */
class TASK1693AiLoopsAndMenuFollowTheSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693ap',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'status' => 'active',
        ]);

        LoopMember::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function allumerLIA(): void
    {
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );

        // Le service memorise sa reponse par Organization.
        app()->forgetInstance(AiSurfaceGate::class);
    }

    private function page(string $url): string
    {
        return $this->actingAs($this->membre)->get($url)->assertOk()->getContent();
    }

    private function urlBoucle(): string
    {
        return route('organization.loops.show', [
            'organization' => $this->organization->slug,
            'loop' => $this->loop->id,
        ]);
    }

    // ── Le menu avatar ───────────────────────────────────────────────────────

    public function test_my_ai_usage_follows_the_switch(): void
    {
        $url = route('organization.dashboard', ['organization' => $this->organization->slug]);

        $this->assertStringNotContainsString('data-nav-ai-usage', $this->page($url));

        $this->allumerLIA();

        $this->assertStringContainsString('data-nav-ai-usage', $this->page($url));
    }

    /** Ce que la garde ne doit PAS emporter : le reste du menu. */
    public function test_the_rest_of_the_menu_is_untouched(): void
    {
        $html = $this->page(route('organization.dashboard', ['organization' => $this->organization->slug]));

        $this->assertStringContainsString(e(__('navigation.settings')), $html);
        $this->assertStringContainsString(e(__('navigation.points_history')), $html);
    }

    // ── La Boucle ────────────────────────────────────────────────────────────

    /**
     * La Card « Resume IA » disparait de l'autorite des Cards elle-meme —
     * donc des TROIS lecteurs a la fois.
     */
    public function test_the_ai_summary_card_leaves_the_registry(): void
    {
        $registre = app(LoopCardRegistry::class);

        $this->assertFalse(
            $registre->chatActionCardsFor($this->loop, $this->membre)->contains('key', 'core.ai_summary'),
            'la Card IA ne doit pas etre offerte quand l\'IA est eteinte',
        );

        $this->allumerLIA();

        $this->assertTrue(
            app(LoopCardRegistry::class)->chatActionCardsFor($this->loop->fresh(), $this->membre)
                ->contains('key', 'core.ai_summary'),
        );
    }

    /** Et les Cards NON-IA de la meme Boucle restent, elles. */
    public function test_the_other_cards_stay(): void
    {
        $cartes = app(LoopCardRegistry::class)->frameCardsFor($this->loop, $this->membre);

        $this->assertGreaterThan(0, $cartes->count(),
            'la garde ne doit emporter que les Cards de categorie « ai »');
        $this->assertFalse($cartes->contains('key', 'core.ai_summary'));
    }

    /** « Rattrape-moi » suit le meme interrupteur. */
    public function test_catch_me_up_follows_the_switch(): void
    {
        $this->assertStringNotContainsString('data-loop-catch-up-link', $this->page($this->urlBoucle()));

        $this->allumerLIA();

        $this->assertStringContainsString('data-loop-catch-up-link', $this->page($this->urlBoucle()));
    }

    /** Et la Boucle reste une Boucle : la conversation n'est pas touchee. */
    public function test_the_loop_itself_is_untouched(): void
    {
        $html = $this->page($this->urlBoucle());

        $this->assertStringContainsString(e($this->loop->name), $html);
    }
}
