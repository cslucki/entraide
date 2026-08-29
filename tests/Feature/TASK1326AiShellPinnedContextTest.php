<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Livewire\AiShell;
use App\Models\AiShellMessage;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1326 (Shell-2) — le contexte epingle du Shell « BouclePro IA ».
 *
 * Contrats prouves ici :
 *
 *  A. GESTE — epingler revalide l'objet par la garde de SA page au moment du
 *     geste : un objet invisible, d'une autre Organization ou d'un kind hors
 *     whitelist n'est jamais epingle. La session ne porte que des references
 *     {kind, id} — jamais un libelle, jamais une URL, jamais un droit. La
 *     limite est bornee ET structurelle.
 *  B. NAVIGATION — les pins survivent d'une page a l'autre (le fil du Shell
 *     aussi), et l'utilisateur voit exactement ce qui est epingle, avec des
 *     noms RELUS a l'instant.
 *  C. REVALIDATION — un pin dont l'objet ne passe plus sa garde disparait de
 *     l'ecran ET de la session ; une reference forgee (autre Organization,
 *     kind inconnu) n'est jamais rendue.
 *  D. INJECTION — un tour recoit EXACTEMENT la liste affichee (labels relus),
 *     tracee en metadata par identifiants seuls ; un pin devenu inaccessible
 *     n'est ni injecte ni trace ; sans pin, le tour est inchange. Aucune
 *     source cachee.
 */
