<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1475 — l'IA de la Boucle redevient visible sur mobile.
 *
 * ## D'ou vient ce manque
 *
 * TASK-1466 a retire le Shell global des Boucles, et c'etait juste : la Boucle
 * porte deja son IA, le Shell y ouvrait une seconde porte vers les memes
 * actions.
 *
 * Mais la mesure a 390 px, faite dans la foulee, montrait ensuite **zero
 * affordance IA visible** : la seule porte etait un bouton « Plus d'actions »
 * qui ne nomme pas l'IA, et qu'il fallait ouvrir pour la trouver. Sur desktop
 * l'action est dans la barre ; sur mobile elle avait quitte le champ de vision.
 *
 * ## Ce que ce bouton n'est pas
 *
 * Il n'ajoute AUCUNE capacite. Il actionne exactement le meme interrupteur que
 * la feuille (`toggleComposerEngine('ia')`), sur le MEME composant Livewire,
 * avec les memes gardes. Il ne monte pas le Shell global — l'invariant de
 * TASK-1466 tient, et ce fichier le mesure explicitement.
 *
 * ## Le piege evite, mesure avant livraison
 *
 * La couleur active etait d'abord ecrite `bg-[var(--bp-primary,#4f46e5)]` —
 * une valeur arbitraire Tailwind, absente du build. `getComputedStyle` rendait
 * `rgba(0,0,0,0)` : le bouton actif etait TRANSPARENT alors que la classe
 * figurait bien dans le HTML. La regle vit desormais dans une feuille locale.
 *
 * C'est la quatrieme fois de la nuit que ce motif apparait ; cette fois il a
 * ete vu avant le commit, en mesurant le style CALCULE et non la chaine.
 */
class TASK1475LoopMobileAiVisibleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-ux-mobile',
            'name' => 'Org UX Mobile',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1475',
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        app()->instance('current_organization', $this->organization);

        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle Mobile');

        config([
            'ai.chatloop.enabled' => true,
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. L'action est la, et elle se nomme
    // =====================================================================

    public function test_the_loop_page_offers_a_named_ai_action_without_opening_a_sheet(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<button[^>]*data-engine-quick="ia"[^>]*>/', $html, $button),
            'le raccourci IA du composeur est rendu');

        // Il se NOMME : un bouton « Plus d'actions » ne disait pas qu'il cachait l'IA.
        $this->assertStringContainsString('aria-label="'.e(__('loops.ask_ai_button')).'"', $button[0]);

        // Et il porte son etat, comme son jumeau de la feuille.
        $this->assertStringContainsString('aria-pressed="false"', $button[0]);
    }

    /** Mobile seulement : la barre desktop porte deja l'action. */
    public function test_it_is_mobile_only(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<button[^>]*data-engine-quick="ia"[^>]*>/', $html, $button));
        $this->assertStringContainsString('md:hidden', $button[0], 'le desktop n\'a pas besoin de ce raccourci');
    }

    // =====================================================================
    // B. Aucune seconde autorite : le meme interrupteur
    // =====================================================================

    /**
     * Le raccourci et la feuille commandent le MEME etat. S'ils divergeaient,
     * l'utilisateur verrait deux boutons IA se contredire.
     */
    public function test_the_shortcut_drives_the_same_engine_state_as_the_sheet(): void
    {
        // L'etat vit dans `composerMode` : `engineActive` est une variable de
        // VUE derivee du mode, pas une propriete Livewire. Asserter
        // `engineActive.ia` rendait `null` — vrai pour une mauvaise raison si
        // l'assertion avait ete plus permissive.
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSet('composerMode', 'normal')
            ->call('toggleComposerEngine', 'ia')
            ->assertSet('composerMode', 'ia')
            ->call('toggleComposerEngine', 'ia')
            ->assertSet('composerMode', 'normal');
    }

    /**
     * L'attribut est DISTINCT de `data-engine-toggle` : la recette e2e cible ce
     * dernier par `:visible` et prend la premiere correspondance. Un second
     * element portant la meme valeur rendrait ce ciblage ambigu.
     */
    public function test_the_shortcut_does_not_borrow_the_sheet_attribute(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<button[^>]*data-engine-quick="ia"[^>]*>/', $html, $button));
        $this->assertStringNotContainsString('data-engine-toggle', $button[0]);

        // Et les interrupteurs historiques restent exactement au nombre de deux
        // par moteur — un dans la barre desktop, un dans la feuille mobile.
        $this->assertSame(2, substr_count($html, 'data-engine-toggle="ia"'), 'barre desktop + feuille mobile');
        $this->assertSame(2, substr_count($html, 'data-engine-toggle="dossiers"'));
        $this->assertSame(1, substr_count($html, 'data-engine-quick="ia"'), 'le raccourci est unique');
    }

    // =====================================================================
    // C. L'invariant de TASK-1466 tient
    // =====================================================================

    public function test_no_global_shell_comes_back_with_it(): void
    {
        $this->actingAs($this->member)->get($this->loopUrl())
            ->assertOk()
            ->assertDontSee('data-ai-shell-panel', false)
            ->assertDontSee('data-ai-fab', false)
            ->assertDontSee('bp-open-ai-shell', false);
    }

    /** Et l'IA native de la page n'a pas bouge. */
    public function test_the_native_loop_ai_is_untouched(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        $this->assertStringContainsString('@bp-open-ask-ai.window', $html);
        $this->assertStringContainsString(e(__('loops.ask_ai_button')), $html);
    }

    // =====================================================================
    // D. La couleur active existe reellement
    // =====================================================================

    /**
     * Une classe utilitaire arbitraire absente du build laisserait le bouton
     * actif TRANSPARENT, sans un mot. La regle doit donc vivre dans une feuille
     * rendue avec la page, et lire le token du theme avec un repli.
     */
    public function test_the_active_colour_does_not_depend_on_the_css_build(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/\.bp-loop-ai-quick\[aria-pressed="true"\]\s*\{[^}]*background:\s*var\(--bp-primary,\s*#[0-9a-f]{3,8}\)/i',
            $html,
            'la couleur active est servie avec la page, et lit le token avec un repli',
        );

        $this->assertSame(1, preg_match('/<button[^>]*data-engine-quick="ia"[^>]*>/', $html, $button));
        $this->assertStringNotContainsString('bg-[var(', $button[0], 'aucune valeur arbitraire Tailwind sur ce bouton');
    }

    private function loopUrl(): string
    {
        return route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop->id]);
    }
}
