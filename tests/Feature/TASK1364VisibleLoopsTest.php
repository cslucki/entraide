<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\AiSelfKnowledge;
use App\Support\Loops\VisibleLoops;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1364 — le Shell nomme les Boucles que le catalogue montre deja.
 *
 * ## Le defaut
 *
 * « Quels boucles sont dispo ? » recevait « je ne peux pas fournir
 * d'informations sur les Boucles disponibles, consultez la plateforme ». Le
 * Shell renvoyait quelqu'un chercher ailleurs une donnee que la page `/loops`
 * affiche a deux clics.
 *
 * ## L'invariant qui gouverne ce fichier
 *
 * `VisibleLoops` est l'UNIQUE autorite, et le catalogue la partage. Aucune
 * regle de visibilite n'est ecrite dans le Shell, donc aucun test ici ne
 * verifie une garde du Shell : ils verifient que le Shell dit LA MEME CHOSE
 * que le catalogue. Une divergence entre les deux est le seul echec possible.
 *
 * Nommer une Boucle n'accorde aucun droit d'entree. L'etat d'acces vient des
 * Policies, jamais de `access_mode` lu au passage.
 */
class TASK1364VisibleLoopsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-visible-loops',
            'loop_mode' => 'multi',
        ]);

        $this->member = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. La question est reconnue, et elle ne vole rien aux autres
    // =====================================================================

    /** 1. Les formulations canoniques FR sont reconnues — celle de Cyril comprise. */
    public function test_the_french_formulations_are_recognised(): void
    {
        $knowledge = app(AiSelfKnowledge::class);

        foreach ([
            'Quels boucles sont dispo ?',
            'Quelles Boucles sont disponibles ?',
            'quelles sont mes boucles',
            'Mes boucles',
        ] as $prompt) {
            $this->assertSame(
                AiSelfKnowledge::TOPIC_VISIBLE_LOOPS,
                $knowledge->topicFor($prompt),
                "Formulation non reconnue : {$prompt}",
            );
        }
    }

    /** 2. Les formulations canoniques EN aussi. */
    public function test_the_english_formulations_are_recognised(): void
    {
        $knowledge = app(AiSelfKnowledge::class);

        foreach (['What Loops are available?', 'what are my loops', 'My loops'] as $prompt) {
            $this->assertSame(AiSelfKnowledge::TOPIC_VISIBLE_LOOPS, $knowledge->topicFor($prompt), $prompt);
        }
    }

    /**
     * 3. La DEFINITION reste la definition.
     *
     * `TOPIC_LOOP` porte deja « c'est quoi les boucles ». Demander ce QU'EST
     * une Boucle et demander LESQUELLES existent sont deux questions, et la
     * seconde ne doit pas voler la premiere.
     */
    public function test_the_definition_question_is_not_stolen(): void
    {
        $knowledge = app(AiSelfKnowledge::class);

        $this->assertSame(AiSelfKnowledge::TOPIC_LOOP, $knowledge->topicFor("c'est quoi les boucles"));
        $this->assertSame(AiSelfKnowledge::TOPIC_LOOP, $knowledge->topicFor('what are loops'));
    }

    /**
     * 4. L'entree « je viens d'arriver » de T1361 etait INATTEIGNABLE.
     *
     * Elle etait ecrite « d arriver », avec une espace, alors que
     * `normalize()` CONSERVE l'apostrophe. Corrigee ici, et verrouillee.
     */
    public function test_the_dead_get_started_formulation_is_now_reachable(): void
    {
        $this->assertSame(
            AiSelfKnowledge::TOPIC_GET_STARTED,
            app(AiSelfKnowledge::class)->topicFor("Je viens d'arriver"),
        );
    }

    // =====================================================================
    // B. Le Shell dit ce que le catalogue dit
    // =====================================================================

    /** 5. Les Boucles dont je suis membre et les autres sont separees. */
    public function test_my_loops_and_the_others_are_separated(): void
    {
        $mine = $this->loop('Ma Boucle');
        $this->join($mine, $this->member);
        $other = $this->loop('Une Autre Boucle');

        $answer = $this->answer();

        $this->assertStringContainsString(__('ai.self_knowledge_visible_loops_mine'), $answer);
        $this->assertStringContainsString('Ma Boucle', $answer);
        $this->assertStringContainsString(__('ai.self_knowledge_visible_loops_others'), $answer);
        $this->assertStringContainsString('Une Autre Boucle', $answer);

        // La separation est reelle : « Ma Boucle » est AVANT l'intitule des
        // autres, donc dans la premiere liste.
        $this->assertLessThan(
            strpos($answer, __('ai.self_knowledge_visible_loops_others')),
            strpos($answer, 'Ma Boucle'),
        );
        $this->assertSame(0, $other->members()->count());
    }

    /**
     * 6. Une Boucle PRIVEE de mon Organization est nommee.
     *
     * TASK-1075 : « privee » ne veut pas dire « cachee », mais « contenu
     * reserve aux membres ». Le catalogue la montre deja ; la taire ici
     * rendrait le Shell moins fiable que la page.
     */
    public function test_a_private_loop_of_my_organization_is_named(): void
    {
        $private = $this->loop('Boucle Confidentielle');
        $private->forceFill(['visibility' => 'private'])->save();

        $this->assertStringContainsString('Boucle Confidentielle', $this->answer());
    }

    /** 7. Une Boucle d'une AUTRE Organization n'est jamais nommee. */
    public function test_a_loop_of_another_organization_is_never_named(): void
    {
        $foreign = Organization::factory()->create(['is_active' => true, 'slug' => 'org-etrangere']);
        Loop::factory()->create([
            'organization_id' => $foreign->id,
            'name' => 'Boucle Etrangere',
            'status' => 'active',
        ]);

        $this->assertStringNotContainsString('Boucle Etrangere', $this->answer());
    }

    /**
     * 8. Une Boucle ARCHIVEE n'est jamais nommee.
     *
     * Le catalogue en fait une seconde liste, filtree par la permission
     * `loops.archive`. Elle est hors de cette V1 — y compris pour un membre
     * de cette Boucle, qui pourrait croire qu'elle est encore vivante.
     */
    public function test_an_archived_loop_is_never_named(): void
    {
        $archived = $this->loop('Boucle Archivee');
        $this->join($archived, $this->member);
        $archived->forceFill(['status' => 'archived'])->save();

        $this->assertStringNotContainsString('Boucle Archivee', $this->answer());
    }

    /**
     * 9. L'etat vide est HONNETE.
     *
     * Il ne dit pas combien de Boucles existent ailleurs, ni qu'il en existe :
     * ce serait reveler l'existence de ce qu'on refuse de nommer.
     */
    public function test_the_empty_state_reveals_no_count(): void
    {
        $foreign = Organization::factory()->create(['is_active' => true, 'slug' => 'org-vide-etrangere']);
        Loop::factory()->count(12)->create(['organization_id' => $foreign->id, 'status' => 'active']);

        $answer = $this->answer();

        $this->assertSame(__('ai.self_knowledge_visible_loops_empty'), $answer);
        $this->assertStringNotContainsString('12', $answer);
    }

    // =====================================================================
    // C. L'etat d'acces vient des Policies
    // =====================================================================

    /** 10. Les quatre etats d'acces sont ceux que les Policies rendent. */
    public function test_each_access_state_comes_from_the_policies(): void
    {
        $open = $this->loop('Boucle Ouverte', 'open');
        $request = $this->loop('Boucle Sur Demande', 'request');
        $invitation = $this->loop('Boucle Sur Invitation', 'invitation');

        $visible = app(VisibleLoops::class);

        // L'autorite d'abord : c'est la Policy qui parle, pas le champ.
        $this->assertTrue($this->member->can('join', $open));
        $this->assertFalse($this->member->can('join', $request));
        $this->assertTrue($this->member->can('requestToJoin', $request));
        $this->assertFalse($this->member->can('requestToJoin', $invitation));

        $this->assertSame(VisibleLoops::ACCESS_OPEN, $visible->accessStateFor($open, $this->member));
        $this->assertSame(VisibleLoops::ACCESS_REQUEST, $visible->accessStateFor($request, $this->member));
        $this->assertSame(VisibleLoops::ACCESS_INVITATION, $visible->accessStateFor($invitation, $this->member));

        $answer = $this->answer();

        foreach ([
            'Boucle Ouverte' => 'ai.self_knowledge_visible_loops_access_open',
            'Boucle Sur Demande' => 'ai.self_knowledge_visible_loops_access_request',
            'Boucle Sur Invitation' => 'ai.self_knowledge_visible_loops_access_invitation',
        ] as $name => $key) {
            $this->assertStringContainsString($name.' ('.__($key).')', $answer);
        }
    }

    /**
     * 11. Une demande DEJA en attente n'invite pas a redemander.
     *
     * Proposer « sur demande » a quelqu'un qui attend deja une reponse est une
     * affirmation fausse sur ce qu'il peut faire.
     */
    public function test_a_pending_request_is_not_an_invitation_to_ask_again(): void
    {
        $loop = $this->loop('Boucle Deja Demandee', 'request');

        LoopJoinRequest::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $loop->id,
            'user_id' => $this->member->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);

        $this->assertSame(
            VisibleLoops::ACCESS_PENDING,
            app(VisibleLoops::class)->accessStateFor($loop->fresh(), $this->member),
        );

        $answer = $this->answer();

        $this->assertStringContainsString(
            'Boucle Deja Demandee ('.__('ai.self_knowledge_visible_loops_access_pending').')',
            $answer,
        );
        $this->assertStringNotContainsString(
            'Boucle Deja Demandee ('.__('ai.self_knowledge_visible_loops_access_request').')',
            $answer,
        );
    }

    // =====================================================================
    // D. Le contrat de la surface
    // =====================================================================

    /**
     * 12. En mono-loop, la liste des AUTRES Boucles est vide.
     *
     * `LoopController::index()` y redirige vers la Boucle primaire au lieu de
     * lister : il n'existe aucune surface ou cette personne verrait ce
     * catalogue. Restriction, jamais elargissement.
     */
    public function test_a_mono_loop_organization_lists_no_other_loops(): void
    {
        $mine = $this->loop('Ma Seule Boucle');
        $this->join($mine, $this->member);
        $this->loop('Boucle Hors Catalogue');

        $this->organization->forceFill(['loop_mode' => 'mono'])->save();
        app()->instance('current_organization', $this->organization->fresh());

        $answer = $this->answer($this->organization->fresh());

        $this->assertStringContainsString('Ma Seule Boucle', $answer);
        $this->assertStringNotContainsString('Boucle Hors Catalogue', $answer);
        $this->assertStringNotContainsString(__('ai.self_knowledge_visible_loops_others'), $answer);
    }

    /** 13. La reponse suit la langue de l'interface, et ne fuit pas l'autre. */
    public function test_the_answer_follows_the_interface_language(): void
    {
        $this->join($this->loop('Ma Boucle'), $this->member);

        app()->setLocale('en');
        $english = $this->answer();

        app()->setLocale('fr');
        $french = $this->answer();

        $this->assertStringContainsString('Your Loops:', $english);
        $this->assertStringNotContainsString('Vos Boucles', $english);
        $this->assertStringContainsString('Vos Boucles', $french);
    }

    /**
     * 14. Zero provider, zero credit, et aucune URL ni identifiant.
     *
     * Le catalogue de capacites n'a jamais porte d'URL, et cette reponse suit
     * la meme regle : chaque page rejoue sa garde au clic, ce n'est pas au
     * Shell de fabriquer une destination.
     */
    public function test_the_answer_costs_nothing_and_leaks_no_identifier(): void
    {
        $loop = $this->loop('Ma Boucle');
        $this->join($loop, $this->member);

        $answer = $this->answer();

        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertStringNotContainsString('http', $answer);
        $this->assertStringNotContainsString((string) $loop->id, $answer);
    }

    /**
     * 15. Le catalogue et le Shell lisent la MEME chose.
     *
     * C'est le test qui garde l'invariant de la TASK : si quelqu'un rajoute un
     * jour un filtre d'un seul cote, cette assertion tombe.
     */
    public function test_the_catalogue_and_the_shell_read_the_same_set(): void
    {
        $this->join($this->loop('Ma Boucle'), $this->member);
        $this->loop('Boucle Ouverte', 'open');
        $this->loop('Boucle Privee')->forceFill(['visibility' => 'private'])->save();

        $fromAuthority = app(VisibleLoops::class)
            ->query((string) $this->organization->id, $this->member)
            ->pluck('name')->sort()->values()->all();

        $grouped = app(VisibleLoops::class)->groupedFor($this->organization, $this->member);

        $fromShell = $grouped['member']->merge($grouped['other'])
            ->pluck('name')->sort()->values()->all();

        $this->assertSame($fromAuthority, $fromShell);
        $this->assertNotEmpty($fromAuthority);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function loop(string $name, string $accessMode = 'request'): Loop
    {
        return Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'status' => 'active',
            'access_mode' => $accessMode,
        ]);
    }

    private function join(Loop $loop, User $user): void
    {
        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'status' => 'active',
            'role' => 'member',
            'joined_at' => now(),
        ]);
    }

    private function answer(?Organization $organization = null): string
    {
        $knowledge = app(AiSelfKnowledge::class);

        return $knowledge->answer(
            AiSelfKnowledge::TOPIC_VISIBLE_LOOPS,
            $organization ?? $this->organization,
            $this->member,
        );
    }
}
