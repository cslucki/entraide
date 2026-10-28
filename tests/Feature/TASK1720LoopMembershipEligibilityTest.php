<?php

namespace Tests\Feature;

use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Services\Loops\Exceptions\LoopInvitationRefusedException;
use App\Services\LoopService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * TASK-1720 — ce qui entre dans une Boucle, et ce qui en sort comme courriel.
 *
 * Trois volets, et les gardes sont choisies sur l'ENJEU, pas sur le nombre :
 *
 *  - **A** — un ajout direct previent la personne, et l'email est reellement
 *    REMIS. Le defaut n'etait pas l'absence de code mais une livraison
 *    `skipped_unreachable` : la garde mesure donc le statut final, jamais la
 *    seule planification ;
 *  - **B** — l'eligibilite. Les trois refus, et surtout le fait qu'ils
 *    tiennent COTE SERVEUR, pas seulement dans l'ecran ;
 *  - **C** — le libelle. « Ajoute » n'est pas « invite ».
 */
class TASK1720LoopMembershipEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    private User $hote;

    private $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create(['is_default' => true]);
        app()->instance('current_organization', $this->organisation);

        $this->hote = User::factory()->create([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);
        $this->loop = (new LoopService)->createLoop($this->hote, 'Boucle 1720');
    }

    private function membre(array $surcharges = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(),
            'approved_at' => now(),
        ], $surcharges));
    }

    private function ajouter(User $cible): array
    {
        return (new LoopService)->addMembersFromOrganization($this->loop, [$cible->id], $this->hote);
    }

    private function estMembre(User $u): bool
    {
        return LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $u->id)->where('status', 'active')->exists();
    }

    // ───────────────────────────────────────────────────────────────────────
    // A — l'ajout direct previent, et l'email est REMIS
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_ajout_direct_emet_la_notification_ET_remet_l_email(): void
    {
        Mail::fake();
        $ajoute = $this->membre();

        $this->ajouter($ajoute);

        $this->assertSame(1, MemberNotification::where(
            'notification_key', NotificationCatalogue::LOOP_MEMBER_ADDED
        )->count());

        // LE point de la TASK : pas « planifiee », pas « en attente » — REMISE.
        // Le defaut corrige rendait `skipped_unreachable` parce que le
        // resolveur de cible lisait la Boucle sous le scope de tenant, absent
        // dans un worker.
        $livraison = MemberNotificationDelivery::where('channel', 'email')->first();
        $this->assertNotNull($livraison);
        $this->assertSame('sent', $livraison->status, (string) $livraison->diagnostic);
    }

    public function test_la_cible_se_resout_SANS_contexte_de_tenant(): void
    {
        // La contre-epreuve du correctif, et la seule qui reproduise le
        // worker : on OUBLIE l'Organization courante avant de resoudre, comme
        // un job le fait. Sans le retrait de scope, la Boucle est introuvable
        // et la cible nulle.
        Mail::fake();
        $this->ajouter($this->membre());

        $notification = MemberNotification::where(
            'notification_key', NotificationCatalogue::LOOP_MEMBER_ADDED
        )->firstOrFail();

        app()->forgetInstance('current_organization');
        app()->instance('current_organization', null);

        $cible = app(\App\Support\Notifications\NotificationTargetResolver::class)->resolve($notification);

        $this->assertNotNull($cible, 'la cible doit se resoudre hors contexte de tenant');
        $this->assertStringContainsString('/loops/', $cible);
    }

    // ───────────────────────────────────────────────────────────────────────
    // B — l'eligibilite, cote SERVEUR
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_email_non_verifie_n_est_pas_ajoute(): void
    {
        $cible = $this->membre(['email_verified_at' => null]);

        $resultat = $this->ajouter($cible);

        $this->assertFalse($this->estMembre($cible));
        $this->assertSame(1, $resultat['skipped']);
        $this->assertSame(0, $resultat['added']);
    }

    public function test_un_compte_en_attente_de_validation_n_est_pas_ajoute(): void
    {
        $this->organisation->forceFill(['requires_account_approval' => true])->save();
        $cible = $this->membre(['approved_at' => null]);

        $this->ajouter($cible);

        $this->assertFalse($this->estMembre($cible));
    }

    public function test_un_compte_ferme_ou_banni_n_est_pas_ajoute(): void
    {
        $cible = $this->membre();
        $cible->forceFill(['banned_at' => now(), 'self_closed_at' => now()])->save();

        $this->ajouter($cible);

        $this->assertFalse($this->estMembre($cible));
    }

    public function test_un_compte_en_regle_EST_ajoute(): void
    {
        // La non-regression qui donne leur sens aux trois refus : sans elle,
        // bloquer tout le monde les passerait tous.
        Mail::fake();
        $cible = $this->membre();

        $this->ajouter($cible);

        $this->assertTrue($this->estMembre($cible));
    }

    public function test_l_ecran_ne_propose_pas_un_compte_ineligible(): void
    {
        $eligible = $this->membre();
        $sansEmail = $this->membre(['email_verified_at' => null]);

        $candidats = (new LoopService)->invitableOrganizationMembers($this->loop)->pluck('id');

        $this->assertTrue($candidats->contains($eligible->id));
        $this->assertFalse($candidats->contains($sansEmail->id));
    }

    // ───────────────────────────────────────────────────────────────────────
    // B — l'invitation d'un compte existant non eligible est REFUSEE
    // ───────────────────────────────────────────────────────────────────────

    public function test_inviter_un_compte_existant_non_verifie_est_refuse(): void
    {
        $this->membre(['email' => 'pasverifie@1720.test', 'email_verified_at' => null]);

        $this->expectException(LoopInvitationRefusedException::class);

        app(LoopInvitationService::class)->invite($this->loop, $this->hote, 'pasverifie@1720.test');
    }

    public function test_inviter_une_personne_SANS_compte_reste_possible(): void
    {
        // La contrainte explicite de MASTER : preserver les invitations vers
        // les personnes sans compte. Sans cette garde, un refus trop large
        // fermerait la porte d'entree du produit.
        $invitation = app(LoopInvitationService::class)
            ->invite($this->loop, $this->hote, 'inconnu@dehors.test');

        $this->assertSame('inconnu@dehors.test', $invitation->recipient_email);
    }

    public function test_un_homonyme_d_adresse_dans_un_AUTRE_tenant_ne_bloque_pas(): void
    {
        // La borne est l'Organization de la Boucle. Un compte ineligible
        // ailleurs n'a pas a empecher une invitation ici — il y est un externe.
        $autre = Organization::factory()->create();
        User::factory()->create([
            'organization_id' => $autre->id,
            'email' => 'ailleurs@autre.test',
            'email_verified_at' => null,
        ]);

        $invitation = app(LoopInvitationService::class)
            ->invite($this->loop, $this->hote, 'ailleurs@autre.test');

        $this->assertNotNull($invitation);
    }

    // ───────────────────────────────────────────────────────────────────────
    // C — « ajoute » n'est pas « invite »
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_courriel_d_ajout_ne_dit_pas_invite(): void
    {
        $compose = app(\App\Services\Loops\LoopInvitationMailer::class)
            ->composeForMember($this->loop, $this->membre(), $this->hote, null, 'fr');

        $this->assertStringContainsString('ajouté', $compose['subject']);
        $this->assertStringNotContainsString('invite', $compose['subject']);
        // `fallback_reason` nul prouve que c'est le GABARIT `loop_member_added`
        // qui a servi, et non le repli Blade.
        $this->assertNull($compose['fallback_reason']);
    }
}
