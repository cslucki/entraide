<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\DemandesDAdhesionEnAttente;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1761 point 2 — la carte « Demandes à rejoindre une Boucle ».
 *
 * ## Le danger principal de cette TASK, et ce que ce fichier garde
 *
 * Cyril ecrit : « si un membre est accepté, il a notification, email, pwa, etc.
 * **comme la procédure normale** ». Le defaut le plus probable etait donc de
 * construire un SECOND chemin d'acceptation, qui finirait par diverger du
 * premier — en silence, parce que la carte marcherait.
 *
 * La carte n'invente rien : ses deux boutons pointent sur
 * `admin.loops.join-requests.accept|reject`, les routes qui servent deja la
 * fiche d'une Boucle, et qui appellent `LoopService::acceptJoinRequest()`.
 * **Le test qui compte est celui qui mesure que la personne acceptee recoit
 * exactement ce qu'elle recevrait depuis la Boucle.**
 */
class TASK1761CarteDemandesAdhesionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1761c', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1761c', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->orgA);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
    }

    /** Une Boucle avec son proprietaire, et une demande en attente dessus. */
    private function demandeEnAttente(Organization $org): LoopJoinRequest
    {
        $proprietaire = User::factory()->create(['organization_id' => $org->id]);

        $boucle = Loop::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $proprietaire->id,
        ]);

        LoopMember::create([
            'loop_id' => $boucle->id, 'user_id' => $proprietaire->id,
            'role' => 'owner', 'status' => 'active',
            'joined_at' => now(), 'organization_id' => $org->id,
        ]);

        return LoopJoinRequest::create([
            'organization_id' => $org->id,
            'loop_id' => $boucle->id,
            'user_id' => User::factory()->create(['organization_id' => $org->id])->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);
    }

    // ── La source de donnees ─────────────────────────────────────────────────

    /** 🔴 TOUTES les Organizations — demande explicite de Cyril. */
    public function test_la_liste_traverse_les_organizations(): void
    {
        $a = $this->demandeEnAttente($this->orgA);
        $b = $this->demandeEnAttente($this->orgB);

        $ids = DemandesDAdhesionEnAttente::lister()->pluck('id');

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids, 'La liste doit etre CROSS-TENANT : « toutes organisations confondues ».');
        $this->assertSame(2, DemandesDAdhesionEnAttente::compter());
    }

    /**
     * Et elle se BORNE quand on le demande.
     *
     * `/admin/loops` filtre deja par Organization : lui servir la liste globale
     * ferait apparaitre, sous une page filtree, des demandes d'ailleurs.
     */
    public function test_la_liste_se_borne_a_une_organization_quand_on_le_demande(): void
    {
        $a = $this->demandeEnAttente($this->orgA);
        $this->demandeEnAttente($this->orgB);

        $ids = DemandesDAdhesionEnAttente::lister($this->orgA->id)->pluck('id');

        $this->assertSame([$a->id], $ids->all());
        $this->assertSame(1, DemandesDAdhesionEnAttente::compter($this->orgA->id));
    }

    /** Une demande deja traitee n'est plus en attente. */
    public function test_une_demande_traitee_disparait_de_la_liste(): void
    {
        $d = $this->demandeEnAttente($this->orgA);
        $d->update(['status' => LoopJoinRequest::STATUS_ACCEPTED]);

        $this->assertCount(0, DemandesDAdhesionEnAttente::lister());
    }

    // ── La carte ─────────────────────────────────────────────────────────────

    /** Elle s'affiche sur le tableau de bord, avec ses lignes et ses boutons. */
    public function test_la_carte_est_sur_le_tableau_de_bord_avec_ses_deux_gestes(): void
    {
        $this->demandeEnAttente($this->orgA);
        $this->demandeEnAttente($this->orgB);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-marqueur="demandes-adhesion"', $html);
        $this->assertSame(2, substr_count($html, 'data-marqueur="demande-adhesion"'),
            'Les deux demandes, toutes Organizations confondues, doivent apparaitre.');
        $this->assertStringContainsString('data-marqueur="demande-accepter"', $html);
        $this->assertStringContainsString('data-marqueur="demande-refuser"', $html);
    }

    /** Sans demande, la carte le dit au lieu de disparaitre. */
    public function test_sans_demande_la_carte_affiche_un_etat_vide(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-marqueur="demandes-adhesion-vide"', $html);
    }

    // ── LA GARDE QUI COMPTE ──────────────────────────────────────────────────

    /**
     * 🔴 ACCEPTER DEPUIS LA CARTE = LA PROCEDURE NORMALE.
     *
     * « si un membre est accepté à rejoindre une boucle, il a notification,
     * email, pwa, etc. comme la procédure normale ». Ce test mesure les
     * CONSEQUENCES, pas le code appele : le membre entre dans la Boucle ET il
     * est notifie. Un second chemin d'acceptation echouerait ici.
     */
    public function test_accepter_depuis_la_carte_fait_entrer_le_membre_et_le_notifie(): void
    {
        $demande = $this->demandeEnAttente($this->orgA);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.join-requests.accept', [$demande->loop, $demande]))
            ->assertSessionHasNoErrors();

        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $demande->fresh()->status);

        $this->assertTrue(
            LoopMember::where('loop_id', $demande->loop_id)->where('user_id', $demande->user_id)->exists(),
            'La personne acceptee n\'est pas entree dans la Boucle.'
        );

        $this->assertTrue(
            MemberNotification::query()->withoutGlobalScopes()
                ->where('recipient_id', $demande->user_id)
                ->where('notification_key', NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED)
                ->exists(),
            'La personne acceptee n\'a pas ete notifiee : la carte a un chemin d\'acceptation a elle.'
        );
    }

    /** Et refuser notifie aussi — l'autre moitie de la chorégraphie. */
    public function test_refuser_depuis_la_carte_notifie_la_personne(): void
    {
        $demande = $this->demandeEnAttente($this->orgA);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.join-requests.reject', [$demande->loop, $demande]))
            ->assertSessionHasNoErrors();

        $this->assertSame(LoopJoinRequest::STATUS_REJECTED, $demande->fresh()->status);

        $this->assertTrue(
            MemberNotification::query()->withoutGlobalScopes()
                ->where('recipient_id', $demande->user_id)
                ->where('notification_key', NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED)
                ->exists()
        );
    }

    /**
     * 🔴 La decision RAMENE sur la page d'ou elle vient.
     *
     * C'est tout l'objet du point 2 : « gérer les demandes rapidement ». Une
     * redirection vers la fiche de la Boucle arracherait le SuperAdmin a son
     * tableau de bord a chaque geste.
     */
    public function test_la_decision_ramene_sur_la_page_d_origine(): void
    {
        $demande = $this->demandeEnAttente($this->orgA);

        $this->actingAs($this->superAdmin)
            ->from(route('admin.dashboard'))
            ->post(route('admin.loops.join-requests.accept', [$demande->loop, $demande]))
            ->assertRedirect(route('admin.dashboard'));
    }

    /** 🔴 Un membre ordinaire n'approche pas de ces gestes. */
    public function test_un_membre_ordinaire_ne_peut_ni_voir_ni_decider(): void
    {
        $demande = $this->demandeEnAttente($this->orgA);
        $quidam = User::factory()->create(['organization_id' => $this->orgA->id]);

        $this->actingAs($quidam)->get(route('admin.dashboard'))->assertForbidden();

        $this->actingAs($quidam)
            ->post(route('admin.loops.join-requests.accept', [$demande->loop, $demande]))
            ->assertForbidden();

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $demande->fresh()->status);
    }
}