class TASK1326AiShellPinnedContextTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $memberA;

    private User $strangerA;

    private User $memberB;

    private Loop $loopA;

    private Loop $loopA2;

    private Loop $loopB;

    private Dossier $dossierA;

    private BlogPost $articleA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create(['is_active' => true, 'slug' => 'org-pins-a', 'name' => 'Org Pins A']);
        $this->organizationB = Organization::factory()->create(['is_active' => true, 'slug' => 'org-pins-b', 'name' => 'Org Pins B']);

        foreach ([$this->organizationA, $this->organizationB] as $organization) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $organization->id,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => 'sk-task1326-'.$organization->id,
                'monthly_budget_usd' => 5.00,
            ]);
        }

        $this->memberA = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Ada', 'name' => 'Pins']);
        $this->strangerA = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Sam', 'name' => 'Pins']);
        $this->memberB = User::factory()->complete()->create(['organization_id' => $this->organizationB->id, 'first_name' => 'Bo', 'name' => 'Pins']);

        app()->instance('current_organization', $this->organizationA);

        $loops = new LoopService;
        // « Bis » et non « A2 » : aucun nom n'est le prefixe d'un autre — le
        // prompt du clarifier embarque le contexte borne (dont les Boucles de
        // l'utilisateur), une sous-chaine ferait un faux positif.
        $this->loopA = $loops->createLoop($this->memberA, 'Boucle Pins A');
        $this->loopA2 = $loops->createLoop($this->memberA, 'Boucle Bis Pins');

        app()->instance('current_organization', $this->organizationB);
        $this->loopB = $loops->createLoop($this->memberB, 'Boucle Pins B');
        app()->instance('current_organization', $this->organizationA);

        $this->dossierA = Dossier::factory()->create([
            'organization_id' => $this->organizationA->id,
            'owner_id' => $this->memberA->id,
            'name' => 'Dossier Pins A',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        // L'Article appartient a strangerA : depublie, memberA n'y a plus
        // aucun titre d'acces — le cas « stale permission » documentaire.
        $this->articleA = BlogPost::create([
            'organization_id' => $this->organizationA->id,
            'user_id' => $this->strangerA->id,
            'title' => 'Article Pins A',
            'slug' => 'article-pins-a',
            'content' => 'Contenu publie.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.chatloop.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Geste : epingler revalide, la session ne porte que des references
    // =====================================================================

    public function test_pinning_stores_identifiers_only_and_renders_a_freshly_resolved_chip(): void
    {
        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->call('pin', 'loop', (string) $this->loopA->id);

        // La session ne porte que {kind, id} — le nom et l'URL n'existent
        // qu'a l'ecran, relus a chaque rendu.
        $this->assertSame(
            [['kind' => 'loop', 'id' => (string) $this->loopA->id]],
            Session::get('ai_shell.pins.'.$this->organizationA->id),
        );

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertSee('data-ai-shell-pins', false)
            ->assertSee('data-ai-shell-pin="loop:'.$this->loopA->id.'"', false)
            ->assertSee('Boucle Pins A')
            ->assertSee(__('ai.shell_pins_note'))
            ->assertSee('data-ai-shell-pin-remove', false);
    }

    public function test_pinning_an_invisible_foreign_or_unknown_object_is_refused(): void
    {
        $foreignDossier = Dossier::factory()->create([
            'organization_id' => $this->organizationA->id,
            'owner_id' => $this->memberA->id,
            'name' => 'Dossier Prive d\'Ada',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $component = Livewire::actingAs($this->strangerA)->test(AiShell::class);

        // Un Dossier qu'on ne peut pas voir, une Boucle d'une autre
        // Organization, un kind hors whitelist, un identifiant inconnu :
        // AUCUN n'entre en session.
        $component->call('pin', 'dossier', (string) $foreignDossier->id);
        $component->call('pin', 'loop', (string) $this->loopB->id);
        $component->call('pin', 'person', (string) $this->memberA->id);
        $component->call('pin', 'loop', (string) Str::uuid());

        $this->assertNull(Session::get('ai_shell.pins.'.$this->organizationA->id));

        Livewire::actingAs($this->strangerA)->test(AiShell::class)
            ->assertDontSee('data-ai-shell-pin=', false)
            ->assertDontSee('Dossier Prive d\'Ada')
            ->assertDontSee('Boucle Pins B');
    }

    public function test_the_pin_limit_is_enforced_and_structural(): void
    {
        $component = Livewire::actingAs($this->memberA)->test(AiShell::class);

        $component->call('pin', 'loop', (string) $this->loopA->id);
        $component->call('pin', 'dossier', (string) $this->dossierA->id);
        $component->call('pin', 'article', (string) $this->articleA->id);

        // Le quatrieme est refuse, avec le message — et la session n'a pas bouge.
        $component->call('pin', 'loop', (string) $this->loopA2->id)
            ->assertSet('notice', __('ai.shell_pin_limit_reached', ['max' => 3]));

        $this->assertCount(3, Session::get('ai_shell.pins.'.$this->organizationA->id));

        // La borne est STRUCTURELLE : une session qui excede la limite ne rend
        // jamais plus que la limite.
        Session::put('ai_shell.pins.'.$this->organizationA->id, [
            ['kind' => 'loop', 'id' => (string) $this->loopA->id],
            ['kind' => 'dossier', 'id' => (string) $this->dossierA->id],
            ['kind' => 'article', 'id' => (string) $this->articleA->id],
            ['kind' => 'loop', 'id' => (string) $this->loopA2->id],
        ]);

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertSee('data-ai-shell-pins-count="3"', false)
            ->assertDontSee('Boucle Bis Pins');
    }

    public function test_unpin_removes_the_reference_and_a_forged_unpin_is_harmless(): void
    {
        $component = Livewire::actingAs($this->memberA)->test(AiShell::class);
        $component->call('pin', 'loop', (string) $this->loopA->id);
        $component->call('pin', 'article', (string) $this->articleA->id);

        $component->call('unpin', 'loop', (string) $this->loopA->id);

        $this->assertSame(
            [['kind' => 'article', 'id' => (string) $this->articleA->id]],
            Session::get('ai_shell.pins.'.$this->organizationA->id),
        );

        // Retirer ce qui n'existe pas ne fait rien — deux fois.
        $component->call('unpin', 'dossier', (string) Str::uuid());
        $component->call('unpin', 'weird', 'x');

        $this->assertCount(1, Session::get('ai_shell.pins.'.$this->organizationA->id));

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertDontSee('data-ai-shell-pin="loop:', false)
            ->assertSee('data-ai-shell-pin="article:'.$this->articleA->id.'"', false);
    }

    // =====================================================================
    // B. Navigation : les pins suivent l'utilisateur de page en page
    // =====================================================================

    public function test_pins_survive_navigation_and_the_pin_button_follows_the_page_object(): void
    {
        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->call('pin', 'loop', (string) $this->loopA->id);

        // Page Boucle (l'objet epingle) : la puce est la, le bouton d'epingle
        // n'est PAS propose pour un objet deja epingle.
        $this->actingAs($this->memberA)->get($this->loopUrl($this->loopA))
            ->assertOk()
            ->assertSee('data-ai-shell-pin="loop:'.$this->loopA->id.'"', false)
            ->assertSee('Boucle Pins A')
            ->assertDontSee('data-ai-shell-pin-add', false);

        // Tableau de bord (aucun objet) : la puce survit, aucun bouton.
        $this->actingAs($this->memberA)->get(route('organization.dashboard', ['organization' => $this->organizationA->slug]))
            ->assertOk()
            ->assertSee('data-ai-shell-pin="loop:'.$this->loopA->id.'"', false)
            ->assertDontSee('data-ai-shell-pin-add', false);

        // Page d'une AUTRE Boucle, non epinglee : la puce survit ET le bouton
        // propose d'epingler l'objet de la page courante — jamais un autre.
        $this->actingAs($this->memberA)->get($this->loopUrl($this->loopA2))
            ->assertOk()
            ->assertSee('data-ai-shell-pin="loop:'.$this->loopA->id.'"', false)
            ->assertSee('data-ai-shell-pin-add', false)
            ->assertSee("pin('loop', '".$this->loopA2->id."')", false);
    }

    // =====================================================================
    // C. Revalidation : ce qui ne passe plus n'existe plus
    // =====================================================================

    public function test_a_pinned_object_that_becomes_inaccessible_disappears_and_is_pruned(): void
    {
        $component = Livewire::actingAs($this->memberA)->test(AiShell::class);
        $component->call('pin', 'loop', (string) $this->loopA->id);
        $component->call('pin', 'article', (string) $this->articleA->id);

        // Sortie de la Boucle : la garde de la page ne passe plus.
        LoopMember::query()->where('loop_id', $this->loopA->id)->where('user_id', $this->memberA->id)->delete();

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertDontSee('Boucle Pins A</a>', false)
            ->assertDontSee('data-ai-shell-pin="loop:', false)
            ->assertSee('Article Pins A');

        // Et la session a ete elaguee au meme rendu : ce que l'utilisateur
        // voit est ce qui est stocke.
        $this->assertSame(
            [['kind' => 'article', 'id' => (string) $this->articleA->id]],
            Session::get('ai_shell.pins.'.$this->organizationA->id),
        );

        // L'Article de strangerA depublie : memberA n'a plus aucun titre.
        $this->articleA->forceFill(['status' => 'draft'])->saveQuietly();

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertDontSee('Article Pins A')
            ->assertDontSee('data-ai-shell-pins', false);

        $this->assertSame([], Session::get('ai_shell.pins.'.$this->organizationA->id));
    }

    public function test_forged_session_references_never_cross_the_organization_nor_the_whitelist(): void
    {
        // Une session forgee : Boucle d'une autre Organization, kind inconnu,
        // entree malformee. Rien ne se nomme, tout est elague.
        Session::put('ai_shell.pins.'.$this->organizationA->id, [
            ['kind' => 'loop', 'id' => (string) $this->loopB->id],
            ['kind' => 'person', 'id' => (string) $this->memberB->id],
            'pas-un-tableau',
        ]);

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertDontSee('data-ai-shell-pin=', false)
            ->assertDontSee('Boucle Pins B')
            ->assertDontSee('Bo Pins');

        $this->assertSame([], Session::get('ai_shell.pins.'.$this->organizationA->id));
    }

    // =====================================================================
    // D. Injection : exactement la liste affichee, tracee par identifiants
    // =====================================================================

    public function test_pinned_labels_are_injected_into_the_turn_and_traced_as_identifiers_only(): void
    {
        $component = Livewire::actingAs($this->memberA)->test(AiShell::class);
        $component->call('pin', 'loop', (string) $this->loopA->id);
        $component->call('pin', 'article', (string) $this->articleA->id);

        $this->fakeClarifier();

        $component->set('draft', 'Ma question epinglee.')->call('send');

        // Le prompt recoit l'intro du contexte epingle et les NOMS relus des
        // deux objets dans le LIBELLE EXACT de la ligne d'epinglage — le
        // contexte borne du clarifier porte aussi des noms de Boucles, seule
        // cette formulation prouve l'injection des pins.
        $intro = trim(Str::before(__('ai.shell_prompt_pinned', ['items' => 'ZZZ']), 'ZZZ'));

        HelpRequestClarifierAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => $prompt->contains($intro)
                && $prompt->contains(__('ai.shell_prompt_pinned_loop', ['name' => 'Boucle Pins A']))
                && $prompt->contains(__('ai.shell_prompt_pinned_article', ['name' => 'Article Pins A']))
                && $prompt->contains('Ma question epinglee.'),
        );

        // La trace du tour : des identifiants, sur le declencheur ET la
        // reponse — de quoi relire quels pins etaient en vigueur, jamais un
        // libelle, jamais un droit.
        $expected = [
            ['kind' => 'loop', 'id' => (string) $this->loopA->id],
            ['kind' => 'article', 'id' => (string) $this->articleA->id],
        ];

        $trigger = AiShellMessage::query()->where('role', AiShellMessage::ROLE_USER)->firstOrFail();
        $answer = AiShellMessage::query()->where('role', AiShellMessage::ROLE_ASSISTANT)->firstOrFail();

        foreach ([$trigger, $answer] as $message) {
            $trace = $message->metadata['pinned_context'];

            // La garantie « identifiants seuls » : chaque entree porte
            // EXACTEMENT kind et id, aucune autre cle — c'est elle qui
            // interdit un libelle dans la trace. L'ordre des cles, lui,
            // appartient au driver (le jsonb PostgreSQL ne le preserve pas) :
            // on ne l'asserte jamais.
            foreach ($trace as $pin) {
                $keys = array_keys($pin);
                sort($keys);
                $this->assertSame(['id', 'kind'], $keys);
            }

            $this->assertSame($expected, array_map(
                fn (array $pin): array => ['kind' => (string) $pin['kind'], 'id' => (string) $pin['id']],
                $trace,
            ));
        }
    }

    public function test_a_stale_pin_is_neither_injected_nor_traced(): void
    {
        $component = Livewire::actingAs($this->memberA)->test(AiShell::class);
        $component->call('pin', 'loop', (string) $this->loopA->id);

        LoopMember::query()->where('loop_id', $this->loopA->id)->where('user_id', $this->memberA->id)->delete();

        $this->fakeClarifier();

        $component->set('draft', 'Ma question.')->call('send');

        // Ni l'intro du contexte epingle, ni la ligne d'epinglage de la
        // Boucle : le pin mort n'a laisse aucune trace dans le prompt.
        $intro = trim(Str::before(__('ai.shell_prompt_pinned', ['items' => 'ZZZ']), 'ZZZ'));

        HelpRequestClarifierAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => ! $prompt->contains($intro)
                && ! $prompt->contains(__('ai.shell_prompt_pinned_loop', ['name' => 'Boucle Pins A']))
                && $prompt->contains('Ma question.'),
        );

        $trigger = AiShellMessage::query()->where('role', AiShellMessage::ROLE_USER)->firstOrFail();
        $this->assertArrayNotHasKey('pinned_context', (array) $trigger->metadata);

        // Le pin mort a ete retire a l'usage : la session est propre.
        $this->assertSame([], Session::get('ai_shell.pins.'.$this->organizationA->id));
    }

    public function test_without_pins_the_turn_is_unchanged(): void
    {
        $this->fakeClarifier();

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Ma question nue.')
            ->call('send');

        $intro = trim(Str::before(__('ai.shell_prompt_pinned', ['items' => 'ZZZ']), 'ZZZ'));

        HelpRequestClarifierAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => ! $prompt->contains($intro)
                && $prompt->contains('Ma question nue.'),
        );

        $trigger = AiShellMessage::query()->where('role', AiShellMessage::ROLE_USER)->firstOrFail();
        $answer = AiShellMessage::query()->where('role', AiShellMessage::ROLE_ASSISTANT)->firstOrFail();

        $this->assertArrayNotHasKey('pinned_context', (array) $trigger->metadata);
        $this->assertArrayNotHasKey('pinned_context', (array) $answer->metadata);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function loopUrl(Loop $loop): string
    {
        return route('organization.loops.show', ['organization' => $this->organizationA->slug, 'loop' => $loop->id]);
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Relecture Erasmus',
            'clarified_request' => 'Trouver un relecteur pour le dossier Erasmus.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake([
            new StructuredTextResponse(
                $structured,
                json_encode($structured, JSON_UNESCAPED_UNICODE),
                new Usage(120, 80),
                new Meta('openai', 'gpt-4o-mini'),
            ),
        ]);
    }
}
