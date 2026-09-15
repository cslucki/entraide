<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellThread;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1567 / CDC-01 V0-L — le tour dit ce qu'il a VU de la conversation.
 *
 * ## Le test qui porte toute la TASK
 *
 * `test_hard_les_ids_traces_sont_les_messages_injectes_pas_la_fenetre_lue`.
 *
 * Il existe une maniere facile et fausse de remplir `history.message_ids` :
 * recopier ce que `AiShellThread::messages()` rend. C'est la fenetre CANDIDATE.
 * `conversationMemory()` la filtre ensuite — `remembered()`, cle d'objet de
 * page, visibilite courante, budget — et seuls les survivants atteignent le
 * modele.
 *
 * Une trace qui recopierait la fenetre serait FAUSSE ET PLAUSIBLE : elle
 * decrirait un contexte que le modele n'a jamais recu, avec l'autorite d'une
 * mesure. C'est precisement ce que CDC-01 P0.12 interdit — « ce que le moteur
 * lui a REELLEMENT donne, jamais ce qu'il aurait du voir ».
 *
 * Ce test construit donc un fil ou un message EST dans la fenetre et N'EST PAS
 * injecte, puis exige les deux faits en meme temps.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1567TurnHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-v0l',
            'name' => 'Org V0-L',
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1567-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        app()->instance('current_organization', $this->organization);

        config([
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai_pricing.overrides' => [],
            'ai.chatloop.enabled' => true,
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── LE test

    public function test_hard_les_ids_traces_sont_les_messages_injectes_pas_la_fenetre_lue(): void
    {
        // Un echange deja tenu, dont la reponse assistant est EXPLOITABLE.
        $this->tourPrecedent('Qui organise la reunion ?', 'Marin organise la reunion.', AiShellResponder::STATUS_ANSWERED);

        // Un second echange dont la reponse assistant est INDISPONIBLE : elle
        // reste dans le fil — la surface l'affiche — mais `remembered()`
        // l'ecarte de la memoire, parce qu'une panne technique ne dit rien du
        // sujet.
        $ecarte = $this->tourPrecedent('Et le budget ?', 'Service indisponible.', AiShellResponder::STATUS_UNAVAILABLE);

        $this->fakeGeneral('Voici ce que je peux en dire.');
        $this->envoyer('Peux-tu resumer ?');

        $history = $this->historyDuDernierTour();

        // (1) LA FENETRE CANDIDATE CONTIENT BIEN LE MESSAGE ECARTE.
        //     Sans cette assertion, le test pourrait passer parce que le
        //     message n'existe pas — et ne prouverait rien du filtrage.
        $fenetre = app(AiShellThread::class)
            ->messages($this->organization, $this->membre)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $this->assertContains($ecarte, $fenetre, 'le message doit bien etre dans la fenetre candidate');

        // (2) ET POURTANT IL N'EST PAS DANS LA TRACE.
        $this->assertNotContains(
            $ecarte,
            $history['message_ids'],
            'un message ecarte par un filtre ne doit JAMAIS figurer dans history.message_ids',
        );

        // (3) LES SURVIVANTS Y SONT.
        $this->assertNotEmpty($history['message_ids']);
        $this->assertSame(count($history['message_ids']), $history['count']);
        $this->assertSame('shell_thread', $history['strategy']);
    }

    public function test_hard_un_tour_dont_le_dossier_n_est_plus_visible_sort_de_la_trace(): void
    {
        // Jumeau du test precedent, sur l'autre frontiere — celle qui touche
        // vraiment au tenant. T1530 : un tour documentaire dont l'objet n'est
        // PLUS visible ne repart vers aucun fournisseur. Il doit donc, a plus
        // forte raison, ne pas apparaitre dans ce que le tour declare avoir vu.
        $this->tourPrecedent('Qui organise ?', 'Marin organise.', AiShellResponder::STATUS_ANSWERED);

        $thread = app(AiShellThread::class);
        $declencheur = $thread->appendUser($this->organization, $this->membre, 'Que dit le Dossier ?');
        $surDossierDisparu = (string) $thread->appendAssistant(
            $this->organization,
            $this->membre,
            'Le Dossier indique X.',
            $declencheur,
            [
                'status' => AiShellResponder::STATUS_ANSWERED,
                'page_context' => [
                    'object_type' => AiShellPageContext::KIND_DOSSIER,
                    // Un Dossier qui n'existe pas / plus : la garde de
                    // visibilite le refuse a la relecture.
                    'object_id' => '99999999-9999-4999-8999-999999999999',
                ],
            ],
        )->id;

        $this->fakeGeneral('Reponse.');
        $this->envoyer('Resume ?');

        $history = $this->historyDuDernierTour();

        $fenetre = app(AiShellThread::class)
            ->messages($this->organization, $this->membre)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $this->assertContains($surDossierDisparu, $fenetre, 'le message est bien dans la fenetre candidate');
        $this->assertNotContains(
            $surDossierDisparu,
            $history['message_ids'],
            'un tour dont l objet n est plus visible ne doit JAMAIS figurer dans la trace',
        );
    }

    public function test_l_ordre_trace_est_l_ordre_d_injection(): void
    {
        $this->tourPrecedent('Premier sujet ?', 'Reponse au premier.', AiShellResponder::STATUS_ANSWERED);
        $this->tourPrecedent('Second sujet ?', 'Reponse au second.', AiShellResponder::STATUS_ANSWERED);

        $this->fakeGeneral('Synthese.');
        $this->envoyer('Resume ?');

        $history = $this->historyDuDernierTour();

        // Le transcript est construit du plus ancien au plus recent
        // (`array_reverse` sur une lecture descendante). Les ids doivent suivre
        // le MEME ordre : une trace dont l'ordre ne serait pas celui du prompt
        // decrirait une conversation qui n'a pas eu lieu.
        $chronologique = AiShellMessage::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->filter(fn (string $id): bool => in_array($id, $history['message_ids'], true))
            ->values()
            ->all();

        $this->assertSame($chronologique, $history['message_ids']);
    }

    // ────────────────────────────── garde de NON-DEPENDANCE

    public function test_nd_collecte_coupee_le_prompt_et_la_reponse_sont_identiques(): void
    {
        $this->tourPrecedent('Qui organise ?', 'Marin organise.', AiShellResponder::STATUS_ANSWERED);

        $avec = $this->promptCapture('Peux-tu resumer ?');

        // Le premier tour a lui-meme allonge le fil : le rejouer tel quel
        // comparerait deux ETATS differents, et non deux comportements. On
        // remet donc le fil dans son etat initial, a l'identique.
        app(AiShellThread::class)->clear($this->organization, $this->membre);
        $this->tourPrecedent('Qui organise ?', 'Marin organise.', AiShellResponder::STATUS_ANSWERED);

        AiTurnTrace::pauseCollectionForTesting();

        $sans = $this->promptCapture('Peux-tu resumer ?');

        // A l'octet pres. Si la collecte changeait ne serait-ce qu'un caractere
        // de ce qui part au modele, l'observabilite serait devenue une
        // dependance fonctionnelle — et la trace ne decrirait plus le produit,
        // elle le modifierait.
        $this->assertSame($avec, $sans);
    }

    public function test_collecte_coupee_le_tour_n_ecrit_aucun_historique(): void
    {
        $this->tourPrecedent('Qui organise ?', 'Marin organise.', AiShellResponder::STATUS_ANSWERED);

        AiTurnTrace::pauseCollectionForTesting();

        $this->fakeGeneral('Reponse.');
        $this->envoyer('Resume ?');

        // La collecte du COLLECTEUR est coupee, mais `history` ne passe pas par
        // lui : il est calcule par le moteur et porte par le writer. Il reste
        // donc ecrit — et c'est voulu : le seam coupe l'OBSERVATION des etapes,
        // pas la verite que le writer connait de son propre tour.
        $history = $this->historyDuDernierTour();

        $this->assertSame('shell_thread', $history['strategy']);
    }

    // ────────────────────────────── scenario 13 (a) et (b) — LoopChat

    public function test_scenario13a_un_tour_en_reply_voit_l_echange_precedent(): void
    {
        $loop = $this->boucle();

        $premier = LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $this->membre->id,
            'body' => 'Quelle est la date de la reunion ?',
            // `user` et non `text` : `AiConversationContextBuilder` ne remonte
            // QUE les types `user` et `ai`. Un `text` romprait la chaine — et
            // le test mesurerait alors mon fixture, pas le produit.
            'type' => 'user',
        ]);

        $reponseIa = LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => null,
            'reply_to_id' => $premier->id,
            'body' => 'La reunion est le 12.',
            'type' => 'ai',
        ]);

        $suivant = LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $this->membre->id,
            'reply_to_id' => $reponseIa->id,
            'body' => 'Et le lieu ?',
            'type' => 'user',
        ]);

        $history = $this->tourChatLoop($loop, 'Et le lieu ?', $suivant);

        $this->assertSame('reply_chain', $history['strategy']);
        $this->assertGreaterThanOrEqual(2, $history['count']);
        $this->assertContains(
            (string) $reponseIa->id,
            $history['message_ids'],
            'la bulle IA du tour precedent doit figurer dans ce que le tour a vu',
        );
        // `trigger_id` est le message AUQUEL l'utilisateur repondait — donc la
        // bulle IA —, jamais le message courant. La distinction n'est pas
        // cosmetique : CDC-02 relie les tours en remontant `reply_to_id`, et un
        // `trigger_id` pointant le message courant ferait boucler cette remontee
        // sur elle-meme.
        $this->assertSame((string) $reponseIa->id, $history['trigger_id']);
    }

    public function test_scenario13b_un_tour_sans_reply_voit_zero_et_le_dit_sans_erreur(): void
    {
        $loop = $this->boucle();

        $declencheur = LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $this->membre->id,
            'body' => 'Une question posee sans repondre a personne.',
            'type' => 'text',
        ]);

        $history = $this->tourChatLoop($loop, 'Une question posee sans repondre a personne.', $declencheur);

        // Le comportement produit ACTUEL : un follow-up sans reply explicite ne
        // recoit aucun historique. La trace l'ecrit TEL QUEL.
        $this->assertSame(0, $history['count']);
        $this->assertSame([], $history['message_ids']);

        // L'assertion que le scenario 13(b) du CDC exige, et que ce test avait
        // d'abord OMISE — c'est cette omission qui a laisse passer une
        // semantique de `trigger_id` divergente de sa spec.
        $this->assertNull($history['trigger_id']);

        // Et surtout : la strategie reste `reply_chain`. C'est bien elle qui a
        // ete TENTEE. Ecrire `none` laisserait croire qu'aucune strategie
        // n'existait, et transformerait un fait architectural en panne.
        $this->assertSame('reply_chain', $history['strategy']);

        // Aucun statut d'erreur : CDC-01 P0.12 interdit de qualifier ce cas.
        // Sa qualification appartient a CDC-02 puis a la Fix Campaign.
        $turn = AiInteraction::query()->latest('created_at')->firstOrFail()
            ->metadata[AiTurnTrace::TURN_METADATA_KEY];

        $this->assertArrayNotHasKey('reason_code', $turn);
        $this->assertArrayNotHasKey('stage', $turn);
    }

    // ────────────────────────────── harnais

    private function boucle(): Loop
    {
        return (new LoopService)->createLoop($this->membre, 'Boucle V0-L');
    }

    /** @return array<string, mixed> */
    private function tourChatLoop(Loop $loop, string $question, LoopMessage $declencheur): array
    {
        AiTurnLock::forgetRequestState();

        LoopDirectAnswerAgent::fake([
            new TextResponse('Reponse.', new Usage(20, 10), new Meta('openai', 'gpt-4o-mini')),
        ]);

        app(ChatLoopAiService::class)->respondInThread($loop, $this->membre, $question, $declencheur);

        $turn = AiInteraction::query()->latest('created_at')->firstOrFail()
            ->metadata[AiTurnTrace::TURN_METADATA_KEY];

        $this->assertArrayHasKey('history', $turn, 'le mode `ia` doit ecrire son historique');

        return $turn['history'];
    }

    /** @return array<string, mixed> */
    private function historyDuDernierTour(): array
    {
        $interaction = AiInteraction::query()->latest('created_at')->firstOrFail();

        $turn = $interaction->metadata[AiTurnTrace::TURN_METADATA_KEY] ?? null;

        $this->assertIsArray($turn, 'le tour doit porter son bloc `turn`');
        $this->assertArrayHasKey('history', $turn, 'ce chemin Shell doit ecrire son historique');

        return $turn['history'];
    }

    private function promptCapture(string $question): string
    {
        $vu = new class
        {
            public string $prompt = '';
        };

        ShellGeneralAnswerAgent::fake(function (string $prompt) use ($vu): TextResponse {
            $vu->prompt = $prompt;

            return new TextResponse('Reponse.', new Usage(80, 30), new Meta('openai', 'gpt-4o-mini'));
        });

        $this->envoyer($question);

        return $vu->prompt;
    }

    private function envoyer(string $question): void
    {
        $context = app(AiShellPageContext::class)->resolve(
            $this->membre,
            $this->organization,
            AiShellPageContext::KIND_DASHBOARD,
            null,
            'organization.dashboard',
        );

        app(AiShellResponder::class)->respond($this->organization, $this->membre, $question, $context);
    }

    /** Ecrit un echange deja tenu dans le fil, et rend l'id de la REPONSE. */
    private function tourPrecedent(string $question, string $reponse, string $statut): string
    {
        $thread = app(AiShellThread::class);

        $declencheur = $thread->appendUser($this->organization, $this->membre, $question);

        $message = $thread->appendAssistant($this->organization, $this->membre, $reponse, $declencheur, [
            'status' => $statut,
        ]);

        return (string) $message->id;
    }

    private function fakeGeneral(string $reponse): void
    {
        ShellGeneralAnswerAgent::fake([
            new TextResponse($reponse, new Usage(80, 30), new Meta('openai', 'gpt-4o-mini')),
        ]);
    }
}
