<?php

namespace Tests\Feature;

use App\Livewire\AiShell;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Ai\AiFabContext;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1363 — le Shell TEND les actions que la page autorise deja.
 *
 * ## Le defaut
 *
 * `AiShell::actions()` appelait DEJA `AiFabContext::loopActions()`, puis jetait
 * trois actions sur quatre avec un `firstWhere()`. Sur une page de Dossier il
 * n'en offrait aucune, uniquement parce que `dossierActions()` etait privee.
 * Depuis T1359 le Shell ENONCE pourtant ces actions en toutes lettres : il
 * imprimait le nom du bouton et jetait le bouton.
 *
 * ## L'invariant qui gouverne ce fichier
 *
 * `AiFabContext` reste l'UNIQUE autorite des actions. Aucune garde n'est
 * ecrite dans le Shell : il relaie ce que l'autorite rend, et rien d'autre.
 * Un non-membre recoit `[]`, un Dossier refuse rend `[]`, et le Shell n'a donc
 * rien a decider.
 *
 * Le lieu fournit les actions possibles. L'humain choisit laquelle declencher.
 */
class TASK1363ContextToActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private User $outsider;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-c2a',
            'name' => 'Org Context To Action',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1363-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        $this->member = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->outsider = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle Context To Action');

        app()->instance('current_organization', $this->organization);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.chatloop.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Les actions de la page arrivent enfin dans le Shell
    // =====================================================================

    /**
     * 1. Un membre autorise recoit TOUTES les actions que l'autorite rend —
     *    plus une seule sur quatre.
     */
    public function test_an_authorised_member_receives_every_action_the_authority_returns(): void
    {
        $expected = app(AiFabContext::class)->loopActions($this->loop, $this->member);

        $this->assertNotEmpty($expected, 'Le pre-requis du test : l\'autorite rend bien des actions ici.');

        $actions = $this->shellActions(AiShellPageContext::KIND_LOOP, (string) $this->loop->id);

        $this->assertCount(count($expected), $actions);
    }

    /**
     * 2. Et ce sont EXACTEMENT les evenements de l'autorite.
     *
     * Aucun evenement invente : un evenement que le Shell fabriquerait
     * n'aurait aucun ecouteur, et produirait un bouton silencieusement mort.
     */
    public function test_the_dispatched_events_are_exactly_the_ones_the_authority_produces(): void
    {
        $expected = collect(app(AiFabContext::class)->loopActions($this->loop, $this->member))
            ->pluck('event')->sort()->values()->all();

        $actual = collect($this->shellActions(AiShellPageContext::KIND_LOOP, (string) $this->loop->id))
            ->pluck('event')->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    /**
     * 3. Le `detail` d'une action est TRANSMIS.
     *
     * Le Resume de Boucle porte le nom de la Card a ouvrir. La vue l'ecrasait
     * par `{}` : le bouton partait, et n'ouvrait rien.
     */
    public function test_an_action_detail_is_carried_through(): void
    {
        $fromAuthority = collect(app(AiFabContext::class)->loopActions($this->loop, $this->member))
            ->firstWhere('key', AiFabContext::ACTION_LOOP_SUMMARY);

        if (! is_array($fromAuthority)) {
            $this->markTestSkipped('Le Resume n\'est pas place dans cette Boucle : rien a transmettre.');
        }

        $shellAction = collect($this->shellActions(AiShellPageContext::KIND_LOOP, (string) $this->loop->id))
            ->firstWhere('key', 'shell_'.AiFabContext::ACTION_LOOP_SUMMARY);

        $this->assertNotNull($shellAction);
        $this->assertSame($fromAuthority['detail'], $shellAction['detail']);
    }

    /** 4. Une page de Dossier autorisee rend enfin son action. */
    public function test_an_authorised_dossier_page_now_offers_its_action(): void
    {
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier Context To Action',
        ]);

        $expected = app(AiFabContext::class)->dossierActions($dossier, $this->member);

        $actions = $this->shellActions(AiShellPageContext::KIND_DOSSIER, (string) $dossier->id);

        $this->assertCount(count($expected), $actions);
    }

    // =====================================================================
    // B. Ce que l'autorite refuse, le Shell ne l'offre pas
    // =====================================================================

    /**
     * 5. Un NON-MEMBRE ne recoit aucune action de Boucle.
     *
     * Et il ne la recoit pas parce que `loopActions()` rend `[]` — pas parce
     * que le Shell aurait recopie une garde.
     */
    public function test_a_non_member_receives_no_loop_action(): void
    {
        $this->assertSame([], app(AiFabContext::class)->loopActions($this->loop, $this->outsider));

        $this->assertSame([], $this->shellActions(AiShellPageContext::KIND_LOOP, (string) $this->loop->id, $this->outsider));
    }

    /** 6. Un Dossier que la personne ne peut pas voir n'offre rien. */
    public function test_a_refused_dossier_offers_nothing(): void
    {
        $otherOrganization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-c2a-etrangere']);
        $stranger = User::factory()->complete()->create(['organization_id' => $otherOrganization->id]);

        $foreign = Dossier::factory()->create([
            'organization_id' => $otherOrganization->id,
            'owner_id' => $stranger->id,
            'name' => 'Dossier Etranger',
        ]);

        $this->assertSame([], $this->shellActions(AiShellPageContext::KIND_DOSSIER, (string) $foreign->id));
    }

    /** 7. Hors Boucle et hors Dossier, aucune action n'apparait. */
    public function test_no_action_appears_outside_a_loop_or_a_dossier(): void
    {
        $this->assertSame([], $this->shellActions(AiShellPageContext::KIND_DASHBOARD, null));
        $this->assertSame([], $this->shellActions(AiShellPageContext::KIND_OTHER, null));
    }

    /**
     * 8. Un identifiant de Boucle FORGE ne rend rien.
     *
     * Le contexte de page est re-resolu a chaque rendu : un identifiant qui ne
     * passe pas sa garde donne un contexte `other`, donc aucune action. C'est
     * la reponse a « et si on trafique le contexte ? ».
     */
    public function test_a_forged_object_id_yields_no_action(): void
    {
        $otherOrganization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-c2a-forge']);
        $stranger = User::factory()->complete()->create(['organization_id' => $otherOrganization->id]);
        $foreignLoop = (new LoopService)->createLoop($stranger, 'Boucle Etrangere');

        $this->assertSame([], $this->shellActions(AiShellPageContext::KIND_LOOP, (string) $foreignLoop->id));
    }

    // =====================================================================
    // C. Le contrat de T1361 n'est pas touche
    // =====================================================================

    /**
     * 9. « Que puis-je faire ici ? » reste self-knowledge, prose seule, zero
     *    provider.
     *
     * Arbitrage MASTER : les actions vivent dans la RANGEE d'actions de la
     * page, pas dans le texte de la reponse. On ne relache pas le contrat
     * `PROSE_ONLY` une heure apres l'avoir fixe.
     */
    public function test_the_self_knowledge_contract_is_untouched(): void
    {
        $component = Livewire::actingAs($this->member)->test(AiShell::class);

        $component->set('draft', 'Que puis-je faire ici ?')->call('send');

        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());

        $answer = (string) AiShellMessage::query()
            ->where('role', 'assistant')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail()
            ->content;

        $this->assertStringNotContainsString('http', $answer);
        $this->assertStringNotContainsString('<button', $answer);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Les actions telles que le Shell les RENDRAIT, contexte re-resolu par le
     * vrai resolveur — jamais un tableau fabrique a la main.
     *
     * @return list<array<string, mixed>>
     */
    private function shellActions(string $kind, ?string $objectId, ?User $actor = null): array
    {
        $actor ??= $this->member;

        $component = Livewire::actingAs($actor)->test(AiShell::class);

        $context = app(AiShellPageContext::class)->resolve($actor, $this->organization, $kind, $objectId);

        $actions = (fn (): array => $this->actions($context))->call($component->instance());

        return array_values($actions);
    }
}
