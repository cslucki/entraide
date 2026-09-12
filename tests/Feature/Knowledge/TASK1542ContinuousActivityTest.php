<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use App\Services\Knowledge\LoopConversationKnowledgeDispatcher;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1542 — une conversation qui ne se tait jamais finit par etre apprise.
 *
 * ## La limite que T1539 laissait ouverte
 *
 * La fenetre d'inactivite suppose que les conversations finissent par se
 * taire. Toutes ne se taisent pas. Une Boucle ou quelqu'un parle toutes les
 * cinq minutes ne franchit jamais `dernier <= calme` : elle n'etait pas « en
 * retard », elle n'etait JAMAIS compilee. Sans limite de temps, sans alerte,
 * et precisement sur les Boucles les plus actives — celles qui ont le plus a
 * apprendre.
 *
 * ## Ce que ces tests mesurent, et dans quel ordre
 *
 * D'abord la PREMISSE : que la regle de calme, seule, ne compile effectivement
 * jamais cette Boucle. Sans elle, « le plafond la rend due » ne prouverait
 * rien — elle aurait pu etre due de toute facon.
 *
 * Ensuite les trois proprietes que le plafond doit tenir SIMULTANEMENT :
 *
 *  - une conversation continue finit par etre apprise ;
 *  - le debounce n'est pas casse : en deca du plafond, N messages rapproches
 *    donnent toujours UNE compilation ;
 *  - le plafond n'est pas une horloge : une Boucle sans matiere non apprise ne
 *    devient jamais due, quelle que soit l'anciennete de sa memoire.
 *
 * La troisieme est la plus facile a perdre, et la plus couteuse : un plafond
 * qui redispatche toutes les Boucles compilees toutes les deux heures ne
 * depenserait rien — le court-circuit d'empreinte tient — mais ferait tourner
 * la file a vide et rendrait la metrique de retard illisible.
 */
class TASK1542ContinuousActivityTest extends TestCase
{
    use RefreshDatabase;

    private const CALME = 10;

    private const PLAFOND = 120;

    private Organization $organization;

