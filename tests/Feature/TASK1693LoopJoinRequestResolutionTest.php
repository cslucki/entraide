<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug L — devenir membre SOLDE la demande en attente.
 *
 * ## Le defaut, mesure sur la base reelle
 *
 * Sur la Boucle `ofsh`, DEUX personnes etaient a la fois membres actives et
 * porteuses d'une demande `pending` : l'une proprietaire, l'autre ajoutee par
 * l'administration. Les ecrans affichaient « 2 demandes en attente » pour des
 * gens deja dans la Boucle, avec des boutons « Accepter / Refuser » sans objet.
 *
 * Les ecrans disaient vrai sur ce qu'ils lisaient. C'est la DONNEE qui etait
 * incoherente — et donc le service qui la produit.
 *
 * ## Quatre chemins, un seul en soldait
 *
 * `acceptJoinRequest()` soldait deja la demande. `addMemberByUserId()`,
 * `addMember()` et `joinOpenLoop()` creaient un membre actif sans jamais
 * regarder les demandes. La regle vit desormais a UN endroit, appele par les
 * trois.
 */
class TASK1693LoopJoinRequestResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Loop $loop;

    private User $demandeur;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693l',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'access_mode' => Loop::ACCESS_REQUEST,
            'status' => 'active',
        ]);

        LoopMember::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => User::factory()->complete()->create(['organization_id' => $this->organization->id])->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->demandeur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->service = app(LoopService::class);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function demande(): LoopJoinRequest
    {
        return LoopJoinRequest::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->demandeur->id,
            'organization_id' => $this->organization->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);
    }

    private function assertPlusAucuneDemandeEnAttente(string $chemin): void
    {
        $this->assertSame(
            0,
            LoopJoinRequest::query()
                ->where('loop_id', $this->loop->id)
                ->where('user_id', $this->demandeur->id)
                ->where('status', LoopJoinRequest::STATUS_PENDING)
                ->count(),
            "[{$chemin}] la demande doit etre soldee des lors que la personne est membre",
        );
    }

    /** L'ajout par l'administration — le cas exact signale par Cyril. */
    public function test_adding_a_member_through_the_admin_resolves_their_pending_request(): void
    {
        $demande = $this->demande();

        $this->service->addMemberByUserId($this->loop, $this->demandeur->id);

        $this->assertPlusAucuneDemandeEnAttente('addMemberByUserId');
        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $demande->fresh()->status,
            'entrer dans la Boucle EST l\'acceptation, quel que soit le chemin');
    }

    /** La designation d'un proprietaire passe par le meme chemin. */
    public function test_naming_an_owner_through_the_admin_resolves_their_pending_request(): void
    {
        $this->demande();

        $this->service->addMemberByUserId($this->loop, $this->demandeur->id, 'owner');

        $this->assertPlusAucuneDemandeEnAttente('addMemberByUserId(owner)');
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->demandeur->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_adding_a_member_by_model_resolves_their_pending_request(): void
    {
        $this->demande();

        $this->service->addMember($this->loop, $this->demandeur);

        $this->assertPlusAucuneDemandeEnAttente('addMember');
    }

    /**
     * Une Boucle peut passer de `request` a `open` en laissant des demandes
     * derriere elle. Celles-la doivent se solder aussi.
     */
    public function test_joining_an_open_loop_resolves_a_request_left_over_from_the_request_era(): void
    {
        $this->demande();

        $this->loop->forceFill(['access_mode' => Loop::ACCESS_OPEN])->save();
        $this->service->joinOpenLoop($this->loop->fresh(), $this->demandeur);

        $this->assertPlusAucuneDemandeEnAttente('joinOpenLoop');
    }

    /**
     * REACTIVATION : un ancien membre re-ajoute solde aussi sa demande.
     *
     * Ce chemin ne passe pas par `LoopMember::create()` mais par un `update()`
     * sur la ligne existante. Le traiter a part etait indispensable : la regle
     * posee seulement a la creation aurait laisse ce cas derriere elle.
     */
    public function test_reactivating_a_former_member_also_resolves_their_pending_request(): void
    {
        LoopMember::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->demandeur->id,
            'organization_id' => $this->organization->id,
            'role' => 'member',
            'status' => 'left',
        ]);

        $this->demande();

        $this->service->addMemberByUserId($this->loop, $this->demandeur->id);

        $this->assertPlusAucuneDemandeEnAttente('reactivation');
    }

    /**
     * Ce qui ne doit PAS bouger : la demande de quelqu'un d'AUTRE.
     *
     * Sans cette garde, une requete trop large solderait toutes les demandes
     * de la Boucle des qu'une seule personne la rejoint — un nettoyage qui
     * emporterait les autres.
     */
    public function test_another_persons_request_is_left_untouched(): void
    {
        $autre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        $sienne = $this->demande();
        $celleDeLautre = LoopJoinRequest::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $autre->id,
            'organization_id' => $this->organization->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);

        $this->service->addMemberByUserId($this->loop, $this->demandeur->id);

        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $sienne->fresh()->status);
        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $celleDeLautre->fresh()->status,
            'la demande d\'une autre personne ne doit pas etre emportee');
    }

    /** Ni une demande deja REFUSEE : l'historique des decisions reste. */
    public function test_an_already_rejected_request_keeps_its_decision(): void
    {
        $refusee = LoopJoinRequest::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->demandeur->id,
            'organization_id' => $this->organization->id,
            'status' => LoopJoinRequest::STATUS_REJECTED,
        ]);

        $this->service->addMemberByUserId($this->loop, $this->demandeur->id);

        $this->assertSame(LoopJoinRequest::STATUS_REJECTED, $refusee->fresh()->status,
            'une decision passee ne se reecrit pas : seules les demandes EN ATTENTE se soldent');
    }

    /**
     * Le FAIT, a l'ecran : plus de « demande en attente » pour un membre.
     *
     * C'est ce que Cyril voyait. Les tests ci-dessus mesurent la donnee ;
     * celui-ci mesure ce qu'elle produit.
     */
    public function test_the_loop_screen_no_longer_shows_a_pending_request_for_an_actual_member(): void
    {
        $this->demande();
        $this->service->addMemberByUserId($this->loop, $this->demandeur->id);

        $html = $this->actingAs($this->demandeur)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-loop-join-pending', $html);
    }

    /**
     * Ce que ce correctif a FAILLI detruire, et qui est desormais verrouille.
     *
     * Premier jet : la resolution etait ajoutee A COTE du mecanisme existant
     * (TASK-1381, ajout en masse), et s'executait AVANT lui. Elle soldait donc
     * la demande sans decideur, et quand le code d'origine cherchait des
     * demandes en attente il n'en trouvait plus : `decided_by` restait nul et
     * la notification ne partait jamais. Deux garanties perdues en silence —
     * deux tests existants les ont attrapees.
     *
     * La regle a donc ete DEPLACEE d'un cran vers le bas plutot que dupliquee,
     * et elle porte le decideur et la notification. Ces deux tests fixent le
     * resultat la ou le defaut se produisait : le chemin ADMINISTRATION.
     */
    public function test_the_admin_who_adds_someone_is_recorded_as_the_decider(): void
    {
        $demande = $this->demande();
        $superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.loops.members.add', $this->loop), [
                'user_id' => $this->demandeur->id,
                'role' => 'member',
            ])
            ->assertRedirect();

        $demande->refresh();

        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $demande->status);
        $this->assertSame($superAdmin->id, $demande->decided_by,
            'un ajout par l\'administration a un DECIDEUR : il doit etre inscrit');
        $this->assertNotNull($demande->decided_at);
    }

    /** Et le demandeur en est averti : pour lui, c'est la meme nouvelle. */
    public function test_the_applicant_is_notified_when_an_admin_adds_them(): void
    {
        $this->demande();
        $superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        // La colonne est `recipient_id` — `user_id` n'existe pas sur ce modele,
        // et un comptage sur une colonne absente rendrait 0 sans rien prouver.
        $avant = \App\Models\MemberNotification::query()
            ->where('recipient_id', $this->demandeur->id)->count();

        $this->actingAs($superAdmin)
            ->post(route('admin.loops.members.add', $this->loop), [
                'user_id' => $this->demandeur->id,
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertGreaterThan(
            $avant,
            \App\Models\MemberNotification::query()->where('recipient_id', $this->demandeur->id)->count(),
            'etre ajoute vaut acceptation : le demandeur recoit la meme notification que depuis l\'ecran de decision',
        );
    }
}
