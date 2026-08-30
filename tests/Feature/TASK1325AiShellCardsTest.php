<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Livewire\AiShell;
use App\Models\AiShellMessage;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\LoopService;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellThread;
use App\Support\Loops\HelpRequestHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1325 (Shell-1) — cartes structurees d'entites et d'actions par tour IA.
 *
 * Contrats prouves ici :
 *
 *  A. SERIALISATION — un tour ANSWERED stocke des REFERENCES (identifiants +
 *     faits verifies au tour), jamais un libelle de confiance, jamais une URL,
 *     jamais un droit. Les PersonCards viennent EXCLUSIVEMENT de l'ensemble
 *     eligible People-1, apparie par People-2 — le modele ne cree jamais un
 *     candidat.
 *  B. RENDU — chaque carte est re-resolue par l'autorite qui gouverne deja son
 *     objet, a l'instant du rendu. Ce qui ne passe plus n'existe plus : Boucle
 *     d'une autre Organization, personne sortie de la Boucle, profil depublie,
 *     gate ai_profiles_enabled, Document devenu inaccessible.
 *  C. WHITELIST — un type de carte inconnu n'est jamais rendu ; aucune route
 *     fournie par la metadata n'atteint l'ecran.
 *  D. ACTIONS — « Preparer la demande ici » agit sur le tour DESIGNE, apres
 *     revalidation du message par le scope forThread ; un identifiant forge ou
 *     etranger ne fait rien. Aucune action ne publie.
 *  E. TOUR — les cartes sont rattachees a LEUR tour, pas au fil.
 */
