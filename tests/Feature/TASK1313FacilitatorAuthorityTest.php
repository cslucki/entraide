<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Livewire\LoopChat;
use App\Livewire\LoopMembersCard;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopPermissionSettingsService;
use App\Services\Loops\LoopAnswerCapitalizationService;
use App\Services\LoopService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1313 — l'autorite d'un ANIMATEUR de Boucle.
 *
 * ## La doctrine
 *
 * Organization = Tenant. Loop != Tenant. Un animateur anime SA Boucle ; il ne
 * devient pas Admin Organization. Toute la question est de lui donner ce qu'il
 * faut pour animer, et rien de plus.
 *
 * ## Ce qui bloquait, mesure avant d'ecrire une ligne
 *
 * Deux autorites INDEPENDANTES refusaient l'animateur :
 *
 * 1. `dossiers.create_article` n'etait accorde qu'a l'owner ;
 * 2. `DossierPolicy::attachArticle()` deleguait, pour un Dossier de Boucle, a
 *    `LoopPolicy::update()` — laquelle exige strictement `role = 'owner'`.
 *
 * Elargir `LoopPolicy::update()` aurait donne a l'animateur l'identite de la
 * Boucle, l'archivage et l'invitation d'adresses EXTERIEURES avec. C'est
 * pourquoi ce sont les permissions PRECISES qui sont demandees au resolveur
 * canonique, et non l'ability large.
 *
 * ## Ce que l'animateur ne gagne PAS
 *
 * L'invitation d'une adresse e-mail exterieure reste gouvernee par
 * `can('update', $loop)` — une ability distincte, volontairement non elargie.
 * Un animateur compose son equipe DANS le tenant ; il ne fait entrer personne
 * DANS le tenant.
 */
