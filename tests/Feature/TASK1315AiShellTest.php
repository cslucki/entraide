<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Livewire\AiShell;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\AiShellMessage;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\LoopService;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellThread;
use App\Support\Loops\HelpRequestHandoff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1315 — Shell « BouclePro IA » V1.
 *
 * Contrats prouves ici :
 *
 *  A. CONTEXTE DE PAGE — il NOMME ce que la page montre deja, et rien d'autre.
 *     Un identifiant forge, celui d'une autre Organization, ou celui d'un objet
 *     dont l'utilisateur n'a pas l'acces, ne rend AUCUN nom, AUCUNE URL,
 *     AUCUNE action. Etre sur une page n'accorde aucun droit.
 *  B. FIL CONSERVE — le fil vit en base, pas dans l'etat du composant : il est
 *     relu tel quel apres une navigation complete.
 *  C. TOUR — le Shell delegue a l'autorite existante (clarification
 *     d'Organization). Il ne publie rien, ni Boucle, ni Demande, ni Article.
 *  D. T1311 — le verrou traite la course ; l'idempotence traite le rejeu, et
 *     elle est garantie par la BASE (`reply_to_id` UNIQUE).
 *  E. TENANT — Organization = Tenant : un fil ne traverse jamais la frontiere.
 *  F. ECONOMIE — au plafond de credit, le Shell refuse sans aucun appel et
 *     sans aucune ligne de ledger.
 *  G. FAB — la porte du Shell s'ajoute sans rien retirer aux actions
 *     historiques (T1231/T1237).
 */