class TASK1325AiShellCardsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $memberA;

    private User $strangerA;

    private User $memberB;

    private User $candidate;

    private Loop $loopA;

    private Loop $loopB;

    private Dossier $dossierA;

    private BlogPost $articleA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create(['is_active' => true, 'slug' => 'org-cards-a', 'name' => 'Org Cards A', 'ai_profiles_enabled' => true]);
        $this->organizationB = Organization::factory()->create(['is_active' => true, 'slug' => 'org-cards-b', 'name' => 'Org Cards B', 'ai_profiles_enabled' => true]);

        foreach ([$this->organizationA, $this->organizationB] as $organization) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $organization->id,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => 'sk-task1325-'.$organization->id,
                'monthly_budget_usd' => 5.00,
            ]);
        }

        $this->memberA = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Ada', 'name' => 'Cards']);
        $this->strangerA = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Sam', 'name' => 'Cards']);
        $this->memberB = User::factory()->complete()->create(['organization_id' => $this->organizationB->id, 'first_name' => 'Bo', 'name' => 'Cards']);

        app()->instance('current_organization', $this->organizationA);

        $loops = new LoopService;
        $this->loopA = $loops->createLoop($this->memberA, 'Boucle Cards A');

        app()->instance('current_organization', $this->organizationB);
        $this->loopB = $loops->createLoop($this->memberB, 'Boucle Cards B');
        app()->instance('current_organization', $this->organizationA);

        // La candidate : membre ACTIF de la Boucle A, profil PUBLIE portant la
        // competence qui appariera la demande — l'exact perimetre People-1/2.
        $this->candidate = $this->eligibleCandidate('Carla', ['Erasmus+']);

        $this->dossierA = Dossier::factory()->create([
            'organization_id' => $this->organizationA->id,
            'owner_id' => $this->memberA->id,
            'name' => 'Dossier Cards A',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->articleA = BlogPost::create([
            'organization_id' => $this->organizationA->id,
            'user_id' => $this->memberA->id,
            'title' => 'Article Cards A',
            'slug' => 'article-cards-a',
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
    // A. Serialisation : des references, jamais des droits
    // =====================================================================

    public function test_an_answered_turn_stores_card_references_with_ids_and_verified_facts_only(): void
    {
        $this->fakeClarifier(suggestedLoopId: (string) $this->loopA->id, reason: 'La Boucle du programme europeen.');

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Qui peut relire mon dossier Erasmus ?')
            ->call('send');

        $answer = AiShellMessage::query()->where('role', AiShellMessage::ROLE_ASSISTANT)->firstOrFail();
        $cards = $answer->metadata['cards'];

        $this->assertIsArray($cards);

        $loopCard = collect($cards)->firstWhere('type', 'loop');
        $this->assertSame((string) $this->loopA->id, $loopCard['id']);
        $this->assertSame('La Boucle du programme europeen.', $loopCard['ai_wording']);
        // Des IDENTIFIANTS, jamais un libelle ni une URL de confiance : tout
        // se re-resout a l'affichage.
        $this->assertArrayNotHasKey('label', $loopCard);
        $this->assertArrayNotHasKey('url', $loopCard);
        $this->assertArrayNotHasKey('title', $loopCard);

        $personCard = collect($cards)->firstWhere('type', 'person');
        $this->assertSame((string) $this->candidate->id, $personCard['user_id']);
        $this->assertSame((string) $this->loopA->id, $personCard['loop_id']);
        $this->assertArrayNotHasKey('display_name', $personCard);

        // La raison est un FAIT serveur People-2 : source + termes apparies +
        // verified, jamais un texte libre de modele.
        $reason = $personCard['reasons'][0];
        $this->assertTrue($reason['verified']);
        $this->assertSame('profile_skill', $reason['type']);
        $this->assertSame('Erasmus+', $reason['label']);
        $this->assertContains('erasmu', $reason['matched_terms']);
        $this->assertArrayHasKey('member_ai_profile_id', $reason['source']);
    }

    public function test_person_cards_never_come_from_outside_the_eligible_set(): void
    {
        // Deux personnes qui APPARIERAIENT la demande mais ne sont pas
        // eligibles : profil reste en brouillon, et non-membre de la Boucle.
        $draftProfile = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Dara', 'name' => 'Cards']);
        LoopMember::factory()->create(['loop_id' => $this->loopA->id, 'user_id' => $draftProfile->id, 'organization_id' => $this->organizationA->id]);
        MemberAiProfile::factory()->draft()->create(['organization_id' => $this->organizationA->id, 'user_id' => $draftProfile->id, 'skills' => ['Erasmus+']]);

        $nonMember = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Nora', 'name' => 'Cards']);
        MemberAiProfile::factory()->published()->create(['organization_id' => $this->organizationA->id, 'user_id' => $nonMember->id, 'skills' => ['Erasmus+']]);

        $this->fakeClarifier(suggestedLoopId: (string) $this->loopA->id);

        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->set('draft', 'Qui peut relire mon dossier Erasmus ?')
            ->call('send');

        $answer = AiShellMessage::query()->where('role', AiShellMessage::ROLE_ASSISTANT)->firstOrFail();
        $personIds = collect($answer->metadata['cards'])->where('type', 'person')->pluck('user_id');

        // La candidate eligible est la — la restriction n'est pas un vide.
        $this->assertContains((string) $this->candidate->id, $personIds);
        $this->assertNotContains((string) $draftProfile->id, $personIds);
        $this->assertNotContains((string) $nonMember->id, $personIds);
    }

    public function test_without_a_suggested_loop_people_come_from_the_page_loop(): void
    {
        $this->fakeClarifier();

        $pageContext = app(AiShellPageContext::class)
            ->resolve($this->memberA, $this->organizationA, AiShellPageContext::KIND_LOOP, (string) $this->loopA->id);

        $turn = app(AiShellResponder::class)
            ->respond($this->organizationA, $this->memberA, 'Qui peut relire mon dossier Erasmus ?', $pageContext);

        $personCard = collect($turn['answer']->metadata['cards'])->firstWhere('type', 'person');

        $this->assertSame((string) $this->candidate->id, $personCard['user_id']);
        $this->assertSame((string) $this->loopA->id, $personCard['loop_id']);
    }

    public function test_a_turn_asked_on_a_document_page_carries_a_document_card_reference(): void
    {
        foreach ([
            [AiShellPageContext::KIND_DOSSIER, (string) $this->dossierA->id],
            [AiShellPageContext::KIND_ARTICLE, (string) $this->articleA->id],
        ] as [$kind, $id]) {
            AiShellMessage::query()->delete();
            $this->fakeClarifier();

            $pageContext = app(AiShellPageContext::class)
                ->resolve($this->memberA, $this->organizationA, $kind, $id);

            $turn = app(AiShellResponder::class)
                ->respond($this->organizationA, $this->memberA, 'Une question sur ce document.', $pageContext);

            $documentCard = collect($turn['answer']->metadata['cards'])->firstWhere('type', 'document');

            $this->assertSame($kind, $documentCard['kind']);
            $this->assertSame($id, $documentCard['id']);
            $this->assertArrayNotHasKey('label', $documentCard);
        }
    }

    // =====================================================================
    // B. Rendu : re-resolution par l'autorite, a l'instant
    // =====================================================================

    public function test_cards_are_rendered_under_their_own_turn(): void
    {
        $first = $this->seedAnsweredTurn('Premier tour.', [
            ['type' => 'loop', 'id' => (string) $this->loopA->id, 'ai_wording' => 'Le bon cercle pour cette demande.'],
        ]);

        $second = $this->seedAnsweredTurn('Second tour.', [
            ['type' => 'person', 'user_id' => (string) $this->candidate->id, 'loop_id' => (string) $this->loopA->id, 'reasons' => [
                ['type' => 'profile_skill', 'label' => 'Erasmus+', 'source' => ['member_ai_profile_id' => 'x'], 'matched_terms' => ['erasmu'], 'verified' => true],
            ]],
            ['type' => 'document', 'kind' => AiShellPageContext::KIND_DOSSIER, 'id' => (string) $this->dossierA->id],
        ]);

        $component = Livewire::actingAs($this->memberA)->test(AiShell::class);

        // Chaque carte porte l'identifiant de SON tour — pas du dernier.
        $component
            ->assertSee('data-ai-shell-cards-turn="'.$first->id.'"', false)
            ->assertSee('data-ai-shell-cards-turn="'.$second->id.'"', false)
            // LoopCard : nom RELU a l'instant, raison IA marquee comme telle,
            // les deux actions canoniques.
            ->assertSee('data-ai-shell-card="loop"', false)
            ->assertSee('Boucle Cards A')
            ->assertSee('Le bon cercle pour cette demande.')
            ->assertSee('data-ai-shell-card-ai-wording', false)
            ->assertSee('data-ai-shell-card-action="open_loop"', false)
            ->assertSee("prepareRequest('".$first->id."')", false)
            // PersonCard : nom People-1 relu, raison verifiee lisible, lien
            // profil canonique.
            ->assertSee('data-ai-shell-card="person"', false)
            ->assertSee('Carla Cards')
            ->assertSee(__('ai.shell_card_reason_profile_skill', ['label' => 'Erasmus+']))
            ->assertSee('data-ai-shell-card-action="view_profile"', false)
            ->assertSee(route('organization.profile.show', ['organization' => $this->organizationA->slug, 'user' => $this->candidate->id]), false)
            // DocumentCard : titre relu, action ouvrir.
            ->assertSee('data-ai-shell-card="document"', false)
            ->assertSee('Dossier Cards A')
            ->assertSee('data-ai-shell-card-action="open_document"', false);
    }

    public function test_a_person_who_left_the_loop_or_unpublished_their_profile_disappears(): void
    {
        $this->seedAnsweredTurn('Un tour.', [
            ['type' => 'person', 'user_id' => (string) $this->candidate->id, 'loop_id' => (string) $this->loopA->id, 'reasons' => []],
        ]);

        // Encore eligible : la carte est la.
        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertSee('data-ai-shell-card="person"', false)
            ->assertSee('Carla Cards');

        // Sortie de la Boucle : la carte n'existe plus.
        LoopMember::query()->where('loop_id', $this->loopA->id)->where('user_id', $this->candidate->id)->delete();

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertDontSee('data-ai-shell-card="person"', false)
            ->assertDontSee('Carla Cards');

        // De retour mais profil DEPUBLIE : toujours rien — la publication est
        // le consentement de visibilite (doctrine People-1).
        LoopMember::factory()->create(['loop_id' => $this->loopA->id, 'user_id' => $this->candidate->id, 'organization_id' => $this->organizationA->id]);
        MemberAiProfile::query()->where('user_id', $this->candidate->id)->update(['status' => MemberAiProfile::STATUS_DRAFT]);

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertDontSee('Carla Cards');
    }

    public function test_the_ai_profiles_gate_removes_person_cards_but_keeps_the_rest(): void
    {
        $this->seedAnsweredTurn('Un tour.', [
            ['type' => 'loop', 'id' => (string) $this->loopA->id, 'ai_wording' => null],
            ['type' => 'person', 'user_id' => (string) $this->candidate->id, 'loop_id' => (string) $this->loopA->id, 'reasons' => []],
        ]);

        $this->organizationA->forceFill(['ai_profiles_enabled' => false])->saveQuietly();

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertSee('data-ai-shell-card="loop"', false)
            ->assertDontSee('data-ai-shell-card="person"', false)
            ->assertDontSee('Carla Cards');
    }

    public function test_a_stored_card_never_crosses_the_organization_boundary(): void
    {
        // Un fil qui pretend a des objets de l'Organization B — le cas d'une
        // metadata forgee ou d'un droit disparu. Rien ne se nomme.
        $this->seedAnsweredTurn('Un tour.', [
            ['type' => 'loop', 'id' => (string) $this->loopB->id, 'ai_wording' => null],
            ['type' => 'person', 'user_id' => (string) $this->memberB->id, 'loop_id' => (string) $this->loopB->id, 'reasons' => []],
        ]);

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertDontSee('data-ai-shell-card="loop"', false)
            ->assertDontSee('data-ai-shell-card="person"', false)
            ->assertDontSee('Boucle Cards B')
            ->assertDontSee('Bo Cards');
    }

    public function test_a_document_the_user_can_no_longer_view_disappears(): void
    {
        $foreignDossier = Dossier::factory()->create([
            'organization_id' => $this->organizationA->id,
            'owner_id' => $this->strangerA->id,
            'name' => 'Dossier Prive de Sam',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->seedAnsweredTurn('Un tour.', [
            ['type' => 'document', 'kind' => AiShellPageContext::KIND_DOSSIER, 'id' => (string) $foreignDossier->id],
        ]);

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertDontSee('data-ai-shell-card="document"', false)
            ->assertDontSee('Dossier Prive de Sam');
    }

    // =====================================================================
    // C. Whitelist structurelle
    // =====================================================================

    public function test_an_unknown_card_type_or_injected_route_is_never_rendered(): void
    {
        $this->seedAnsweredTurn('Un tour.', [
            ['type' => 'external_tool', 'url' => 'https://evil.example/exfiltrate'],
            ['type' => 'person', 'user_id' => (string) $this->candidate->id, 'loop_id' => (string) $this->loopA->id,
                'url' => 'https://evil.example/phishing', 'reasons' => []],
            ['type' => 'loop', 'id' => (string) $this->loopA->id, 'ai_wording' => null],
            'pas-un-tableau',
        ]);

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            // Les types connus se rendent…
            ->assertSee('data-ai-shell-card="loop"', false)
            ->assertSee('data-ai-shell-card="person"', false)
            // …le reste n'existe pas, et aucune URL stockee n'atteint l'ecran :
            // les liens sont TOUJOURS reconstruits par le serveur.
            ->assertDontSee('external_tool')
            ->assertDontSee('evil.example');
    }

    // =====================================================================
    // D. Actions : le tour designe, revalide — et rien ne publie
    // =====================================================================

    public function test_prepare_request_acts_on_the_designated_turn_after_forthread_revalidation(): void
    {
        $first = $this->seedAnsweredTurn('Premier brouillon.', [
            ['type' => 'loop', 'id' => (string) $this->loopA->id, 'ai_wording' => null],
        ], title: 'Premier brouillon.');

        $this->seedAnsweredTurn('Second brouillon.', [], title: 'Second brouillon.');

        $requests = ServiceRequest::query()->count();

        // La carte du PREMIER tour prepare le brouillon du PREMIER tour, meme
        // si un tour plus recent existe.
        Livewire::actingAs($this->memberA)
            ->test(AiShell::class)
            ->call('prepareRequest', $first->id)
            ->assertRedirect(route('organization.requests.create', ['organization' => $this->organizationA->slug]));

        $draft = app(HelpRequestHandoff::class)->pullDraft($this->memberA, $this->organizationA);
        $this->assertSame('Premier brouillon.', $draft['title']);
        $this->assertSame($this->loopA->id, $draft['relay_loop_id']);

        $this->assertSame($requests, ServiceRequest::query()->count(), 'Preparer n\'est jamais publier.');
    }

    public function test_prepare_request_with_a_forged_or_foreign_message_id_does_nothing(): void
    {
        // Le message d'un AUTRE utilisateur — l'identifiant existe, mais pas
        // dans le fil du demandeur.
        $thread = app(AiShellThread::class);
        $foreignTrigger = $thread->appendUser($this->organizationA, $this->strangerA, 'Question de Sam.');
        $foreignAnswer = $thread->appendAssistant($this->organizationA, $this->strangerA, 'Reponse a Sam.', $foreignTrigger, [
            'status' => AiShellResponder::STATUS_ANSWERED,
            'title' => 'Brouillon de Sam.',
            'message_draft' => 'Brouillon de Sam.',
        ]);

        foreach ([(string) $foreignAnswer->id, (string) Str::uuid()] as $forgedId) {
            Livewire::actingAs($this->memberA)
                ->test(AiShell::class)
                ->call('prepareRequest', $forgedId)
                ->assertNoRedirect();

            $this->assertFalse(app(HelpRequestHandoff::class)->hasDraft($this->memberA, $this->organizationA));
        }
    }

    // =====================================================================
    // E. Absence de carte = reponse texte normale
    // =====================================================================

    public function test_a_turn_without_cards_renders_as_plain_text(): void
    {
        $this->seedAnsweredTurn('Une reponse sans objet structure.', []);

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->assertSee('Une reponse sans objet structure.')
            ->assertDontSee('data-ai-shell-cards', false);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Membre actif de la Boucle A, profil PUBLIE avec ces competences. */
    private function eligibleCandidate(string $firstName, array $skills): User
    {
        $user = User::factory()->complete()->create([
            'organization_id' => $this->organizationA->id,
            'first_name' => $firstName,
            'name' => 'Cards',
        ]);

        LoopMember::factory()->create([
            'loop_id' => $this->loopA->id,
            'user_id' => $user->id,
            'organization_id' => $this->organizationA->id,
        ]);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organizationA->id,
            'user_id' => $user->id,
            'skills' => $skills,
        ]);

        return $user;
    }

    /** Un tour deja repondu du fil de memberA, portant ces references de cartes. */
    private function seedAnsweredTurn(string $content, array $cards, ?string $title = null): AiShellMessage
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organizationA, $this->memberA, 'Ma question.');

        return $thread->appendAssistant($this->organizationA, $this->memberA, $content, $trigger, array_filter([
            'status' => AiShellResponder::STATUS_ANSWERED,
            'producer' => 'laravel_ai_sdk',
            'title' => $title,
            'message_draft' => $title,
            'suggested_loop_id' => collect($cards)->filter(fn ($card) => is_array($card))->firstWhere('type', 'loop')['id'] ?? null,
            'cards' => $cards,
        ], static fn ($value): bool => $value !== null));
    }

    private function fakeClarifier(string $suggestedLoopId = '', string $reason = ''): void
    {
        $structured = [
            'title' => 'Relecture Erasmus',
            'clarified_request' => 'Trouver un relecteur pour le dossier Erasmus.',
            'help_type' => 'information',
            'suggested_loop_id' => $suggestedLoopId,
            'suggested_category_id' => '',
            'suggestion_reason' => $reason,
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
