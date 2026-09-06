<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopAnswerCapitalizationService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1310 — « Ajouter au Dossier » : capitaliser une réponse IA du ChatLoop
 * en Article du Dossier de la Boucle, APRES relecture et validation humaine.
 *
 * Ce que ces tests protègent :
 *
 *  - les TROIS moteurs (IA, Dossiers, IA + Dossiers) sont capitalisables, et
 *    RIEN d'autre — ni message humain, ni agent de membre, ni bulle supprimée ;
 *  - l'auteur de l'Article est TOUJOURS l'humain qui valide, jamais l'IA ;
 *  - titre et contenu sont modifiables AVANT enregistrement, et c'est la
 *    version modifiée qui est persistée ;
 *  - le `dossier_id` venu du front n'est jamais cru sur parole : tenant,
 *    Boucle et droit d'écriture sont revalidés côté serveur ;
 *  - la provenance est complète et honnête — les sources recopiées sont les
 *    sources CITÉES (T1309), jamais les seulement consultées ;
 *  - l'indexation passe par le pipeline canonique existant, sans qu'aucun
 *    indexeur nouveau ne soit appelé ;
 *  - AUCUN appel provider supplémentaire : la feature entière coûte zéro.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1310AddAnswerToDossierTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    /** Droit d'ecriture REEL : DossierPolicy delegue a LoopPolicy::update. */
    private User $curator;

    /** Membre ORDINAIRE : lit la Boucle, n'ecrit pas dans son Dossier. */
    private User $member;

    private User $stranger;

    private Loop $loop;

    private Dossier $rootDossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'LaunchPals', 'slug' => 'launchpals']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Autre Org', 'slug' => 'autre-org']);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->curator = $this->owner;
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        app()->instance('current_organization', $this->organization);
        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->owner, 'Boucle capitalisation');
        $loopService->addMember($this->loop, $this->member, 'member');

        $this->rootDossier = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();

        config(['ai.chatloop.enabled' => true]);

        // Aucune indexation reelle : les jobs sont captures, ce qui permet
        // AUSSI de prouver que c'est bien le pipeline canonique qui part.
        Queue::fake();
        Http::preventStrayRequests();
    }

    // =====================================================================
    // A. Les trois moteurs sont capitalisables — et rien d'autre.
    // =====================================================================

    /**
     * @return list<array{0: string}>
     */
    public static function capitalizableModes(): array
    {
        return [['llm'], ['rag'], ['llm_rag']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('capitalizableModes')]
    public function test_every_ai_engine_can_be_added_to_the_dossier(string $aiMode): void
    {
        $message = $this->aiMessage($aiMode);

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.capitalize_action'))
            ->call('startCapitalization', $message->id)
            ->assertSet('capitalizingMessageId', $message->id)
            ->call('saveCapitalization')
            ->assertHasNoErrors();

        $post = BlogPost::query()->whereNotNull('ai_origin')->sole();
        $this->assertSame((string) $message->id, $post->ai_origin['source_loop_message_id']);

        $this->assertSame($aiMode, $post->ai_origin['ai_mode']);
        $this->assertSame(LoopAnswerCapitalizationService::ORIGIN_AI_SYNTHESIS, $post->ai_origin['origin_type']);
    }

    public function test_a_human_message_never_offers_the_action(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Un message humain ordinaire.',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Un message humain ordinaire.')
            ->assertDontSee(__('loops.capitalize_action'));
    }

    public function test_a_member_agent_message_and_a_deleted_bubble_are_never_capitalizable(): void
    {
        $agent = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Reponse de l agent du membre.',
            'type' => 'member_agent',
            'metadata' => ['ai_generated' => true],
            'organization_id' => $this->loop->organization_id,
        ]);

        $deleted = $this->aiMessage('rag');
        $deleted->forceFill(['deleted_at' => now()])->saveQuietly();

        $service = app(LoopAnswerCapitalizationService::class);
        $this->assertFalse($service->isCapitalizable($this->loop, $agent));
        $this->assertFalse($service->isCapitalizable($this->loop, $deleted->fresh()));

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSee(__('loops.capitalize_action'));
    }

    // =====================================================================
    // B. L'invariant : l'auteur est l'humain qui valide.
    // =====================================================================

    public function test_the_article_author_is_always_the_validating_human(): void
    {
        $message = $this->aiMessage('llm_rag');

        $post = app(LoopAnswerCapitalizationService::class)->capitalize(
            $this->loop, $this->curator, $message, (string) $this->rootDossier->id,
            'Synthese relue', 'Contenu valide par un humain.',
        );

        $this->assertSame($this->curator->id, $post->user_id, 'l\'Article appartient a l\'humain, jamais a l\'IA');
        $this->assertSame($this->curator->id, $post->ai_origin['human_curator_id']);
        // La bulle IA n'a pas de sender : elle ne peut donc pas etre l'auteur.
        $this->assertNull($message->sender_id);
    }

    // =====================================================================
    // C. Le brouillon est REELLEMENT editable avant enregistrement.
    // =====================================================================

    public function test_title_and_content_are_prefilled_then_editable_before_saving(): void
    {
        $message = $this->aiMessage('rag', body: 'La reponse originale de l IA.', question: 'Que disent nos documents ?');

        $this->actingAs($this->curator);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $message->id);

        // Pre-rempli depuis la QUESTION, sans aucun appel provider.
        $component->assertSet('capitalizeTitle', 'Que disent nos documents')
            ->assertSet('capitalizeContent', 'La reponse originale de l IA.');

        $component->set('capitalizeTitle', 'Titre reecrit par l humain')
            ->set('capitalizeContent', 'Contenu corrige par l humain.')
            ->call('saveCapitalization')
            ->assertHasNoErrors();

        $post = BlogPost::query()->whereNotNull('ai_origin')->sole();
        $this->assertSame('Titre reecrit par l humain', $post->title);
        $this->assertSame('Contenu corrige par l humain.', $post->content);
    }

    public function test_an_empty_title_or_content_is_refused(): void
    {
        $message = $this->aiMessage('rag');

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $message->id)
            ->set('capitalizeTitle', '   ')
            ->call('saveCapitalization')
            ->assertHasErrors('capitalizeTitle');

        $this->assertSame(0, BlogPost::query()->whereNotNull('ai_origin')->count());
    }

    // =====================================================================
    // D. Rattachement et indexation par le chemin canonique.
    // =====================================================================

    public function test_the_article_is_attached_to_the_chosen_dossier_and_indexed_by_the_existing_pipeline(): void
    {
        $message = $this->aiMessage('rag');

        $post = app(LoopAnswerCapitalizationService::class)->capitalize(
            $this->loop, $this->curator, $message, (string) $this->rootDossier->id, 'Titre', 'Contenu',
        );

        $this->assertDatabaseHas('dossier_blog_posts', [
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->rootDossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->curator->id,
        ]);
        $this->assertTrue($post->loops()->where('loops.id', $this->loop->id)->exists());

        // Publie et `audience = loop` : les DEUX conditions que
        // `publiclyReadable()` exige pour que l'indexation le voie, sans
        // jamais paraitre dans le Blog public.
        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertSame(BlogPost::AUDIENCE_LOOP, $post->audience);
        $this->assertFalse((bool) $post->listed_in_blog);
        $this->assertTrue(
            BlogPost::query()->whereKey($post->id)->publiclyReadable()->exists(),
            'l\'Article doit etre eligible a l\'indexation canonique',
        );

        // Le pipeline canonique est bien parti — via BlogPostObserver, sans
        // qu'aucun indexeur ne soit appele par TASK-1310.
        Queue::assertPushed(\App\Jobs\IndexDossierArticleChunks::class);
    }

    public function test_the_whole_feature_costs_no_provider_call_at_all(): void
    {
        $message = $this->aiMessage('llm_rag');

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $message->id)
            ->call('saveCapitalization')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('ai_provider_invocations', 0);
        $this->assertDatabaseCount('ai_interactions', 0);
    }

    // =====================================================================
    // E. Provenance — complete, et honnete.
    // =====================================================================

    public function test_the_provenance_is_persisted_in_full(): void
    {
        $message = $this->aiMessage('llm_rag', sources: [
            ['ref' => 'S1', 'title' => 'Manifeste.md', 'dossier_name' => 'Dossier', 'excerpt' => 'extrait', 'url' => 'https://test.laravel/x'],
        ], interactionId: '01a04011-0000-7000-8000-000000000001');

        $post = app(LoopAnswerCapitalizationService::class)->capitalize(
            $this->loop, $this->curator, $message, (string) $this->rootDossier->id, 'Titre', 'Contenu',
        );

        $origin = $post->ai_origin;
        $this->assertSame(LoopAnswerCapitalizationService::ORIGIN_AI_SYNTHESIS, $origin['origin_type']);
        $this->assertSame((string) $message->id, $origin['source_loop_message_id']);
        $this->assertSame((string) $this->loop->id, $origin['source_loop_id']);
        $this->assertSame('01a04011-0000-7000-8000-000000000001', $origin['ai_interaction_id']);
        $this->assertSame('llm_rag', $origin['ai_mode']);
        $this->assertSame((string) $this->curator->id, $origin['human_curator_id']);
        $this->assertSame(['S1'], array_column($origin['sources'], 'ref'));
    }

    /**
     * TASK-1309 : `sources` = les sources REELLEMENT CITEES. Un document
     * seulement consulte n'a etaye aucune affirmation — le recopier dans la
     * provenance de l'Article en ferait retroactivement un appui.
     */
    public function test_only_cited_sources_are_carried_over_never_the_merely_consulted_ones(): void
    {
        $message = $this->aiMessage('rag');
        $message->forceFill(['metadata' => [
            'ai_mode' => 'rag',
            'sources' => [],
            'consulted' => [
                ['ref' => 'S1', 'title' => 'Document seulement consulte', 'dossier_name' => 'D', 'excerpt' => 'x', 'url' => null],
            ],
        ]])->saveQuietly();

        $post = app(LoopAnswerCapitalizationService::class)->capitalize(
            $this->loop, $this->curator, $message->fresh(), (string) $this->rootDossier->id, 'Titre', 'Contenu',
        );

        $this->assertSame([], $post->ai_origin['sources']);
        $this->assertStringNotContainsString('seulement consulte', json_encode($post->ai_origin));
    }

    /**
     * La distinction que TASK-1310 doit PRESERVER : une source primaire porte
     * `ai_origin = null`, une synthese IA validee porte l'objet. C'est ce qui
     * permettra plus tard de ne pas confondre les deux dans le RAG — sans
     * qu'aucune ponderation ne soit implementee ici.
     */
    public function test_a_primary_source_article_keeps_a_null_origin(): void
    {
        $primary = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'title' => 'Article ecrit a la main',
            'content' => 'Un humain a ecrit ceci.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->assertNull($primary->fresh()->ai_origin, 'un Article ordinaire est une SOURCE PRIMAIRE');

        $synthesis = app(LoopAnswerCapitalizationService::class)->capitalize(
            $this->loop, $this->curator, $this->aiMessage('rag'), (string) $this->rootDossier->id, 'Titre', 'Contenu',
        );

        $this->assertNotNull($synthesis->ai_origin);
        $this->assertSame(LoopAnswerCapitalizationService::ORIGIN_AI_SYNTHESIS, $synthesis->ai_origin['origin_type']);
    }

    // =====================================================================
    // F. Autorite : tenant, Boucle, permission, requete forgee.
    // =====================================================================

    public function test_a_stranger_of_another_organization_is_refused(): void
    {
        $message = $this->aiMessage('rag');

        $this->expectException(RuntimeException::class);

        try {
            app(LoopAnswerCapitalizationService::class)->capitalize(
                $this->loop, $this->stranger, $message, (string) $this->rootDossier->id, 'Titre', 'Contenu',
            );
        } finally {
            $this->assertSame(0, BlogPost::query()->whereNotNull('ai_origin')->count());
        }
    }

    public function test_a_member_of_the_organization_who_is_not_in_the_loop_is_refused(): void
    {
        $outsider = User::factory()->create(['organization_id' => $this->organization->id]);
        $message = $this->aiMessage('rag');

        $this->expectException(RuntimeException::class);

        try {
            app(LoopAnswerCapitalizationService::class)->capitalize(
                $this->loop, $outsider, $message, (string) $this->rootDossier->id, 'Titre', 'Contenu',
            );
        } finally {
            $this->assertSame(0, BlogPost::query()->whereNotNull('ai_origin')->count());
        }
    }

    /**
     * LE test de requete forgee : un `dossier_id` d'une AUTRE Organization,
     * fourni directement au service. L'UI n'est pas la barriere — le
     * perimetre ecrivable est recalcule cote serveur pour CET utilisateur et
     * CETTE Boucle, et un identifiant qui n'y figure pas est refuse.
     */
    public function test_a_forged_dossier_id_from_another_organization_is_refused(): void
    {
        $foreignDossier = Dossier::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'owner_id' => $this->stranger->id,
            'name' => 'SECRET-T1310-OTHER-ORG',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);

        $message = $this->aiMessage('rag');

        $this->expectException(RuntimeException::class);

        try {
            app(LoopAnswerCapitalizationService::class)->capitalize(
                $this->loop, $this->curator, $message, (string) $foreignDossier->id, 'Titre', 'Contenu',
            );
        } finally {
            $this->assertSame(0, DossierBlogPost::query()->where('dossier_id', $foreignDossier->id)->count());
            $this->assertSame(0, BlogPost::query()->where('organization_id', $this->otherOrganization->id)->count());
        }
    }

    public function test_a_forged_dossier_id_of_another_loop_is_refused(): void
    {
        $otherLoop = (new LoopService)->createLoop($this->owner, 'Autre Boucle');
        $otherLoopDossier = Dossier::query()->where('loop_id', $otherLoop->id)->firstOrFail();

        $message = $this->aiMessage('rag');

        // Le Dossier racine d'une Boucle porte DEJA son document racine : ce
        // qui doit rester intact, c'est le COMPTE, pas le zero absolu.
        $before = DossierBlogPost::query()->where('dossier_id', $otherLoopDossier->id)->count();

        $this->expectException(RuntimeException::class);

        try {
            app(LoopAnswerCapitalizationService::class)->capitalize(
                $this->loop, $this->curator, $message, (string) $otherLoopDossier->id, 'Titre', 'Contenu',
            );
        } finally {
            $this->assertSame($before, DossierBlogPost::query()->where('dossier_id', $otherLoopDossier->id)->count());
            $this->assertSame(0, BlogPost::query()->whereNotNull('ai_origin')->count());
        }
    }

    /**
     * Une bulle d'une AUTRE Boucle, passee directement au service : elle n'est
     * pas capitalisable ici, meme si son auteur a tous les droits dans SA
     * Boucle.
     */
    public function test_a_message_from_another_loop_is_never_capitalizable_here(): void
    {
        $otherLoop = (new LoopService)->createLoop($this->owner, 'Autre Boucle');
        $foreignMessage = LoopMessage::create([
            'loop_id' => $otherLoop->id,
            'sender_id' => null,
            'body' => 'SECRET-T1310-OTHER-LOOP',
            'type' => 'ai',
            'metadata' => ['ai_mode' => 'rag'],
            'organization_id' => $otherLoop->organization_id,
        ]);

        $service = app(LoopAnswerCapitalizationService::class);
        $this->assertFalse($service->isCapitalizable($this->loop, $foreignMessage));

        $this->expectException(RuntimeException::class);

        try {
            $service->capitalize($this->loop, $this->curator, $foreignMessage, (string) $this->rootDossier->id, 'Titre', 'Contenu');
        } finally {
            $this->assertSame(0, BlogPost::query()->whereNotNull('ai_origin')->count());
        }
    }

    /**
     * Le composant lui-meme ne sert pas de porte derobee : `startCapitalization`
     * relit le message DANS la Boucle courante et n'ouvre rien pour un
     * identifiant etranger.
     */
    public function test_the_component_never_opens_a_draft_for_a_foreign_message(): void
    {
        $otherLoop = (new LoopService)->createLoop($this->owner, 'Autre Boucle');
        $foreignMessage = LoopMessage::create([
            'loop_id' => $otherLoop->id,
            'sender_id' => null,
            'body' => 'SECRET-T1310-OTHER-LOOP',
            'type' => 'ai',
            'metadata' => ['ai_mode' => 'rag'],
            'organization_id' => $otherLoop->organization_id,
        ]);

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $foreignMessage->id)
            ->assertSet('capitalizingMessageId', null);
    }

    // =====================================================================
    // G. Perimetre des Dossiers proposes.
    // =====================================================================

    public function test_only_dossiers_writable_in_this_loop_are_offered(): void
    {
        // Un Dossier prive d'un AUTRE membre, dans la meme Organization : ni
        // dans la Boucle, ni ecrivable.
        Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier prive hors Boucle',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $writable = app(LoopAnswerCapitalizationService::class)->writableDossiers($this->loop, $this->curator);

        $this->assertNotEmpty($writable);
        foreach ($writable as $dossier) {
            $this->assertSame($this->organization->id, $dossier->organization_id);
            $this->assertNotSame('Dossier prive hors Boucle', $dossier->name);
        }
    }

    public function test_the_loop_dossier_is_preselected_without_any_hardcoded_id(): void
    {
        $default = app(LoopAnswerCapitalizationService::class)->defaultDossier($this->loop, $this->curator);

        $this->assertNotNull($default);
        $this->assertSame($this->rootDossier->id, $default->id);
        // Resolu DEPUIS la Boucle, jamais depuis une constante.
        $this->assertSame($this->loop->id, $default->loop_id);
    }

    public function test_a_stranger_gets_no_writable_dossier_at_all(): void
    {
        $this->assertTrue(
            app(LoopAnswerCapitalizationService::class)->writableDossiers($this->loop, $this->stranger)->isEmpty(),
        );
    }

    // =====================================================================
    // Regressions trouvees par la recette REELLE, invisibles aux tests d'abord
    // =====================================================================

    /**
     * L'action doit etre un VRAI bouton dans le HTML, pas seulement un libelle
     * present quelque part.
     *
     * REGRESSION VECUE : l'action etait initialement rendue dans le slot par
     * defaut de `x-conversation.message-bubble`, lequel passe integralement
     * par `markdown($renderableBody)` — le balisage y etait avale et l'action
     * n'existait pas dans la page. Sur le banc reel : 0 action visible pour le
     * proprietaire, alors que le serveur l'autorisait pleinement.
     *
     * Et pourquoi les tests n'ont rien vu : `assertSee(__('loops.capitalize_action'))`
     * passait quand meme, parce que le TEXTE survivait a la traversee du
     * markdown. Un test qui n'assertait que le libelle validait une action
     * incliquable. On asserte donc l'ATTRIBUT porteur du clic.
     */
    public function test_the_action_is_a_real_button_and_not_swallowed_by_the_markdown_pipeline(): void
    {
        $message = $this->aiMessage('rag');

        $this->actingAs($this->curator);

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-capitalize-open="'.$message->id.'"')
            ->assertSeeHtml('wire:click="startCapitalization(\''.$message->id.'\')"');
    }

    /**
     * La confirmation doit survivre a un re-render.
     *
     * REGRESSION VECUE : elle etait portee par un flash de session. Cette page
     * porte un `wire:poll` ; le premier re-render venu LIT donc le flash — ce
     * qui le consomme — et l'utilisateur ne voit jamais rien. En recette
     * reelle l'Article etait bel et bien enregistre, mais l'ecran restait
     * muet : le pire des echecs, celui qui pousse a recommencer. C'est
     * exactement ainsi qu'un Article en double a ete cree.
     *
     * Le re-render est ici provoque explicitement : c'est ce que fait le poll.
     */
    public function test_the_confirmation_survives_the_re_render_that_a_poll_triggers(): void
    {
        $message = $this->aiMessage('rag');

        $this->actingAs($this->curator);

        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $message->id)
            ->call('saveCapitalization')
            ->assertHasNoErrors();

        $dossierName = (string) $this->rootDossier->name;
        $expected = __('loops.capitalize_saved', ['dossier' => $dossierName]);

        $component->assertSet('capitalizeFlash', $expected)->assertSee($expected);

        // Le re-render que declenche `wire:poll`. Un flash de session aurait
        // disparu ici ; l'etat du composant, non.
        $component->call('$refresh')
            ->assertSet('capitalizeFlash', $expected)
            ->assertSee($expected);
    }

    /**
     * Rouvrir le brouillon efface la confirmation precedente : sans cela, une
     * seconde capitalisation s'ouvrirait sous le message de succes de la
     * premiere, et l'utilisateur ne saurait plus laquelle est confirmee.
     */
    public function test_starting_a_new_draft_clears_the_previous_confirmation(): void
    {
        $first = $this->aiMessage('rag');
        $second = $this->aiMessage('llm', 'Une seconde reponse IA.');

        $this->actingAs($this->curator);

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $first->id)
            ->call('saveCapitalization')
            ->assertNotSet('capitalizeFlash', '')
            ->call('startCapitalization', $second->id)
            ->assertSet('capitalizeFlash', '');
    }

    /**
     * L'Article publie dit d'ou vient son texte.
     *
     * `blog_posts.ai_origin` ne doit pas etre une colonne que personne ne lit :
     * un lecteur qui ouvre l'Article doit pouvoir constater qu'il s'agit d'une
     * synthese IA relue par un humain. L'auteur, lui, reste l'humain — la
     * mention dit la PROVENANCE DU TEXTE, elle ne reattribue rien a l'IA.
     */
    public function test_the_published_article_states_where_its_text_comes_from(): void
    {
        $message = $this->aiMessage('rag', 'Reponse IA capitalisable.', 'Une question', [
            ['ref' => 'S1', 'title' => 'Manifeste.md', 'dossier_name' => 'D', 'excerpt' => 'x', 'url' => null],
        ]);

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $message->id)
            ->call('saveCapitalization')
            ->assertHasNoErrors();

        $post = BlogPost::query()->whereNotNull('ai_origin')->sole();

        $this->get(route('organization.blog.show', [
            'organization' => $this->organization->slug,
            'post' => $post->slug,
        ]))
            ->assertOk()
            ->assertSee(__('blog.ai_origin_notice', ['curator' => $this->curator->publicDisplayName()]))
            ->assertSee(__('blog.ai_origin_sources', ['count' => 1]))
            ->assertSee('data-ai-origin="rag"', false);
    }

    /**
     * Le pendant : un Article ecrit a la main ne porte AUCUNE mention d'IA.
     * Sans cette sentinelle, un affichage inconditionnel passerait le test
     * precedent tout en mentant sur tous les autres Articles.
     */
    public function test_a_hand_written_article_never_claims_an_ai_origin(): void
    {
        $post = BlogPost::create([
            'user_id' => $this->curator->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article ecrit a la main',
            'content' => 'Ecrit par un humain, de bout en bout.',
            'status' => 'published',
            'published_at' => now(),
            'audience' => BlogPost::AUDIENCE_LOOP,
            'listed_in_blog' => false,
        ]);
        $post->loops()->attach($this->loop->id);

        $this->assertNull($post->ai_origin);

        $this->actingAs($this->curator);
        $this->get(route('organization.blog.show', [
            'organization' => $this->organization->slug,
            'post' => $post->slug,
        ]))
            ->assertOk()
            ->assertDontSee(__('blog.ai_origin_notice', ['curator' => $this->curator->publicDisplayName()]))
            ->assertDontSee('data-ai-origin', false);
    }

    /**
     * La bulle ne doit JAMAIS laisser lire un marqueur de bloc Livewire.
     *
     * REGRESSION VECUE, visible a l'oeil nu sur le banc : au-dessus de
     * « Sources utilisees » s'affichait, en clair, `<!--[if BLOCK]><![endif]-->`.
     * Cause : un `@if` place entre la balise du composant et ses slots. Livewire
     * encadre tout bloc conditionnel de ces marqueurs ; a cet endroit ils
     * tombent dans le slot PAR DEFAUT, qui traverse `markdown()` — lequel les
     * echappe en texte au lieu de les laisser etre des commentaires HTML.
     *
     * La sentinelle vise la forme ECHAPPEE : c'est elle, et elle seule, que
     * l'utilisateur voit.
     */
    public function test_no_livewire_block_marker_ever_leaks_as_text_into_a_bubble(): void
    {
        $this->aiMessage('rag', 'Une reponse IA avec une action dessous.');

        $this->actingAs($this->curator);

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSee('&lt;!--[if BLOCK]&gt;', false)
            ->assertDontSee('&lt;![endif]--&gt;', false);
    }

    // =====================================================================
    // REVIEW FIX — provenance resoluble : [S1] doit vouloir dire quelque chose
    // =====================================================================

    /**
     * LE test du blocker de revue.
     *
     * Une reponse Dossiers cite ses appuis dans le TEXTE, sous forme `[S1]`,
     * `[S2]`. Ce texte devient un Article DURABLE. Si l'Article publie ne rend
     * pas la correspondance `[Sn] -> document`, le lecteur voit des references
     * qui ne renvoient a rien : la provenance est detruite au moment meme ou
     * elle devrait devenir permanente.
     *
     * `ai_origin.sources` conserve pourtant la correspondance. Le defaut est
     * donc entierement dans le RENDU.
     */
    public function test_a_published_article_lets_the_reader_resolve_the_citations_in_its_text(): void
    {
        $message = $this->aiMessage(
            'rag',
            'Les Boucles sont des espaces de memoire [S1], et le cadre du dialogue les encadre [S2].',
            'Que disent nos documents ?',
            [
                ['ref' => 'S1', 'title' => '02-ManifesteV2.md', 'dossier_name' => '01-COMMUNICATION', 'excerpt' => 'Une Boucle est un espace social.', 'url' => 'https://test.laravel/files/manifeste'],
                ['ref' => 'S2', 'title' => 'Cadre du dialogue', 'dossier_name' => '01-COMMUNICATION', 'excerpt' => 'Pourquoi cette Boucle existe.', 'url' => null],
            ],
        );

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $message->id)
            ->call('saveCapitalization')
            ->assertHasNoErrors();

        $post = BlogPost::query()->whereNotNull('ai_origin')->sole();

        $response = $this->get(route('organization.blog.show', [
            'organization' => $this->organization->slug,
            'post' => $post->slug,
        ]))->assertOk();

        // Le texte porte bien les references...
        $response->assertSee('[S1]')->assertSee('[S2]');

        // ...et le lecteur doit pouvoir les RESOUDRE.
        $response
            ->assertSee('02-ManifesteV2.md')
            ->assertSee('Cadre du dialogue');
    }

    /**
     * Le Dossier d'origine est rendu quand il est connu, et le lien « Ouvrir »
     * n'apparait QUE si une URL existe. Aucun lien n'est invente : une source
     * sans URL reste une source, lisible, mais non cliquable.
     */
    public function test_the_dossier_name_is_shown_and_a_link_appears_only_when_a_url_exists(): void
    {
        $post = $this->capitalizedArticleWithSources([
            ['ref' => 'S1', 'title' => 'Avec URL.md', 'dossier_name' => 'DOSSIER-VISIBLE', 'excerpt' => 'x', 'url' => 'https://test.laravel/files/avec-url'],
            ['ref' => 'S2', 'title' => 'Sans URL.md', 'dossier_name' => null, 'excerpt' => 'x', 'url' => null],
        ]);

        $html = $this->articleHtml($post);

        $this->assertStringContainsString('DOSSIER-VISIBLE', $html);
        $this->assertStringContainsString('https://test.laravel/files/avec-url', $html);

        // Une seule source cliquable sur les deux. On compte le MARQUEUR du
        // lien, jamais son libelle : « Ouvrir » apparait ailleurs sur la page,
        // et compter des mots ferait passer un test pour la mauvaise raison.
        $this->assertSame(2, substr_count($html, 'data-ai-origin-source>'));
        $this->assertSame(1, substr_count($html, 'data-ai-origin-open'));
    }

    /**
     * Une URL a schema non navigable, meme persistee, ne devient JAMAIS un
     * lien. La colonne est ecrite cote serveur aujourd'hui — cette garde ne
     * depend pas de cette hypothese pour tenir demain.
     */
    public function test_a_non_navigable_url_never_becomes_a_clickable_link(): void
    {
        $post = $this->capitalizedArticleWithSources([
            ['ref' => 'S1', 'title' => 'Source hostile.md', 'dossier_name' => 'D', 'excerpt' => 'x', 'url' => 'javascript:alert(1)'],
        ]);

        $html = $this->articleHtml($post);

        $this->assertStringContainsString('Source hostile.md', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('data-ai-origin-open', $html);
    }

    /**
     * Un document seulement CONSULTE n'a etaye aucune affirmation. Il n'a donc
     * rien a faire sous un texte durable : l'y afficher en ferait
     * retroactivement un appui.
     */
    public function test_a_merely_consulted_document_is_never_rendered_on_the_article(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Une reponse qui ne cite rien.',
            'type' => 'ai',
            'metadata' => [
                'ai_mode' => 'rag',
                'consulted' => [
                    ['ref' => 'S1', 'title' => 'DOCUMENT-SEULEMENT-CONSULTE.md', 'dossier_name' => 'D', 'excerpt' => 'x', 'url' => null],
                ],
            ],
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $message->id)
            ->call('saveCapitalization')
            ->assertHasNoErrors();

        $post = BlogPost::query()->whereNotNull('ai_origin')->sole();
        $html = $this->articleHtml($post);

        $this->assertStringNotContainsString('DOCUMENT-SEULEMENT-CONSULTE', $html);
        $this->assertStringNotContainsString(__('blog.ai_origin_sources_title'), $html);
    }

    /**
     * Une synthese IA sans aucune source citee affiche la mention de
     * provenance, mais AUCUN bloc de sources vide : un titre « Sources
     * documentaires » suivi de rien serait une promesse non tenue.
     */
    public function test_an_ai_synthesis_without_any_cited_source_shows_no_empty_block(): void
    {
        $post = $this->capitalizedArticleWithSources(null);

        $html = $this->articleHtml($post);

        // Le nom du curateur est genere par Faker et peut contenir une
        // apostrophe — « Osborne O'Connell » a fait rougir cette assertion en
        // CI, le HTML rendant `O&#039;Connell`. Les deux autres assertions du
        // fichier passent par `assertSee()`, qui echappe par defaut ; celle-ci
        // comparait la chaine brute. Un faux rouge intermittent, dependant du
        // seed, qui n'apprenait rien sur le code.
        $this->assertStringContainsString(
            e(__('blog.ai_origin_notice', ['curator' => $this->curator->publicDisplayName()])),
            $html,
        );
        $this->assertStringNotContainsString(__('blog.ai_origin_sources_title'), $html);
        $this->assertStringNotContainsString('data-ai-origin-source', $html);
    }

    /**
     * `title` et `excerpt` viennent de documents uploades : du contenu
     * utilisateur. Ils sont rendus ECHAPPES, sur une page durable et
     * publiquement lisible.
     */
    public function test_hostile_html_in_a_source_title_stays_escaped(): void
    {
        $post = $this->capitalizedArticleWithSources([
            ['ref' => 'S1', 'title' => '<script>alert("xss")</script>', 'dossier_name' => '"><img src=x onerror=alert(1)>', 'excerpt' => 'x', 'url' => null],
        ]);

        $html = $this->articleHtml($post);

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('onerror=alert(1)&gt;', $html);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Capitalise une reponse IA portant CES sources citees, et rend l'Article.
     *
     * @param list<array<string, mixed>>|null $sources
     */
    private function capitalizedArticleWithSources(?array $sources): BlogPost
    {
        $message = $this->aiMessage(
            'rag',
            'Un texte qui cite ses appuis [S1] et [S2].',
            'Une question',
            $sources,
        );

        $this->actingAs($this->curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $message->id)
            ->call('saveCapitalization')
            ->assertHasNoErrors();

        return BlogPost::query()->whereNotNull('ai_origin')->sole();
    }

    private function articleHtml(BlogPost $post): string
    {
        return $this->actingAs($this->curator)
            ->get(route('organization.blog.show', [
                'organization' => $this->organization->slug,
                'post' => $post->slug,
            ]))
            ->assertOk()
            ->getContent();
    }

    /** @param list<array<string, mixed>>|null $sources */
    private function aiMessage(string $aiMode, string $body = 'Reponse IA capitalisable.', ?string $question = null, ?array $sources = null, ?string $interactionId = null): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => $body,
            'type' => 'ai',
            'metadata' => array_filter([
                'ai_mode' => $aiMode,
                'question' => $question,
                'sources' => $sources,
                'ai_interaction_id' => $interactionId,
            ], static fn ($v): bool => $v !== null),
            'organization_id' => $this->loop->organization_id,
        ]);
    }
}