#[Group('sensitive')]
class TASK1313FacilitatorAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $facilitator;

    private User $member;

    private User $orgMemberOutsideLoop;

    private User $stranger;

    private Loop $loop;

    private Loop $otherLoop;

    private Dossier $rootDossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'LaunchPals', 'slug' => 'launchpals']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Autre Org', 'slug' => 'autre-org']);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-tenant',
        ]);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->facilitator = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->orgMemberOutsideLoop = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        app()->instance('current_organization', $this->organization);
        $loops = new LoopService;

        $this->loop = $loops->createLoop($this->owner, 'Boucle animee');
        $loops->addMember($this->loop, $this->facilitator, 'facilitator');
        $loops->addMember($this->loop, $this->member, 'member');

        $this->otherLoop = $loops->createLoop($this->owner, 'Autre Boucle');

        $this->rootDossier = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.chatloop.enabled' => true,
        ]);

        Http::preventStrayRequests();
        LoopKnowledgeAgent::fake(['Reponse.']);
    }

    // =====================================================================
    // A. LA MATRICE — ce que chaque role peut, lu au resolveur canonique
    // =====================================================================

    /**
     * Les deux permissions ouvertes par cette TASK, role par role.
     *
     * Elles sont lues au RESOLVEUR, pas dans le tableau de config : c'est le
     * resolveur qui fait autorite, et lui seul applique tenant, adhesion,
     * archivage et overrides d'Organization.
     */
    public function test_the_canonical_resolver_grants_the_two_new_authorities_to_facilitators_only(): void
    {
        $resolver = app(LoopPermissionResolver::class);

        foreach (['dossiers.create_article', 'loop_members.add'] as $permission) {
            $this->assertTrue($resolver->can($this->owner, $this->loop, $permission), "owner / $permission");
            $this->assertTrue($resolver->can($this->facilitator, $this->loop, $permission), "facilitator / $permission");
            $this->assertFalse($resolver->can($this->member, $this->loop, $permission), "member / $permission");
            $this->assertFalse($resolver->can($this->stranger, $this->loop, $permission), "stranger / $permission");
        }
    }

    /**
     * L'animateur ne devient PAS owner. Ce test est la contrepartie du
     * precedent : ouvrir deux permissions precises ne doit rien ouvrir d'autre.
     */
    public function test_a_facilitator_gains_nothing_that_belongs_to_the_owner(): void
    {
        $resolver = app(LoopPermissionResolver::class);

        foreach ([
            'loops.update_identity',
            'loops.change_type',
            'loops.archive',
            'loops.manage_owners',
            'loops.manage_facilitators',
            'loop_members.remove',
            'loop_members.change_role',
            'dossiers.upload_file',
        ] as $ownerOnly) {
            $this->assertTrue($resolver->can($this->owner, $this->loop, $ownerOnly), "owner / $ownerOnly");
            $this->assertFalse($resolver->can($this->facilitator, $this->loop, $ownerOnly), "facilitator / $ownerOnly");
        }
    }

    /**
     * L'ability large `update` n'a PAS bouge — c'est elle qui gouverne
     * l'identite de la Boucle et l'invitation d'une adresse exterieure.
     */
    public function test_the_broad_update_ability_still_belongs_to_the_owner_alone(): void
    {
        $this->assertTrue($this->owner->can('update', $this->loop));
        $this->assertFalse($this->facilitator->can('update', $this->loop));
        $this->assertFalse($this->member->can('update', $this->loop));
    }

    // =====================================================================
    // B. CAPITALISATION — visible par tous, active pour qui a le droit
    // =====================================================================

    public function test_the_owner_sees_an_active_button(): void
    {
        $message = $this->aiMessage();

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('data-capitalize-allowed="1"', false)
            ->assertSeeHtml('data-capitalize-open="'.$message->id.'"')
            ->assertDontSee('data-capitalize-hint', false);
    }

    public function test_the_facilitator_sees_an_active_button(): void
    {
        $message = $this->aiMessage();

        $this->actingAs($this->facilitator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('data-capitalize-allowed="1"', false)
            ->assertSeeHtml('data-capitalize-open="'.$message->id.'"')
            ->assertDontSee('data-capitalize-hint', false);
    }

    /**
     * LE test de la regle produit : le membre ordinaire VOIT l'action, elle est
     * desactivee, et il sait pourquoi.
     *
     * La masquer reviendrait a ce qu'il ne puisse pas meme savoir qu'elle
     * existe : un refus explique informe, une absence laisse croire que rien
     * n'est possible.
     */
    public function test_an_ordinary_member_sees_the_action_disabled_and_explained(): void
    {
        $message = $this->aiMessage();

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-capitalize-open="'.$message->id.'"')
            ->assertSee('data-capitalize-allowed="0"', false)
            ->assertSee('data-capitalize-hint', false)
            ->assertSee(__('loops.capitalize_reserved_to_facilitators'))
            // Et surtout : aucun `wire:click` a declencher.
            ->assertDontSee('wire:click="startCapitalization', false);
    }

    public function test_someone_outside_the_loop_gets_no_action_at_all(): void
    {
        $this->aiMessage();

        $this->actingAs($this->orgMemberOutsideLoop);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSee('data-capitalize-open', false)
            ->assertDontSee('data-capitalize-hint', false);
    }

    // =====================================================================
    // C. CAPITALISATION — le serveur, seule autorite
    // =====================================================================

    public function test_the_owner_capitalizes(): void
    {
        $post = $this->capitalizeAs($this->owner);

        $this->assertSame($this->owner->id, $post->user_id);
    }

    /**
     * LE test central de la TASK : l'animateur capitalise reellement.
     */
    public function test_the_facilitator_capitalizes_and_signs_the_article(): void
    {
        $post = $this->capitalizeAs($this->facilitator);

        // L'invariant T1310 tient : l'auteur est l'HUMAIN qui valide.
        $this->assertSame($this->facilitator->id, $post->user_id);
        $this->assertSame(
            LoopAnswerCapitalizationService::ORIGIN_AI_SYNTHESIS,
            $post->ai_origin['origin_type'],
        );
        $this->assertSame((string) $this->facilitator->id, $post->ai_origin['human_curator_id']);
    }

    /**
     * L'UI n'est jamais la barriere : un membre ordinaire qui FORGE l'appel au
     * service est refuse cote serveur, et aucun Article n'existe.
     */
    public function test_a_forged_call_from_an_ordinary_member_is_refused_server_side(): void
    {
        $message = $this->aiMessage();

        $this->expectException(RuntimeException::class);

        try {
            app(LoopAnswerCapitalizationService::class)->capitalize(
                $this->loop,
                $this->member,
                $message,
                (string) $this->rootDossier->id,
                'Titre force',
                'Contenu force',
            );
        } finally {
            $this->assertSame(0, BlogPost::query()->whereNotNull('ai_origin')->count());
        }
    }

    public function test_a_facilitator_of_another_loop_cannot_capitalize_here(): void
    {
        (new LoopService)->addMember($this->otherLoop, $this->member, 'facilitator');
        $message = $this->aiMessage();

        $this->expectException(RuntimeException::class);

        try {
            app(LoopAnswerCapitalizationService::class)->capitalize(
                $this->loop,
                $this->member,
                $message,
                (string) $this->rootDossier->id,
                'Titre',
                'Contenu',
            );
        } finally {
            $this->assertSame(0, BlogPost::query()->whereNotNull('ai_origin')->count());
        }
    }

    public function test_a_stranger_from_another_organization_is_refused(): void
    {
        $message = $this->aiMessage();

        $this->expectException(RuntimeException::class);

        try {
            app(LoopAnswerCapitalizationService::class)->capitalize(
                $this->loop,
                $this->stranger,
                $message,
                (string) $this->rootDossier->id,
                'Titre',
                'Contenu',
            );
        } finally {
            $this->assertSame(0, BlogPost::query()->whereNotNull('ai_origin')->count());
        }
    }

    /**
     * Le geste ne coute RIEN au provider : l'ouverture aux animateurs multiplie
     * le nombre de personnes qui peuvent capitaliser, jamais la depense.
     */
    #[Group('ai')]
    public function test_opening_the_gesture_to_facilitators_costs_no_provider_call(): void
    {
        $this->capitalizeAs($this->facilitator);

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // D. GESTION DES MEMBRES
    // =====================================================================

    /**
     * L'animateur fait entrer dans SA Boucle quelqu'un qui est DEJA membre de
     * son Organization.
     */
    public function test_a_facilitator_adds_an_existing_organization_member(): void
    {
        $this->actingAs($this->facilitator);

        Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
            ->set('selected', [$this->orgMemberOutsideLoop->id])
            ->call('add');

        $this->assertSame(1, $this->activeMemberships($this->orgMemberOutsideLoop));
    }

    /**
     * Une seule adhesion, jamais deux : rejouer l'ajout ne duplique rien.
     */
    public function test_adding_the_same_person_twice_creates_a_single_membership(): void
    {
        $this->actingAs($this->facilitator);

        foreach ([1, 2] as $ignored) {
            Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
                ->set('selected', [$this->orgMemberOutsideLoop->id])
                ->call('add');
        }

        $this->assertSame(1, $this->activeMemberships($this->orgMemberOutsideLoop));
    }

    public function test_an_ordinary_member_cannot_add_anyone(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
            ->set('selected', [$this->orgMemberOutsideLoop->id])
            ->call('add')
            ->assertForbidden();

        $this->assertSame(0, $this->activeMemberships($this->orgMemberOutsideLoop));
    }

    /**
     * Un `user_id` forge d'une AUTRE Organization n'entre jamais dans la
     * Boucle — et il est arrete DEUX FOIS.
     *
     * REVELE PAR MUTATION : en supprimant le re-cadrage serveur de la Card
     * (`invitableOrganizationMembers()->whereIn(...)`), ce test PASSAIT
     * toujours. La garantie ne venait donc pas de la couche que son nom
     * suggerait. `LoopService::addMemberByUserId()` porte un refus explicite de
     * l'inter-Organization, et c'est lui qui tenait.
     *
     * Deux gardes independantes valent mieux qu'une — mais il fallait savoir
     * laquelle repond, sous peine de croire protegee une couche qui ne l'est
     * pas. Les deux sont donc assertees separement.
     */
    public function test_a_forged_user_id_from_another_organization_never_enters_the_loop(): void
    {
        $this->actingAs($this->facilitator);

        Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
            ->set('selected', [$this->stranger->id])
            ->call('add');

        $this->assertSame(0, $this->activeMemberships($this->stranger));
        $this->assertSame(
            $this->otherOrganization->id,
            $this->stranger->fresh()->organization_id,
            'aucun rattachement de tenant ne doit avoir bouge',
        );

        // Couche 1 — le re-cadrage : l'etranger n'est meme pas un candidat.
        $this->assertFalse(
            (new LoopService)->invitableOrganizationMembers($this->loop)
                ->contains(fn (User $u): bool => $u->id === $this->stranger->id),
            'un membre d\'une autre Organization ne doit jamais etre propose',
        );

        // Couche 2 — le service, qui refuse meme si on l'appelle directement.
        $this->expectException(RuntimeException::class);
        (new LoopService)->addMemberByUserId($this->loop, (string) $this->stranger->id);
    }

    /**
     * Animer une Boucle ne donne aucune autorite sur une AUTRE Boucle, meme
     * dans la meme Organization.
     */
    public function test_a_facilitator_of_one_loop_gains_nothing_on_another(): void
    {
        $this->assertFalse(
            app(LoopPermissionResolver::class)->can($this->facilitator, $this->otherLoop, 'loop_members.add'),
        );

        $this->actingAs($this->facilitator);

        Livewire::test(LoopMembersCard::class, ['loop' => $this->otherLoop])
            ->set('selected', [$this->orgMemberOutsideLoop->id])
            ->call('add')
            ->assertForbidden();

        $this->assertSame(0, LoopMember::query()
            ->where('loop_id', $this->otherLoop->id)
            ->where('user_id', $this->orgMemberOutsideLoop->id)
            ->where('status', 'active')
            ->count());
    }

    /**
     * Ajouter quelqu'un a une Boucle ne touche a AUCUN role d'Organization.
     * L'animateur compose une equipe, il n'administre pas le tenant.
     */
    public function test_adding_a_member_changes_no_organization_role(): void
    {
        $before = User::query()->orderBy('id')->pluck('organization_id', 'id')->all();
        $adminBefore = $this->organization->fresh()->admin_id;

        $this->actingAs($this->facilitator);
        Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
            ->set('selected', [$this->orgMemberOutsideLoop->id])
            ->call('add');

        $this->assertSame($before, User::query()->orderBy('id')->pluck('organization_id', 'id')->all());
        $this->assertSame($adminBefore, $this->organization->fresh()->admin_id);
    }

    /**
     * Aucune invitation exterieure n'est creee au passage — et l'animateur n'a
     * toujours pas le droit d'en emettre : ce chemin reste gouverne par
     * `can('update', $loop)`, ability distincte, volontairement non elargie.
     */
    public function test_no_external_invitation_is_ever_created_and_the_facilitator_still_cannot_send_one(): void
    {
        $this->actingAs($this->facilitator);
        Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
            ->set('selected', [$this->orgMemberOutsideLoop->id])
            ->call('add');

        $this->assertSame(0, LoopInvitation::query()->count());

        // L'autorite de l'invitation externe n'a pas bouge.
        $this->assertFalse($this->facilitator->can('update', $this->loop));
        $this->assertTrue($this->owner->can('update', $this->loop));
    }

    /**
     * La Card ne propose ses gestes qu'a qui peut les faire — pendant que le
     * serveur, lui, refuse de toute facon.
     */
    public function test_the_members_card_offers_management_to_the_facilitator_and_not_to_the_member(): void
    {
        $this->actingAs($this->facilitator);
        $this->assertTrue(Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])->instance()->canManage());

        $this->actingAs($this->member);
        $this->assertFalse(Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])->instance()->canManage());
    }

    // =====================================================================
    // E. LES DEUX GESTES SONT REELLEMENT SEPARES (review fix)
    // =====================================================================

    /**
     * Repondre a une demande d'adhesion et ajouter quelqu'un de sa propre
     * initiative ne sont pas le meme acte : l'un repond a une sollicitation,
     * l'autre en prend l'initiative. Deux permissions, deux abilities.
     */
    public function test_the_two_gestures_answer_to_two_distinct_permissions(): void
    {
        // Le facilitator peut les deux — c'est la regle produit.
        $this->assertTrue($this->facilitator->can('addMembers', $this->loop));
        $this->assertTrue($this->facilitator->can('manageJoinRequests', $this->loop));

        $this->assertFalse($this->member->can('addMembers', $this->loop));
        $this->assertFalse($this->member->can('manageJoinRequests', $this->loop));
    }

    public function test_a_facilitator_accepts_a_join_request(): void
    {
        $request = $this->pendingJoinRequest();

        $this->actingAs($this->facilitator)
            ->post(route('loop-join-requests.accept', $request))
            ->assertRedirect();

        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $request->fresh()->status);
        $this->assertSame(1, $this->activeMemberships($this->orgMemberOutsideLoop));
    }

    public function test_a_facilitator_rejects_a_join_request(): void
    {
        $request = $this->pendingJoinRequest();

        $this->actingAs($this->facilitator)
            ->post(route('loop-join-requests.reject', $request))
            ->assertRedirect();

        $this->assertSame(LoopJoinRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame(0, $this->activeMemberships($this->orgMemberOutsideLoop));
    }

    public function test_an_ordinary_member_can_neither_accept_nor_reject(): void
    {
        foreach (['loop-join-requests.accept', 'loop-join-requests.reject'] as $route) {
            $request = $this->pendingJoinRequest();

            $this->actingAs($this->member)
                ->post(route($route, $request))
                ->assertForbidden();

            $this->assertSame(LoopJoinRequest::STATUS_PENDING, $request->fresh()->status);
            $request->delete();
        }

        $this->assertSame(0, $this->activeMemberships($this->orgMemberOutsideLoop));
    }

    /**
     * Frontiere de tenant : une demande d'adhesion d'une AUTRE Organization
     * n'est pas decidable ici, meme par un facilitator legitime chez lui.
     */
    public function test_a_join_request_of_another_organization_is_never_decidable_here(): void
    {
        $foreignLoop = LoopMember::query()->where('user_id', $this->stranger->id)->first();
        $this->assertNull($foreignLoop, 'l\'etranger ne doit appartenir a aucune Boucle de ce jeu');

        $request = LoopJoinRequest::create([
            'organization_id' => $this->otherOrganization->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->stranger->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);

        $this->actingAs($this->facilitator)
            ->post(route('loop-join-requests.accept', $request))
            ->assertNotFound();

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_a_facilitator_of_one_loop_decides_nothing_in_another(): void
    {
        $this->assertFalse(
            app(LoopPermissionResolver::class)
                ->can($this->facilitator, $this->otherLoop, 'loop_members.review_join_requests'),
        );
        $this->assertFalse($this->facilitator->can('manageJoinRequests', $this->otherLoop));
    }

    // ---------------------------------------------------------------------
    // LA preuve que la separation est reelle : les overrides d'Organization
    // ---------------------------------------------------------------------

    /**
     * `loop_members.add` revoque, `review_join_requests` conserve.
     *
     * C'EST LE TEST QUI COMPTE. Avant le review fix, les deux gestes passaient
     * par une seule permission : revoquer l'ajout direct aurait FERME au passage
     * la revue des demandes, sans que personne l'ait voulu. Un override doit
     * pouvoir dire « pas d'ajout de sa propre initiative, mais oui a la reponse
     * aux demandes » — et l'inverse.
     */
    public function test_revoking_the_add_permission_leaves_join_request_review_intact(): void
    {
        $this->revokeForFacilitator('loop_members.add');

        $this->assertFalse($this->facilitator->can('addMembers', $this->loop));
        $this->assertTrue($this->facilitator->can('manageJoinRequests', $this->loop));

        // La demande d'adhesion reste decidable...
        //
        // L'ORDRE COMPTE, et pas pour une raison de logique metier :
        // `LoopController::acceptJoinRequest()` declare `: RedirectResponse`,
        // mais `redirect()` rend un `Livewire\...\Redirector` des qu'un
        // composant Livewire a ete monte dans le meme processus — d'ou un
        // TypeError, donc un 500, si l'on monte la Card AVANT. En HTTP reel
        // aucun composant n'est monte : la production n'est pas concernee.
        // Fragilite signalee comme dette, non corrigee ici (hors perimetre).
        $request = $this->pendingJoinRequest();
        $this->actingAs($this->facilitator)
            ->post(route('loop-join-requests.accept', $request))
            ->assertRedirect();

        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $request->fresh()->status);

        // ...tandis que l'ajout direct, lui, est refuse.
        $this->actingAs($this->facilitator);
        Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
            ->set('selected', [$this->stranger->id])
            ->call('add')
            ->assertForbidden();
    }

    /**
     * Le miroir : `review_join_requests` revoque, `add` conserve.
     */
    public function test_revoking_join_request_review_leaves_the_direct_add_intact(): void
    {
        $this->revokeForFacilitator('loop_members.review_join_requests');

        $this->assertTrue($this->facilitator->can('addMembers', $this->loop));
        $this->assertFalse($this->facilitator->can('manageJoinRequests', $this->loop));

        // La demande d'adhesion n'est plus decidable...
        $request = $this->pendingJoinRequest();
        $this->actingAs($this->facilitator)
            ->post(route('loop-join-requests.accept', $request))
            ->assertForbidden();
        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $request->fresh()->status);
        $request->delete();

        // ...mais l'ajout direct fonctionne toujours.
        $this->actingAs($this->facilitator);
        Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
            ->set('selected', [$this->orgMemberOutsideLoop->id])
            ->call('add');

        $this->assertSame(1, $this->activeMemberships($this->orgMemberOutsideLoop));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function aiMessage(): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Une reponse IA capitalisable.',
            'type' => 'ai',
            'metadata' => ['ai_mode' => 'rag'],
            'organization_id' => $this->loop->organization_id,
        ]);
    }

    private function capitalizeAs(User $curator): BlogPost
    {
        $message = $this->aiMessage();

        $this->actingAs($curator);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCapitalization', $message->id)
            ->call('saveCapitalization')
            ->assertHasNoErrors();

        return BlogPost::query()->whereNotNull('ai_origin')->sole();
    }

    /** Une demande d'adhesion en attente, de l'Organization de la Boucle. */
    private function pendingJoinRequest(): LoopJoinRequest
    {
        return LoopJoinRequest::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->orgMemberOutsideLoop->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);
    }

    /**
     * Revoque une permission pour le facilitator, par le chemin canonique des
     * overrides d'Organization — jamais en reecrivant la config a la main.
     */
    private function revokeForFacilitator(string $permission): void
    {
        $ok = app(LoopPermissionSettingsService::class)->setOrganization(
            $this->organization->fresh(),
            $this->loop->type,
            'facilitator',
            $permission,
            false,
        );

        $this->assertTrue($ok, "l'override doit etre accepte pour {$permission}");
        $this->loop = $this->loop->fresh();
    }

    private function activeMemberships(User $user): int
    {
        return LoopMember::query()
            ->where('loop_id', $this->loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->count();
    }
}
