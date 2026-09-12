<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Jobs\DeriveLoopConversationKnowledge;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Knowledge\DerivedKnowledgeNoteIndexer;
use App\Services\Knowledge\LoopClaimCompiler;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use App\Services\Knowledge\LoopConversationKnowledgeDispatcher;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1539 — BouclePro apprend EN TRAVAILLANT, sans commande manuelle et sans
 * appeler un modele apres chaque phrase.
 *
 * ## Pourquoi un balayeur, et pas un observer sur `LoopMessage`
 *
 * Un observer appellerait le modele a chaque message, « ok » et « merci »
 * compris. Un `delay()` par message ne resout rien non plus : dix messages
 * rapproches produiraient dix jobs differes que `WithoutOverlapping` se
 * contenterait de SERIALISER — dix derivations, pas une.
 *
 * Ce qui fait converger N messages vers UNE compilation, c'est une **fenetre
 * d'inactivite** : on ne compile pas une conversation en cours, on attend
 * qu'elle se soit posee. Un balayeur periodique selectionne les Boucles
 * calmees ET porteuses de nouveaute, et dispatche un job par Boucle.
 *
 * Le budget economique n'est pas la strategie de frequence : il refuse une
 * depense, il ne decide pas d'un rythme.
 */
class TASK1539AutomaticWriteTriggerTest extends TestCase
{
    use RefreshDatabase;

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
            'api_key' => 'sk-tenant-1539',
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
            'ai.knowledge.conversation.quiet_minutes' => 10,
            'ai_pricing.overrides' => [],
        ]);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (): array => array_fill(0, 1536, 0.01),
            $prompt->inputs,
        ))->preventStrayEmbeddings();
    }

    // ─────────────────────────────────────────── DEBOUNCE_CALL_COUNT

    public function test_huit_messages_rapproches_ne_produisent_qu_une_seule_derivation(): void
    {
        // Une vraie sequence de travail : huit messages en quelques minutes.
        foreach (range(1, 8) as $i) {
            $this->message("Point numero {$i} sur le chantier Belleville, avec suffisamment de matiere pour compter.",
                now()->subMinutes(40 - $i));
        }

        $appels = 0;
        $this->fakeAgent('Huit points ont ete abordes sur le chantier Belleville.',
            function () use (&$appels): void {
                $appels++;
            });

        $dispatches = app(LoopConversationKnowledgeDispatcher::class)->dispatchDue();

        $this->assertSame(1, $dispatches, 'une seule Boucle a compiler, donc un seul job');
        $this->assertSame(1, $appels,
            'DEBOUNCE_CALL_COUNT : huit messages rapproches -> UN appel, jamais huit');
        $this->assertSame(1, DerivedKnowledgeNote::query()->claims()->active()->count());
    }

    public function test_une_conversation_encore_en_cours_n_est_pas_compilee(): void
    {
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.', now()->subMinutes(30));
        // Quelqu'un vient de parler : la conversation n'est pas posee.
        $this->message('Attends, je verifie le chiffre exact avant qu on acte quoi que ce soit.', now()->subMinute());

        $appels = 0;
        $this->fakeAgent('…', function () use (&$appels): void {
            $appels++;
        });

        $this->assertSame(0, app(LoopConversationKnowledgeDispatcher::class)->dispatchDue(),
            'on ne compile pas une conversation en cours : on attend qu elle se pose');
        $this->assertSame(0, $appels);
        $this->assertSame(0, DerivedKnowledgeNote::query()->count());
    }

    public function test_un_message_banal_apres_compilation_ne_coute_aucun_appel(): void
    {
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.', now()->subHour());

        $this->fakeAgent('Le budget travaux de Belleville est de 486 000 euros.');
        app(LoopConversationKnowledgeDispatcher::class)->dispatchDue();
        $this->assertSame(1, DerivedKnowledgeNote::query()->claims()->active()->count());

        // « ok » n'apporte aucun fait : il n'entre meme pas dans la source.
        $this->message('ok', now()->subMinutes(30));
        $this->message('merci 👍', now()->subMinutes(29));

        $appels = 0;
        $this->fakeAgent('…', function () use (&$appels): void {
            $appels++;
        });

        app(LoopConversationKnowledgeDispatcher::class)->dispatchDue();

        $this->assertSame(0, $appels,
            'un « ok » ou un emoji ne doit jamais declencher un appel de modele');
        $this->assertSame(1, DerivedKnowledgeNote::query()->claims()->active()->count());
    }

    public function test_une_boucle_deja_a_jour_n_est_pas_redispatchee(): void
    {
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.', now()->subHour());
        $this->fakeAgent('Le budget travaux de Belleville est de 486 000 euros.');

        $dispatcher = app(LoopConversationKnowledgeDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue());
        $this->assertSame(0, $dispatcher->dispatchDue(),
            'rien de neuf depuis la derniere compilation : aucun job');
    }

    // ─────────────────────────────────────────── discipline de queue

    public function test_le_job_part_sur_sa_queue_dediee_et_jamais_sur_default(): void
    {
        Queue::fake();

        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.', now()->subHour());
        app(LoopConversationKnowledgeDispatcher::class)->dispatchDue();

        Queue::assertPushedOn(LoopConversationKnowledgeDispatcher::DEDICATED_QUEUE, DeriveLoopConversationKnowledge::class);
        $this->assertNotSame('default', LoopConversationKnowledgeDispatcher::DEDICATED_QUEUE,
            'la queue `default` porte des jobs historiques en quarantaine : jamais celle-ci');
    }

    public function test_deux_jobs_sur_la_meme_boucle_ne_se_chevauchent_pas(): void
    {
        $job = new DeriveLoopConversationKnowledge((string) $this->loop->id);
        $milieu = collect($job->middleware())->first(fn ($m): bool => $m instanceof WithoutOverlapping);

        $this->assertNotNull($milieu, 'deux compilations simultanees de la MEME Boucle se marcheraient dessus');
        $this->assertStringContainsString((string) $this->loop->id, $job->overlapKey(),
            'le verrou porte sur la Boucle, pas sur le service entier');
    }

    public function test_rejouer_le_job_ne_produit_pas_une_seconde_note(): void
    {
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.', now()->subHour());
        $this->fakeAgent('Le budget travaux de Belleville est de 486 000 euros.');

        $job = new DeriveLoopConversationKnowledge((string) $this->loop->id);
        $lancer = fn (): null => $job->handle(
            app(LoopClaimCompiler::class),
            app(DerivedKnowledgeNoteIndexer::class),
        );

        $lancer();
        $lancer();

        $this->assertSame(1, DerivedKnowledgeNote::query()->claims()->active()->count(),
            'un retry ne doit pas dupliquer la connaissance');
    }

    public function test_la_matiere_brute_n_est_jamais_perdue(): void
    {
        foreach (range(1, 5) as $i) {
            $this->message("Point numero {$i} sur le chantier, avec assez de matiere pour etre compte.",
                now()->subMinutes(40 - $i));
        }

        $avant = LoopMessage::where('loop_id', $this->loop->id)->count();

        $this->fakeAgent('Cinq points ont ete abordes.');
        app(LoopConversationKnowledgeDispatcher::class)->dispatchDue();

        $this->assertSame($avant, LoopMessage::where('loop_id', $this->loop->id)->count(),
            'la compilation LIT l activite, elle ne la consomme jamais');
    }

    public function test_une_boucle_d_une_organization_sans_credential_ne_coute_rien(): void
    {
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->delete();

        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.', now()->subHour());

        $appels = 0;
        $this->fakeAgent('…', function () use (&$appels): void {
            $appels++;
        });

        app(LoopConversationKnowledgeDispatcher::class)->dispatchDue();

        $this->assertSame(0, $appels);
        $this->assertSame(0, DerivedKnowledgeNote::query()->count());
    }

    public function test_une_boucle_que_le_deriver_refusera_n_est_jamais_mise_en_file(): void
    {
        // Le balayeur doit lire EXACTEMENT la population du deriver. Sinon il
        // promet un travail impossible, et la Boucle est redispatchee a chaque
        // balayage, sans fin. Mesure en environnement reel : trois Boucles
        // sentinelles sans Dossier racine y restaient bloquees.
        $orpheline = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Boucle sans Dossier racine',
            'visibility' => 'private',
        ]);

        LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $orpheline->id,
            'sender_id' => $this->alice->id,
            'body' => 'Un fait parfaitement durable et suffisamment long pour compter.',
            'type' => 'user',
        ])->forceFill(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()])->save();

        $dues = array_map(static fn (Loop $l): string => (string) $l->id,
            app(LoopConversationKnowledgeDispatcher::class)->due());

        $this->assertNotContains((string) $orpheline->id, $dues,
            'sans Dossier racine, le deriver refuse : la mettre en file ferait tourner la file a vide');
    }

    public function test_une_boucle_ou_l_on_n_a_dit_que_ok_n_est_jamais_mise_en_file(): void
    {
        $bavardage = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Boucle sans matiere',
            'visibility' => 'private',
        ]);

        app(LoopRootDocumentService::class)->ensureRootDossier($bavardage->fresh());

        foreach (['ok', 'merci 👍', 'ah'] as $i => $mot) {
            LoopMessage::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $bavardage->id,
                'sender_id' => $this->alice->id,
                'body' => $mot,
                'type' => 'user',
            ])->forceFill(['created_at' => now()->subHour()->addMinutes($i), 'updated_at' => now()->subHour()])->save();
        }

        $dues = array_map(static fn (Loop $l): string => (string) $l->id,
            app(LoopConversationKnowledgeDispatcher::class)->due());

        $this->assertNotContains((string) $bavardage->id, $dues,
            'aucun message n atteint le seuil du deriver : il n y a rien a compiler');
    }

    // ─────────────────────────────────────────── helpers

    private function message(string $body, ?\DateTimeInterface $quand = null): LoopMessage
    {
        $m = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => $body,
            'type' => 'user',
        ]);

        if ($quand !== null) {
            $m->forceFill(['created_at' => $quand, 'updated_at' => $quand])->save();
        }

        return $m;
    }

    /**
     * TASK-1541 — le chemin automatique compile des ENONCES.
     *
     * Les proprietes mesurees ici n'ont pas bouge d'un pouce : une fenetre
     * d'inactivite, un appel pour N messages, zero appel pour un « ok », pas de
     * redispatch d'une Boucle a jour. Seul a change l'agent qui les porte — et
     * c'est precisement pour cela que ces tests doivent suivre le chemin reel
     * plutot que rester verts sur un agent que plus personne n'appelle.
     *
     * Le patch reprend le texte demande : un ADD dont la preuve est le dernier
     * message assez long. Un patch sans preuve valide serait rejete, et
     * « aucun enonce ecrit » se confondrait avec « aucun appel ».
     */
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
