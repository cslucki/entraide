<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopActivityTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $member;

    private User $nonMember;

    private User $crossUser;

    private Loop $loop;

    private Loop $inactiveLoop;

    private LoopMessageService $messageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        app()->instance('current_organization', $this->organization);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $loopService = new LoopService;

        $this->loop = $loopService->createLoop($this->owner, 'Active Loop');
        $loopService->addMember($this->loop, $this->member, 'member');

        $this->inactiveLoop = $loopService->createLoop($this->owner, 'Inactive Loop');
        $loopService->addMember($this->inactiveLoop, $this->member, 'member');

        $this->messageService = new LoopMessageService;
    }

    // -------------------------------------------------------------------------
    // Service: touch on sendUserMessage
    // -------------------------------------------------------------------------

    public function test_send_user_message_touches_loop_updated_at(): void
    {
        $originalUpdatedAt = $this->loop->updated_at->copy();

        $this->travel(5)->minutes();

        $this->messageService->sendUserMessage(
            $this->loop,
            $this->member,
            'Activity check',
        );

        $freshLoop = $this->loop->fresh();

        $this->assertTrue(
            $freshLoop->updated_at->gt($originalUpdatedAt),
            'Loop updated_at should be touched after sending a user message',
        );
        $this->assertEquals('Activity check', $freshLoop->messages()->latest()->first()->body);
    }

    public function test_send_help_request_touches_loop_updated_at(): void
    {
        $originalUpdatedAt = $this->loop->updated_at->copy();

        $this->travel(5)->minutes();

        $this->messageService->sendHelpRequestMessage(
            $this->loop,
            $this->member,
            'I need help with architecture',
            'Help title',
            'I need help',
            'Project context',
            'advice',
            null,
            'normal',
        );

        $freshLoop = $this->loop->fresh();

        $this->assertTrue(
            $freshLoop->updated_at->gt($originalUpdatedAt),
            'Loop updated_at should be touched after sending a help request message',
        );
    }

    // -------------------------------------------------------------------------
    // Service: touch preserves tenant isolation
    // -------------------------------------------------------------------------

    public function test_send_message_from_same_organization_touches_correct_loop(): void
    {
        $activeOriginalUpdatedAt = $this->loop->updated_at->copy();
        $inactiveOriginalUpdatedAt = $this->inactiveLoop->updated_at->copy();

        $this->travel(5)->minutes();

        $this->messageService->sendUserMessage(
            $this->loop,
            $this->member,
            'Only this loop should be touched',
        );

        $this->assertTrue(
            $this->loop->fresh()->updated_at->gt($activeOriginalUpdatedAt),
            'The loop receiving the message should be touched',
        );
        $this->assertEquals(
            $inactiveOriginalUpdatedAt->toDateTimeString(),
            $this->inactiveLoop->fresh()->updated_at->toDateTimeString(),
            'The inactive loop should not be touched',
        );
    }

    // -------------------------------------------------------------------------
    // Web route: activity sorting on index
    // -------------------------------------------------------------------------

    public function test_index_orders_active_loop_before_inactive_loop(): void
    {
        $this->travel(5)->minutes();

        $this->messageService->sendUserMessage(
            $this->loop,
            $this->member,
            'Recent activity on active loop',
        );

        $response = $this->actingAs($this->member)
            ->get(route('loops.index'));

        $response->assertStatus(200);

        $response->assertSeeInOrder(['Active Loop', 'Inactive Loop']);
    }

    public function test_index_shows_last_message_time(): void
    {
        $this->messageService->sendUserMessage(
            $this->loop,
            $this->member,
            'This message sets the last activity',
        );

        $response = $this->actingAs($this->member)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertSee('Active Loop');
        $response->assertSee('Inactive Loop');
    }

    // -------------------------------------------------------------------------
    // Tenant safety: cross-community isolation
    // -------------------------------------------------------------------------

    public function test_cross_organization_user_cannot_see_loop_on_index(): void
    {
        $this->organization->update(['is_active' => false]);
        app()->forgetInstance('current_organization');

        $response = $this->actingAs($this->crossUser)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertDontSee('Active Loop');
        $response->assertDontSee('Inactive Loop');
    }

    public function test_non_member_can_discover_loop_on_index(): void
    {
        // TASK-1075: the catalog now shows every active Loop of the Organization,
        // member or not — discovery no longer requires membership. Tenant
        // isolation (cross-org) remains strict, see the test above.
        $response = $this->actingAs($this->nonMember)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertSee('Active Loop');
        $response->assertSee('Inactive Loop');
    }

    public function test_member_of_one_loop_also_discovers_same_organization_other_loop(): void
    {
        // TASK-1075: catalog discovery is Organization-wide, not membership-gated.
        $loopService = new LoopService;
        $anotherLoop = $loopService->createLoop($this->owner, 'Owner Only Loop');

        $response = $this->actingAs($this->member)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertSee('Active Loop');
        $response->assertSee('Owner Only Loop');
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    /**
     * TASK-1686 — ce test s'appelait
     * `test_loop_without_messages_shows_dash_instead_of_time` et affirmait
     * `assertSee('—')`. Il est devenu ROUGE, et le diagnostic a montre qu'il
     * ne mesurait pas ce que son nom annoncait.
     *
     * ## Ce qui a ete mesure
     *
     * Le SEUL em-dash de la carte de catalogue
     * (`loops/partials/catalog-card.blade.php:65`) vit dans l'`aria-label` du
     * bouton « Modifier », rendu sous `@can('update', $item)`. Ce test agit en
     * MEMBRE simple : le bouton n'est pas rendu, et **aucun em-dash n'existe
     * sur la page**. L'assertion passait donc par COINCIDENCE, grace a un
     * libelle d'accessibilite sans rapport avec l'activite d'une Boucle ; elle
     * s'est eteinte le jour ou ce bouton a cesse d'etre rendu a ce profil.
     *
     * ## Le produit est CORRECT — rien n'a ete ajoute pour verdir ce test
     *
     * Le catalogue n'affiche NI horodatage NI tiret pour l'activite. Depuis le
     * catalogue premium (T1075/T1076), le seul signal est une pastille
     * « Actif recemment », conditionnee a `last_message_at` de moins de 7 jours
     * (`catalog-card.blade.php:25,121`). `VisibleLoops::query()` calcule bien
     * cette valeur (`withMax('messages as last_message_at', 'created_at')`).
     *
     * Mesure du contrat reel, Boucle sans message contre Boucle avec message :
     * **0 pastille** puis **1 pastille**. C'est ce que ce test affirme
     * desormais — l'ABSENCE de signal d'activite sur une Boucle muette, et sa
     * PRESENCE des qu'un message existe. Sans la seconde moitie, un test qui
     * n'affirme qu'une absence resterait vert si la pastille disparaissait du
     * produit.
     *
     * ## Le piege de langue
     *
     * La suite tourne en locale `en` : la page rend « Recently active » quand
     * `__()` appele depuis le test peut rendre « Actif recemment ». Le libelle
     * attendu est donc resolu dans la locale que la REPONSE declare, jamais
     * dans celle du contexte de test — sans quoi l'assertion passerait ou
     * echouerait par coincidence de langue, exactement le defaut qu'on corrige.
     */
    public function test_loop_without_messages_shows_no_recent_activity_badge(): void
    {
        $response = $this->actingAs($this->member)->get(route('loops.index'));
        $response->assertStatus(200);

        $badge = $this->badgeLabelFor($response->getContent());

        $this->assertStringNotContainsString(
            $badge,
            $response->getContent(),
            'une Boucle sans message ne doit porter aucun signal d\'activite'
        );

        // La contre-epreuve : la pastille existe bel et bien des qu'un message
        // est publie. Elle prouve que l'absence ci-dessus mesure le produit, et
        // non un libelle introuvable.
        $this->messageService->sendUserMessage($this->loop, $this->member, 'Un message');

        $after = $this->actingAs($this->member)->get(route('loops.index'));
        $after->assertStatus(200);

        $this->assertSame(
            1,
            substr_count($after->getContent(), $badge),
            'la Boucle qui vient de recevoir un message doit porter la pastille, et elle seule'
        );
    }

    /**
     * Le libelle de la pastille, resolu dans la locale que la REPONSE declare.
     *
     * La suite tourne en `en` et la page rend `lang="en"`, alors que `__()`
     * appele depuis le contexte de test peut rendre le francais. Lire la locale
     * sur la reponse elle-meme supprime cette dependance.
     */
    private function badgeLabelFor(string $html): string
    {
        preg_match('/<html[^>]*\blang="([a-z]{2})/i', $html, $m);

        return __('loops.card_recently_active', [], $m[1] ?? app()->getLocale());
    }

    public function test_guest_cannot_access_index(): void
    {
        $response = $this->get(route('loops.index'));

        $response->assertRedirect(route('login'));
    }
}
