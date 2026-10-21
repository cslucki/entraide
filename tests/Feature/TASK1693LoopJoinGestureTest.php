<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug K — le geste propose suit le MODE D'ACCES de la Boucle.
 *
 * ## Le defaut
 *
 * La zone basse de `loops/show` n'avait qu'un bouton, « Rejoindre cette
 * boucle », conditionne a `isPublic()` — c'est-a-dire a la VISIBILITE. Or
 * `LoopPolicy::join()` exige `isOpenAccess()`, c'est-a-dire le MODE D'ACCES.
 *
 * Une Boucle `visibility = public` et `access_mode = request` affichait donc un
 * bouton que la politique refusait : 403 « votre compte ne dispose pas des
 * droits necessaires » sur un geste que l'ecran venait d'offrir.
 *
 * Le commentaire d'origine affirmait « the button is only ever shown for open
 * Loops ». C'etait faux : il etait montre pour les Boucles PUBLIQUES.
 * Visibilite et mode d'acces sont deux axes INDEPENDANTS, et les confondre
 * fabrique un cul-de-sac.
 *
 * ## Ce qui manquait
 *
 * `loops.join-requests.store` existait depuis toujours, avec sa politique et
 * son service — mais AUCUNE poignee dans cette vue. Une capacite non exposee
 * est une capacite absente.
 */
class TASK1693LoopJoinGestureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693k',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function boucle(string $accessMode): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'access_mode' => $accessMode,
            'status' => 'active',
        ]);

        LoopMember::query()->create([
            'loop_id' => $loop->id,
            'user_id' => User::factory()->complete()->create(['organization_id' => $this->organization->id])->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        // La Boucle doit etre la Boucle PRINCIPALE de l'Organization.
        //
        // `LoopController:671` : un non-membre qui appartient a l'Organization
        // recoit `loops.show` — et donc cette zone basse — UNIQUEMENT pour la
        // Boucle principale. Pour toute autre, il recoit `loops.presentation`,
        // qui a son propre jeu de boutons, deja correct.
        //
        // C'est precisement ce qui a produit le defaut signale : la Boucle
        // `ofsh` est la Boucle principale d'OFSH, et le bouton direct y a ete
        // offert a quelqu'un que la politique allait refuser. Une Boucle de
        // test non principale n'aurait jamais rendu cette zone — le premier
        // jet de ces tests mesurait donc une page sans rapport.
        $this->organization->forceFill(['primary_loop_id' => $loop->id])->save();

        return $loop;
    }

    private function page(Loop $loop): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $loop->id,
            ]))
            ->assertOk()
            ->getContent();
    }

    /**
     * LE defaut signale, dans les deux sens : l'ecran n'offre plus le geste
     * direct, et il offre celui qui fonctionne.
     */
    public function test_a_request_access_loop_offers_the_request_not_the_direct_join(): void
    {
        $loop = $this->boucle(Loop::ACCESS_REQUEST);

        $html = $this->page($loop);

        $this->assertStringNotContainsString('data-loop-join="direct"', $html,
            'le bouton direct menait a un 403 : il ne doit plus etre offert');
        $this->assertStringContainsString('data-loop-join-request', $html);
        $this->assertStringContainsString(
            e(route('organization.loops.join-requests.store', [
                'organization' => $this->organization->slug,
                'loop' => $loop->id,
            ])),
            $html,
        );
    }

    /** Et le geste offert aboutit reellement — c'est le fait, pas le balisage. */
    public function test_the_offered_request_actually_succeeds(): void
    {
        $loop = $this->boucle(Loop::ACCESS_REQUEST);

        $this->actingAs($this->membre)
            ->post(route('organization.loops.join-requests.store', [
                'organization' => $this->organization->slug,
                'loop' => $loop->id,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('loop_join_requests', [
            'loop_id' => $loop->id,
            'user_id' => $this->membre->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);
    }

    /** Une Boucle OUVERTE garde son bouton direct, qui aboutit. */
    public function test_an_open_loop_keeps_the_direct_join_and_it_works(): void
    {
        $loop = $this->boucle(Loop::ACCESS_OPEN);

        $html = $this->page($loop);
        $this->assertStringContainsString('data-loop-join="direct"', $html);
        $this->assertStringNotContainsString('data-loop-join-request', $html);

        $this->actingAs($this->membre)
            ->post(route('organization.loops.join', [
                'organization' => $this->organization->slug,
                'loop' => $loop->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $this->membre->id,
            'status' => 'active',
        ]);
    }

    /**
     * Une demande deja posee : on DIT qu'elle attend, et on offre de la retirer.
     *
     * Reafficher le bouton d'envoi serait le meme piege qu'au depart — la
     * politique refuse une seconde demande.
     */
    public function test_a_pending_request_is_announced_and_can_be_withdrawn(): void
    {
        $loop = $this->boucle(Loop::ACCESS_REQUEST);

        $demande = LoopJoinRequest::query()->create([
            'loop_id' => $loop->id,
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);

        $html = $this->page($loop);

        $this->assertStringContainsString('data-loop-join-pending', $html);
        $this->assertStringNotContainsString('data-loop-join-request', $html,
            'une seconde demande serait refusee : ne pas l\'offrir');
        $this->assertStringContainsString('data-loop-join-cancel', $html);

        $this->actingAs($this->membre)
            ->delete(route('loop-join-requests.cancel', $demande))
            ->assertRedirect();

        $this->assertDatabaseMissing('loop_join_requests', [
            'id' => $demande->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);
    }

    /** Sur invitation : aucun geste, et on le DIT plutot que de laisser un vide. */
    public function test_an_invitation_loop_offers_no_gesture_but_says_why(): void
    {
        $loop = $this->boucle(Loop::ACCESS_INVITATION);

        $html = $this->page($loop);

        $this->assertStringNotContainsString('data-loop-join="direct"', $html);
        $this->assertStringNotContainsString('data-loop-join-request', $html);
        $this->assertStringContainsString('data-loop-invitation-only', $html);
    }

    /**
     * La garde de fond : AUCUN geste offert ne doit etre refuse par la
     * politique.
     *
     * C'est la formulation generale du defaut — l'ecran et la politique
     * doivent dire la meme chose, pour les trois modes. Un test par mode
     * pourrait rester vert en se trompant de paire ; celui-ci les confronte.
     */
    public function test_no_offered_gesture_is_ever_refused_by_the_policy(): void
    {
        foreach ([Loop::ACCESS_OPEN, Loop::ACCESS_REQUEST, Loop::ACCESS_INVITATION] as $mode) {
            $loop = $this->boucle($mode);
            $html = $this->page($loop);

            if (str_contains($html, 'data-loop-join="direct"')) {
                $this->assertTrue($this->membre->can('join', $loop), "[{$mode}] le bouton direct est offert mais refuse");
            }

            if (str_contains($html, 'data-loop-join-request')) {
                $this->assertTrue($this->membre->can('requestToJoin', $loop), "[{$mode}] la demande est offerte mais refusee");
            }
        }
    }
}