class TASK1315AiShellTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $memberA;

    private User $strangerA;

    private User $memberB;

    private User $superAdmin;

    private Loop $loopA;

    private Loop $loopB;

    private Dossier $dossierA;

    private BlogPost $articleA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create(['is_active' => true, 'slug' => 'org-shell-a', 'name' => 'Org Shell A']);
        $this->organizationB = Organization::factory()->create(['is_active' => true, 'slug' => 'org-shell-b', 'name' => 'Org Shell B']);

        foreach ([$this->organizationA, $this->organizationB] as $organization) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $organization->id,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => 'sk-task1315-'.$organization->id,
                'monthly_budget_usd' => 5.00,
            ]);
        }

        $this->memberA = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Ada', 'name' => 'Shell']);
        $this->strangerA = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Sam', 'name' => 'Shell']);
        $this->memberB = User::factory()->complete()->create(['organization_id' => $this->organizationB->id, 'first_name' => 'Bo', 'name' => 'Shell']);
        $this->superAdmin = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Root', 'name' => 'Shell', 'is_admin' => true]);

        app()->instance('current_organization', $this->organizationA);

        $loops = new LoopService;
        $this->loopA = $loops->createLoop($this->memberA, 'Boucle Shell A');

        app()->instance('current_organization', $this->organizationB);
        $this->loopB = $loops->createLoop($this->memberB, 'Boucle Shell B');
        app()->instance('current_organization', $this->organizationA);

        $this->dossierA = Dossier::factory()->create([
            'organization_id' => $this->organizationA->id,
            'owner_id' => $this->memberA->id,
            'name' => 'Dossier Shell A',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->articleA = BlogPost::create([
            'organization_id' => $this->organizationA->id,
            'user_id' => $this->memberA->id,
            'title' => 'Article Shell A',
            'slug' => 'article-shell-a',
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
    // A. Le contexte de page n'accorde aucun droit
    // =====================================================================

    public function test_the_page_context_names_only_what_the_page_already_shows(): void
    {
        $resolver = app(AiShellPageContext::class);

        $onLoop = $resolver->resolve($this->memberA, $this->organizationA, AiShellPageContext::KIND_LOOP, (string) $this->loopA->id);
        $this->assertSame(AiShellPageContext::KIND_LOOP, $onLoop['kind']);
        $this->assertSame('Boucle Shell A', $onLoop['object']['label']);
        $this->assertNotSame('', $onLoop['object']['url']);

        $onDossier = $resolver->resolve($this->memberA, $this->organizationA, AiShellPageContext::KIND_DOSSIER, (string) $this->dossierA->id);
        $this->assertSame(AiShellPageContext::KIND_DOSSIER, $onDossier['kind']);
        $this->assertSame('Dossier Shell A', $onDossier['object']['label']);

        $onArticle = $resolver->resolve($this->memberA, $this->organizationA, AiShellPageContext::KIND_ARTICLE, (string) $this->articleA->id);
        $this->assertSame(AiShellPageContext::KIND_ARTICLE, $onArticle['kind']);
        $this->assertSame('Article Shell A', $onArticle['object']['label']);

        $dashboard = $resolver->resolve($this->memberA, $this->organizationA, AiShellPageContext::KIND_DASHBOARD, null);
        $this->assertSame(AiShellPageContext::KIND_DASHBOARD, $dashboard['kind']);
        $this->assertNull($dashboard['object']);
    }

    /**
     * Le coeur de la garde : chaque identifiant est REJOUE contre la garde de
     * sa page. Ce qui ne passe pas ne rend ni nom, ni URL.
     */
    public function test_a_forged_or_foreign_object_id_reveals_nothing(): void
    {
        $resolver = app(AiShellPageContext::class);

        $forged = [
            [AiShellPageContext::KIND_LOOP, (string) Str::uuid(), 'un identifiant de Boucle invente'],
            [AiShellPageContext::KIND_LOOP, (string) $this->loopB->id, 'une Boucle d\'une autre Organization'],
            [AiShellPageContext::KIND_DOSSIER, (string) Str::uuid(), 'un identifiant de Dossier invente'],
            [AiShellPageContext::KIND_ARTICLE, (string) Str::uuid(), 'un identifiant d\'Article invente'],
            ['loop; drop table', (string) $this->loopA->id, 'un type de contexte inconnu'],
        ];

        foreach ($forged as [$kind, $id, $why]) {
            $context = $resolver->resolve($this->memberA, $this->organizationA, $kind, $id);

            $this->assertNull($context['object'], "Le Shell ne doit rien nommer pour {$why}.");
            $this->assertSame(AiShellPageContext::KIND_OTHER, $context['kind'], $why);
            $this->assertSame($this->organizationA->name, $context['label'], $why);
        }
    }

    public function test_a_non_member_gets_no_loop_context_even_though_the_presentation_page_renders(): void
    {
        $this->loopA->update(['is_public' => true]);

        $context = app(AiShellPageContext::class)
            ->resolve($this->strangerA, $this->organizationA, AiShellPageContext::KIND_LOOP, (string) $this->loopA->id);

        $this->assertNull($context['object']);
        $this->assertSame(AiShellPageContext::KIND_OTHER, $context['kind']);
    }

    public function test_a_dossier_the_user_cannot_view_is_never_named(): void
    {
        $context = app(AiShellPageContext::class)
            ->resolve($this->strangerA, $this->organizationA, AiShellPageContext::KIND_DOSSIER, (string) $this->dossierA->id);

        $this->assertNull($context['object']);
        $this->assertTrue($this->strangerA->cannot('view', $this->dossierA));
    }

    public function test_an_unpublished_article_is_named_to_its_author_and_to_nobody_else(): void
    {
        $draft = BlogPost::create([
            'organization_id' => $this->organizationA->id,
            'user_id' => $this->memberA->id,
            'title' => 'Brouillon Shell',
            'slug' => 'brouillon-shell',
            'content' => 'Contenu brouillon.',
            'status' => 'draft',
            'published_at' => null,
        ]);

        $resolver = app(AiShellPageContext::class);

        $this->assertSame('Brouillon Shell', $resolver->resolve($this->memberA, $this->organizationA, AiShellPageContext::KIND_ARTICLE, (string) $draft->id)['object']['label']);
        $this->assertNull($resolver->resolve($this->strangerA, $this->organizationA, AiShellPageContext::KIND_ARTICLE, (string) $draft->id)['object']);
    }

    public function test_a_private_loop_manifesto_is_never_named_to_a_non_member(): void
    {
        $this->loopA->update(['visibility' => 'private', 'manifesto_blog_post_id' => $this->articleA->id]);

        $resolver = app(AiShellPageContext::class);

        $this->assertNull(
            $resolver->resolve($this->strangerA, $this->organizationA, AiShellPageContext::KIND_ARTICLE, (string) $this->articleA->id)['object'],
            'Le garde de Manifeste prive (T1079) doit valoir aussi pour le Shell.'
        );
        $this->assertNotNull(
            $resolver->resolve($this->memberA, $this->organizationA, AiShellPageContext::KIND_ARTICLE, (string) $this->articleA->id)['object']
        );
    }

    public function test_the_context_properties_are_locked_against_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('contextObjectId', (string) $this->loopB->id);
    }

    // =====================================================================
    // B. Le fil est conserve pendant la navigation
    // =====================================================================

    public function test_the_thread_survives_a_full_navigation(): void
    {
        $this->fakeClarifier();

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour notre dossier europeen.')
            ->call('send');

        $this->assertSame(2, AiShellMessage::query()->count());

        // Navigation reelle : nouvelle requete HTTP, nouveau montage du
        // composant. Ce qui revient vient de la base, pas de l'etat client.
        $page = $this->actingAs($this->memberA)->get($this->loopUrl());

        $page->assertOk()
            ->assertSee('data-ai-shell-panel', false)
            ->assertSee('data-ai-shell-thread-count="2"', false)
            ->assertSee('Je cherche un relecteur pour notre dossier europeen.')
            ->assertSee('Cadrer notre relecture europeenne.');

        // …et encore sur une page d'une autre nature.
        $this->actingAs($this->memberA)->get(route('organization.dashboard', ['organization' => $this->organizationA->slug]))
            ->assertOk()
            ->assertSee('data-ai-shell-thread-count="2"', false)
            ->assertSee('Je cherche un relecteur pour notre dossier europeen.');
    }

    /**
     * DECISION MASTER 27/08 : pas de SPA. La continuite du Shell repose sur un
     * identifiant de conversation REUTILISE apres un rechargement complet, un
     * fil relu en base, et un PageContext RECALCULE sur la nouvelle requete.
     */
    public function test_the_conversation_id_is_reused_across_a_full_page_reload(): void
    {
        $this->fakeClarifier();

        $component = Livewire::actingAs($this->memberA)->test(AiShell::class);

        // L'identifiant affiche AVANT le premier tour est celui que ce tour
        // inscrit : la conversation ne change pas sous les yeux de
        // l'utilisateur au moment ou elle commence.
        $shown = (string) $component->get('conversationId');
        $this->assertNotSame('', $shown);

        $component->set('draft', 'Premiere question.')->call('send');

        $this->assertSame($shown, (string) AiShellMessage::query()->value('conversation_id'));

        $conversationId = (string) AiShellMessage::query()->value('conversation_id');
        $this->assertNotSame('', $conversationId);

        // Les deux messages du tour partagent la conversation.
        $this->assertSame([$conversationId], AiShellMessage::query()->distinct()->pluck('conversation_id')->all());

        // Rechargement complet sur une page d'une autre nature : MEME
        // identifiant, contexte RECALCULE.
        $this->actingAs($this->memberA)->get($this->loopUrl())
            ->assertOk()
            ->assertSee('data-ai-shell-conversation="'.$conversationId.'"', false)
            ->assertSee('data-ai-shell-context-kind="loop"', false);

        $this->actingAs($this->memberA)->get($this->dossierUrl())
            ->assertOk()
            ->assertSee('data-ai-shell-conversation="'.$conversationId.'"', false)
            ->assertSee('data-ai-shell-context-kind="dossier"', false);

        // Un second tour, apres navigation, reste dans la MEME conversation.
        $this->fakeClarifier();
        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->assertSet('conversationId', $conversationId)
            ->set('draft', 'Seconde question, autre page.')
            ->call('send');

        $this->assertSame(4, AiShellMessage::query()->count());
        $this->assertSame([$conversationId], AiShellMessage::query()->distinct()->pluck('conversation_id')->all());
    }

    public function test_clearing_the_thread_opens_a_new_conversation(): void
    {
        $this->fakeClarifier();

        $component = Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Une question.')
            ->call('send');

        $first = (string) AiShellMessage::query()->value('conversation_id');

        $component->call('askForClear')->call('clearThread');
        $second = $component->get('conversationId');

        $this->assertNotSame($first, $second);
        $this->assertSame(0, AiShellMessage::query()->count());
    }

    public function test_the_context_follows_the_page_while_the_thread_does_not_move(): void
    {
        $this->fakeClarifier();

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Une question generale.')
            ->call('send');

        $this->actingAs($this->memberA)->get($this->loopUrl())
            ->assertOk()
            ->assertSee('data-ai-shell-context-kind="loop"', false)
            ->assertSee('Boucle Shell A');

        $this->actingAs($this->memberA)->get($this->dossierUrl())
            ->assertOk()
            ->assertSee('data-ai-shell-context-kind="dossier"', false)
            ->assertSee('Dossier Shell A')
            ->assertSee('data-ai-shell-thread-count="2"', false);
    }

    // =====================================================================
    // C. Le tour : une autorite existante, aucune publication
    // =====================================================================

    public function test_a_turn_delegates_to_the_existing_clarification_authority_and_persists_both_sides(): void
    {
        $this->fakeClarifier();

        $interactions = AiInteraction::query()->count();
        $ledger = AiProviderInvocation::query()->count();

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur.')
            ->call('send')
            ->assertSet('draft', '')
            ->assertSet('notice', null);

        $messages = AiShellMessage::query()->orderBy('created_at')->get();
        $this->assertCount(2, $messages);

        [$trigger, $answer] = [$messages[0], $messages[1]];
        $this->assertSame(AiShellMessage::ROLE_USER, $trigger->role);
        $this->assertSame('Je cherche un relecteur.', $trigger->content);
        $this->assertSame(AiShellMessage::ROLE_ASSISTANT, $answer->role);
        $this->assertSame($trigger->id, $answer->reply_to_id);
        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $answer->metadata['status']);
        $this->assertSame('laravel_ai_sdk', $answer->metadata['producer']);
        $this->assertSame((string) $this->organizationA->id, $answer->organization_id);

        // L'autorite atteinte est bien la clarification : sa trace et sa ligne
        // de ledger existent, et le Shell n'en a ecrit aucune de son cru.
        $this->assertSame($interactions + 1, AiInteraction::query()->count());
        $this->assertSame($ledger + 1, AiProviderInvocation::query()->count());
        $this->assertSame('help_request.clarify', AiInteraction::query()->latest('created_at')->first()->process);
    }

    public function test_a_turn_publishes_nothing(): void
    {
        $this->fakeClarifier();

        $loopMessages = LoopMessage::query()->count();
        $requests = ServiceRequest::query()->count();

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Publie ceci dans la Boucle tout de suite.')
            ->call('send');

        $this->assertSame($loopMessages, LoopMessage::query()->count(), 'Le Shell ne poste jamais dans une Boucle.');
        $this->assertSame($requests, ServiceRequest::query()->count(), 'Le Shell ne cree jamais une Demande.');
        $this->assertSame(0, BlogPost::query()->where('title', 'like', '%Publie%')->count());
    }

    public function test_an_empty_prompt_never_reaches_the_authority(): void
    {
        $this->fakeClarifier();

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', '   ')
            ->call('send');

        $this->assertSame(0, AiShellMessage::query()->count());
        $this->assertSame(0, AiInteraction::query()->count());
    }

    // =====================================================================
    // D. T1311 : le verrou traite la course, l'idempotence traite le rejeu
    // =====================================================================

    public function test_a_concurrent_turn_is_refused_and_writes_nothing(): void
    {
        $this->fakeClarifier();

        Cache::add(AiShellResponder::lockKey($this->organizationA, $this->memberA), true, 90);

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Deuxieme onglet.')
            ->call('send')
            ->assertSet('notice', __('ai.shell_turn_in_progress'))
            // La question n'est pas perdue : elle revient dans le composeur.
            ->assertSet('draft', 'Deuxieme onglet.');

        $this->assertSame(0, AiShellMessage::query()->count());
        $this->assertSame(0, AiInteraction::query()->count());
    }

    public function test_a_trigger_can_never_carry_two_answers(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organizationA, $this->memberA, 'Question.');
        $thread->appendAssistant($this->organizationA, $this->memberA, 'Reponse.', $trigger);

        $this->assertNotNull($thread->answerFor($trigger));

        // La garantie n'est pas une lecture-puis-ecriture : c'est la BASE.
        $this->expectException(QueryException::class);
        $thread->appendAssistant($this->organizationA, $this->memberA, 'Deuxieme reponse.', $trigger);
    }

    /**
     * DECISION MASTER §7 : un double submit ne doit JAMAIS produire deux appels
     * provider, deux reponses assistant, ni deux ecritures economiques.
     */
    public function test_a_double_submit_produces_one_generation_one_answer_one_economic_write(): void
    {
        $this->fakeClarifier();

        $component = Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Je clique deux fois.');

        // Deux `send()` de suite, sans reposer le brouillon entre les deux :
        // c'est exactement ce que produit un double clic (Livewire serialise
        // les requetes d'un meme composant, le second part avec le brouillon
        // deja vide).
        $component->call('send')->call('send');

        $this->assertSame(1, AiShellMessage::query()->where('role', AiShellMessage::ROLE_USER)->count());
        $this->assertSame(1, AiShellMessage::query()->where('role', AiShellMessage::ROLE_ASSISTANT)->count());
        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame(1, AiProviderInvocation::query()->count());
    }

    /**
     * DECISION MASTER §3 : le verrou du Shell EST celui de T1311, sur une cle
     * fournie. Deux utilisateurs ne se bloquent pas ; deux Organizations ne
     * partagent jamais une cle ; une panne libere le verrou.
     */
    public function test_the_shell_lock_is_the_t1311_primitive_on_its_own_scoped_key(): void
    {
        $key = AiShellResponder::lockKey($this->organizationA, $this->memberA);

        $this->assertStringContainsString((string) $this->organizationA->id, $key);
        $this->assertStringContainsString((string) $this->memberA->id, $key);

        // Deux utilisateurs : cles distinctes, aucun blocage mutuel.
        $this->assertNotSame($key, AiShellResponder::lockKey($this->organizationA, $this->strangerA));
        // Deux Organizations : cles distinctes, et cela se LIT dans la cle.
        $this->assertNotSame($key, AiShellResponder::lockKey($this->organizationB, $this->memberA));

        // Une panne pendant la generation libere le verrou (le `finally` de
        // T1311) : le tour suivant passe.
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('panne provider');
        });

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->set('draft', 'Un tour qui echoue.')
            ->call('send');

        $this->assertFalse(Cache::has($key), 'Une panne ne doit jamais geler le tour jusqu\'au TTL.');

        $this->fakeClarifier();
        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->set('draft', 'Le tour suivant passe.')
            ->call('send');

        // Deux tours, deux reponses : le premier dit honnetement son
        // indisponibilite (la clarification retombe sur son repli deterministe
        // et le Shell ne la presente jamais comme une reponse de l'IA), le
        // second aboutit. Le verrou n'a bloque ni l'un ni l'autre.
        $reponses = AiShellMessage::query()
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $reponses);
        $this->assertSame(AiShellResponder::STATUS_UNAVAILABLE, $reponses[0]->metadata['status']);
        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $reponses[1]->metadata['status']);
    }

    // =====================================================================
    // E. Organization = Tenant
    // =====================================================================

    public function test_a_thread_never_crosses_the_organization_boundary(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organizationA, $this->memberA, 'Secret de A.');
        $thread->appendAssistant($this->organizationA, $this->memberA, 'Reponse de A.', $trigger);

        $this->assertCount(2, $thread->messages($this->organizationA, $this->memberA));
        $this->assertCount(0, $thread->messages($this->organizationB, $this->memberA));
        $this->assertCount(0, $thread->messages($this->organizationA, $this->strangerA));

        app()->instance('current_organization', $this->organizationB);
        Livewire::actingAs($this->memberB)
            ->test(AiShell::class)
            ->assertDontSee('Secret de A.');
    }

    /**
     * DECISION MASTER §1 : `conversation_id` est un identifiant OPAQUE de
     * regroupement, JAMAIS une autorite d'acces. Toute lecture est re-scopee
     * par Organization + utilisateur AVANT d'etre filtree par conversation.
     */
    public function test_a_forged_conversation_id_never_reads_across_users_or_organizations(): void
    {
        $thread = app(AiShellThread::class);

        $triggerA = $thread->appendUser($this->organizationA, $this->memberA, 'Secret de Ada.');
        $thread->appendAssistant($this->organizationA, $this->memberA, 'Reponse a Ada.', $triggerA);
        $conversationA = (string) $triggerA->conversation_id;

        $triggerB = $thread->appendUser($this->organizationB, $this->memberB, 'Secret de Bo.');
        $conversationB = (string) $triggerB->conversation_id;

        // La conversation d'Ada, demandee avec l'identite de Sam : rien.
        $this->assertCount(0, $thread->messages($this->organizationA, $this->strangerA, $conversationA));
        // La conversation d'Ada, demandee dans l'autre Organization : rien.
        $this->assertCount(0, $thread->messages($this->organizationB, $this->memberA, $conversationA));
        // La conversation de Bo, demandee par Ada : rien.
        $this->assertCount(0, $thread->messages($this->organizationA, $this->memberA, $conversationB));
        // Un identifiant purement invente : rien.
        $this->assertCount(0, $thread->messages($this->organizationA, $this->memberA, (string) Str::uuid()));
        // Et la sienne, elle, revient.
        $this->assertCount(2, $thread->messages($this->organizationA, $this->memberA, $conversationA));

        // Effacer avec l'identifiant d'autrui n'efface rien.
        $this->assertSame(0, $thread->clear($this->organizationA, $this->memberA, $conversationB));
        $this->assertSame(1, AiShellMessage::query()->where('user_id', $this->memberB->id)->count());
    }

    /**
     * DECISION MASTER — TASK-1145 : sur une page dont l'objet a ete REFUSE,
     * le Shell ne se monte pas du tout. Monter un composant Livewire y
     * inscrirait `memo.path`, donc l'URL, donc l'identifiant refuse.
     */
    public function test_the_shell_is_not_mounted_on_a_page_whose_subject_was_refused(): void
    {
        $url = $this->dossierUrl();

        // Le proprietaire du Dossier : le Shell est la.
        $this->actingAs($this->memberA)->get($url)
            ->assertOk()
            ->assertSee('data-ai-shell-panel', false);

        // Un tiers sans acces : page de refus, aucun Shell, et surtout aucun
        // instantane Livewire portant l'identifiant du Dossier.
        $refus = $this->actingAs($this->strangerA)->get($url);
        $refus->assertForbidden();
        $this->assertStringNotContainsString('data-ai-shell', $refus->getContent());
        $this->assertStringNotContainsString('wire:name="ai-shell"', $refus->getContent());
        $this->assertStringNotContainsString((string) $this->dossierA->getKey(), $refus->getContent());
    }

    public function test_clearing_deletes_only_my_thread_in_this_organization(): void
    {
        $thread = app(AiShellThread::class);
        $thread->appendUser($this->organizationA, $this->memberA, 'A moi.');
        $thread->appendUser($this->organizationA, $this->strangerA, 'A quelqu\'un d\'autre.');
        $thread->appendUser($this->organizationB, $this->memberB, 'A une autre Organization.');

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->call('askForClear')
            ->assertSet('confirmingClear', true)
            ->call('clearThread')
            ->assertSet('confirmingClear', false);

        $this->assertSame(0, AiShellMessage::query()->where('user_id', $this->memberA->id)->count());
        $this->assertSame(1, AiShellMessage::query()->where('user_id', $this->strangerA->id)->count());
        $this->assertSame(1, AiShellMessage::query()->where('user_id', $this->memberB->id)->count());
    }

    // =====================================================================
    // Actions natives : revalidees, jamais publiantes
    // =====================================================================

    public function test_the_suggested_loop_is_revalidated_at_display_time_not_trusted_from_the_thread(): void
    {
        // Un fil qui porte l'identifiant d'une Boucle d'une AUTRE Organization
        // — le cas exact d'un fil relu apres un changement de droits.
        // TASK-1325 : la suggestion s'affiche desormais comme LoopCard du
        // tour ; la regle de revalidation, elle, ne change pas.
        $this->answerWithSuggestedLoop((string) $this->loopB->id);

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->assertDontSee('data-ai-shell-card="loop"', false)
            ->assertDontSee('Boucle Shell B');

        AiShellMessage::query()->delete();
        $this->answerWithSuggestedLoop((string) $this->loopA->id);

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->assertSee('data-ai-shell-card="loop"', false)
            ->assertSee('data-ai-shell-card-action="open_loop"', false)
            ->assertSee('Boucle Shell A');
    }

    public function test_prepare_request_stores_a_draft_and_publishes_nothing(): void
    {
        $this->answerWithSuggestedLoop((string) $this->loopA->id);
        $requests = ServiceRequest::query()->count();

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->assertSee('data-ai-shell-card-action="prepare_request"', false)
            ->call('prepareRequest')
            ->assertRedirect(route('organization.requests.create', ['organization' => $this->organizationA->slug]));

        $this->assertTrue(app(HelpRequestHandoff::class)->hasDraft($this->memberA, $this->organizationA));
        $this->assertSame($requests, ServiceRequest::query()->count(), 'Preparer n\'est pas publier.');

        $draft = app(HelpRequestHandoff::class)->pullDraft($this->memberA, $this->organizationA);
        $this->assertSame('Cadrer notre relecture europeenne.', $draft['title']);
        $this->assertSame($this->loopA->id, $draft['relay_loop_id']);
    }

    public function test_the_loop_dossiers_action_uses_the_fab_guard_and_nothing_else(): void
    {
        Livewire::actingAs($this->memberA)
            ->test(AiShell::class, [])
            ->assertDontSee('data-ai-shell-action="shell_loop_knowledge"', false);

        // Sur la page Boucle, l'action apparait — via la MEME source que le FAB.
        $this->actingAs($this->memberA)->get($this->loopUrl())
            ->assertOk()
            ->assertSee('data-ai-shell-action="shell_loop_knowledge"', false);

        // Et elle disparait exactement quand le bouton de la page disparait.
        config(['ai.chatloop.enabled' => false]);
        $this->actingAs($this->memberA)->get($this->loopUrl())
            ->assertOk()
            ->assertDontSee('data-ai-shell-action="shell_loop_knowledge"', false);
    }

    // =====================================================================
    // F. Economie : au plafond, aucun appel
    // =====================================================================

    public function test_at_the_credit_cap_the_shell_refuses_without_any_call(): void
    {
        $this->fakeClarifier();
        $this->platformQuota(2);
        $this->uses($this->memberA, 2);

        $interactions = AiInteraction::query()->count();
        $ledger = AiProviderInvocation::query()->count();

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->assertSee('data-ai-shell-refusal', false)
            ->set('draft', 'Une question de plus.')
            ->call('send');

        $this->assertSame(0, AiShellMessage::query()->count());
        $this->assertSame($interactions, AiInteraction::query()->count());
        $this->assertSame($ledger, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // G. Le FAB : la porte du Shell s'ajoute, elle ne retire rien
    // =====================================================================

    public function test_the_fab_opens_the_shell_and_keeps_its_historical_actions(): void
    {
        $page = $this->actingAs($this->memberA)->get($this->loopUrl());

        $page->assertOk()
            ->assertSee('data-ai-fab-shell', false)
            ->assertSee('bp-open-ai-shell', false)
            ->assertSee('data-ai-fab-page-context="loop"', false)
            // Non-regression T1231/T1237 : les actions de la page sont intactes.
            ->assertSee('data-ai-fab-action="loop_ask"', false)
            ->assertSee('data-ai-fab-action="loop_knowledge"', false);
    }

    public function test_the_shell_kill_switch_removes_the_shell_and_keeps_the_fab(): void
    {
        config(['ai.shell.enabled' => false]);

        $this->actingAs($this->memberA)->get($this->loopUrl())
            ->assertOk()
            ->assertDontSee('data-ai-fab-shell', false)
            ->assertDontSee('data-ai-shell-panel', false)
            ->assertSee('data-ai-fab-action="loop_ask"', false);
    }

    public function test_the_shell_is_absent_from_guest_and_admin_layouts(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-ai-shell', false);

        $this->actingAs($this->superAdmin)->get(route('admin.ia-usage'))
            ->assertOk()
            ->assertDontSee('data-ai-shell', false);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function loopUrl(): string
    {
        return route('organization.loops.show', ['organization' => $this->organizationA->slug, 'loop' => $this->loopA->id]);
    }

    private function dossierUrl(): string
    {
        return route('organization.dossiers.show', ['organization' => $this->organizationA->slug, 'dossier' => $this->dossierA->id]);
    }

    /** Un tour deja repondu, portant l'identifiant de Boucle donne. */
    private function answerWithSuggestedLoop(string $loopId): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organizationA, $this->memberA, 'Ma question.');
        $thread->appendAssistant($this->organizationA, $this->memberA, 'Cadrer notre relecture europeenne.', $trigger, [
            'status' => AiShellResponder::STATUS_ANSWERED,
            'producer' => 'laravel_ai_sdk',
            'title' => 'Cadrer notre relecture europeenne.',
            'message_draft' => 'Cadrer notre relecture europeenne.',
            'suggested_loop_id' => $loopId,
            'suggested_category' => null,
        ]);
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Relecture europeenne',
            'clarified_request' => 'Cadrer notre relecture europeenne.',
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

    private function platformQuota(?int $monthlyUses, bool $offerSubscription = true): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true,
            'monthly_uses' => $monthlyUses,
            'alert_percent' => 80,
            'offer_subscription' => $offerSubscription,
        ], $this->superAdmin);
    }

    private function uses(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            AiInteraction::create([
                'user_id' => $user->id,
                'organization_id' => $this->organizationA->id,
                'correlation_id' => (string) Str::uuid(),
                'process' => 'loop_knowledge.answer',
                'feature' => 'loop_knowledge_answer',
                'model' => 'openai/gpt-4o-mini',
                'prompt' => 'p',
                'response' => 'r',
                'input_tokens' => 10,
                'output_tokens' => 5,
                'cost_usd' => 0.001,
                'cost_unknown' => false,
                'metadata' => ['provider' => 'openai', 'status' => 'success'],
            ]);
        }
    }
}