    private User $alice;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Belleville',
            'visibility' => 'private',
        ]);

        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->alice->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1542',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai.knowledge.conversation.quiet_minutes' => self::CALME,
            'ai.knowledge.conversation.max_learning_delay_minutes' => self::PLAFOND,
            'ai_pricing.overrides' => [],
        ]);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (): array => array_fill(0, 1536, 0.01),
            $prompt->inputs,
        ))->preventStrayEmbeddings();
    }

    // ─────────────────────────────── la limite, et sa fermeture

    public function test_une_conversation_qui_ne_se_tait_jamais_finit_par_etre_apprise(): void
    {
        // Quatre heures de conversation continue : un message toutes les cinq
        // minutes, moitie moins que la fenetre de calme. Personne ne se tait.
        $this->conversationContinue(heures: 4, toutesLesMinutes: 5);

        // PREMISSE 1 — la regle de calme ne peut PAS s'appliquer : le dernier
        // message est trop recent. Sans cette mesure, le test ne saurait pas
        // si le plafond a servi a quelque chose.
        $dernier = LoopMessage::where('loop_id', $this->loop->id)->max('created_at');
        $this->assertTrue(now()->parse($dernier)->greaterThan(now()->subMinutes(self::CALME)),
            'PREMISSE : la conversation est TOUJOURS en cours');

        // PREMISSE 2 — et rien n'a jamais ete appris.
        $this->assertSame(0, DerivedKnowledgeNote::query()->count());

        $appels = 0;
        $this->fakeAgent('Le budget travaux de Belleville est de 486 000 euros.',
            function () use (&$appels): void {
                $appels++;
            });

        $this->assertSame(1, app(LoopConversationKnowledgeDispatcher::class)->dispatchDue(),
            'au-dela du plafond, on compile pendant que les gens parlent encore');
        $this->assertSame(1, $appels);
        $this->assertSame(1, DerivedKnowledgeNote::query()->claims()->active()->count());
    }

    public function test_une_boucle_deja_compilee_qui_continue_de_parler_rattrape_son_retard(): void
    {
        // LE cas reel, et celui qu'un sabotage a montre non mesure : une Boucle
        // compilee une fois, ou les gens continuent de parler sans jamais se
        // taire. Mesurer l'attente depuis le DERNIER message ne la rattraperait
        // jamais — ce message est recent par construction.
        //
        // L'attente se mesure depuis la derniere COMPILATION.
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.',
            now()->subMinutes(300));

        $this->fakeAgent('Le budget travaux de Belleville est de 486 000 euros.');

        $dispatcher = app(LoopConversationKnowledgeDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue(), 'PREMISSE : une premiere compilation a bien eu lieu');

        $connu = now()->parse(DerivedKnowledgeNote::query()->max('observed_at'));
        $this->assertTrue($connu->lessThan(now()->subMinutes(self::PLAFOND)),
            'PREMISSE : la derniere compilation est plus ancienne que le plafond');

        // Puis quatre heures de conversation continue, jusqu'a maintenant.
        $this->conversationContinue(heures: 4, toutesLesMinutes: 5);

        $this->assertTrue(
            now()->parse(LoopMessage::where('loop_id', $this->loop->id)->max('created_at'))
                ->greaterThan(now()->subMinutes(self::CALME)),
            'PREMISSE : et elle ne se tait toujours pas',
        );

        $appels = 0;
        $this->fakeAgent('Une tranche de voirie est ajoutee sur le parvis nord de Belleville.',
            function () use (&$appels): void {
                $appels++;
            });

        $this->assertSame(1, $dispatcher->dispatchDue(),
            'le retard se mesure depuis la derniere compilation, jamais depuis le dernier message');
        $this->assertSame(1, $appels);
        $this->assertSame(2, DerivedKnowledgeNote::query()->claims()->active()->count(),
            'la matiere accumulee pendant la conversation continue est bien apprise');
    }

    public function test_en_deca_du_plafond_une_conversation_en_cours_attend_toujours(): void
    {
        // Une heure de conversation continue : en cours, et sous le plafond.
        // Rien ne doit se declencher — sinon le plafond aurait remplace la
        // fenetre de calme au lieu de la borner.
        $this->conversationContinue(heures: 1, toutesLesMinutes: 5);

        $appels = 0;
        $this->fakeAgent('…', function () use (&$appels): void {
            $appels++;
        });

        $this->assertSame(0, app(LoopConversationKnowledgeDispatcher::class)->dispatchDue(),
            'on ne compile pas un echange en cours tant que l attente reste raisonnable');
        $this->assertSame(0, $appels);
    }

    public function test_le_plafond_ne_casse_pas_le_debounce(): void
    {
        // Huit messages en quelques minutes, puis le calme. C'est le cas
        // nominal de T1539 : il doit continuer de donner UN seul appel.
        foreach (range(1, 8) as $i) {
            $this->message("Point numero {$i} sur le chantier Belleville, avec assez de matiere pour compter.",
                now()->subMinutes(40 - $i));
        }

        $appels = 0;
        $this->fakeAgent('Huit points ont ete abordes sur le chantier Belleville.',
            function () use (&$appels): void {
                $appels++;
            });

        $this->assertSame(1, app(LoopConversationKnowledgeDispatcher::class)->dispatchDue());
        $this->assertSame(1, $appels, 'huit messages rapproches -> UN appel, jamais huit');
    }

    // ─────────────────────────────── le plafond n'est pas une horloge

    public function test_une_boucle_sans_matiere_non_apprise_n_est_jamais_due_par_le_plafond(): void
    {
        // Une Boucle compilee il y a longtemps, et plus rien dit depuis. Son
        // retard d'apprentissage est de zero : il n'y a rien a apprendre.
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.',
            now()->subMinutes(self::PLAFOND * 3));

        $this->fakeAgent('Le budget travaux de Belleville est de 486 000 euros.');

        $dispatcher = app(LoopConversationKnowledgeDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue(), 'PREMISSE : elle a bien ete compilee');

        // La memoire est plus vieille que le plafond — et cela ne doit RIEN
        // declencher. Un plafond qui se contenterait de regarder l anciennete
        // redispatcherait toutes les Boucles du depot, indefiniment.
        $connu = DerivedKnowledgeNote::query()->max('observed_at');
        $this->assertTrue(now()->parse($connu)->lessThan(now()->subMinutes(self::PLAFOND)),
            'PREMISSE : la memoire EST plus ancienne que le plafond');

        $appels = 0;
        $this->fakeAgent('…', function () use (&$appels): void {
            $appels++;
        });

        $this->assertSame(0, $dispatcher->dispatchDue(),
            'rien de non appris : le plafond ne doit pas se comporter en horloge');
        $this->assertSame(0, $appels);
    }

    public function test_le_plafond_ne_peut_pas_etre_plus_court_que_la_fenetre_de_calme(): void
    {
        // Une configuration incoherente — plafond d'UNE minute, calme de dix —
        // ferait compiler tous les echanges en cours a chaque balayage : le
        // debounce ne servirait plus a rien, et chaque Boucle vivante
        // couterait un appel toutes les minutes.
        config(['ai.knowledge.conversation.max_learning_delay_minutes' => 1]);

        // Six minutes de conversation en cours. Le dernier message date de
        // maintenant : la fenetre de calme ne peut pas s'appliquer. La matiere
        // non apprise, elle, n'a que six minutes — moins que le calme.
        //
        // Le plafond effectif ne descend jamais sous le calme : six minutes
        // d'attente ne suffisent donc pas. Avec le plafond brut d'une minute,
        // cette Boucle serait due.
        foreach ([6, 4, 2, 0] as $minutes) {
            $this->message(
                "Point a T-{$minutes} sur le chantier Belleville, avec assez de matiere pour etre compte.",
                now()->subMinutes($minutes),
            );
        }

        $appels = 0;
        $this->fakeAgent('…', function () use (&$appels): void {
            $appels++;
        });

        $this->assertSame(0, app(LoopConversationKnowledgeDispatcher::class)->dispatchDue(),
            'un plafond mal configure ne doit pas prendre la main sur la fenetre de calme');
        $this->assertSame(0, $appels);
    }

    // ─────────────────────────────── le cout reste borne

    public function test_le_plafond_ne_produit_pas_un_appel_par_balayage(): void
    {
        // La propriete de cout : une fois la Boucle compilee, les balayages
        // suivants ne redeclenchent rien tant qu aucun message nouveau n est
        // arrive — meme si la conversation continue de tourner en boucle sur
        // les memes messages deja lus.
        $this->conversationContinue(heures: 4, toutesLesMinutes: 5);

        $appels = 0;
        $this->fakeAgent('Le budget travaux de Belleville est de 486 000 euros.',
            function () use (&$appels): void {
                $appels++;
            });

        $dispatcher = app(LoopConversationKnowledgeDispatcher::class);
        $dispatcher->dispatchDue();
        $dispatcher->dispatchDue();
        $dispatcher->dispatchDue();

        $this->assertSame(1, $appels,
            'trois balayages sur la meme matiere : un seul appel');
    }

    // ────────────────────────────────────────────────── helpers

    /**
     * Une conversation qui ne se tait jamais : un message toutes les N
     * minutes, avec N strictement inferieur a la fenetre de calme, jusqu'a
     * l'instant present.
     */
    private function conversationContinue(int $heures, int $toutesLesMinutes): void
    {
        $this->assertLessThan(self::CALME, $toutesLesMinutes,
            'PREMISSE de la fixture : parler moins souvent que le calme ne prouverait rien');

        for ($minutes = $heures * 60; $minutes >= 0; $minutes -= $toutesLesMinutes) {
            $this->message(
                "Point a T-{$minutes} sur le chantier Belleville, avec assez de matiere pour etre compte.",
                now()->subMinutes($minutes),
            );
        }
    }

    private function message(string $body, \DateTimeInterface $quand): LoopMessage
    {
        $message = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => $body,
            'type' => 'user',
        ]);

        // `created_at` n'est pas fillable : il faut forcer ET ecrire. Une
        // premiere version oubliait le `save()`, et tous les messages
        // restaient dates de maintenant — la conversation etait « en cours »
        // dans TOUS les tests, y compris ceux du debounce.
        $message->forceFill(['created_at' => $quand, 'updated_at' => $quand])->save();

        return $message;
    }

    private function fakeAgent(string $texte, ?callable $compteur = null): void
    {
        LoopClaimPatchAgent::fake(function () use ($texte, $compteur): TextResponse {
            if ($compteur !== null) {
                $compteur();
            }

            $preuve = LoopMessage::query()
                ->where('loop_id', $this->loop->id)
                ->where('type', 'user')
                ->whereRaw('length(trim(body)) >= ?', [LoopConversationKnowledgeDeriver::MIN_MESSAGE_CHARS])
                ->orderByDesc('created_at')
                ->value('id');

            return new TextResponse(
                (string) json_encode(['operations' => [
                    ['op' => 'ADD', 'text' => $texte, 'evidence' => [(string) $preuve]],
                ]], JSON_UNESCAPED_UNICODE),
                new Usage(30, 12), new Meta('openrouter', 'openai/gpt-4o-mini'),
            );
        });
    }
}
