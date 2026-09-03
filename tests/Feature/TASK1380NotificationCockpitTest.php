<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use App\Support\Ops\NotificationCockpitDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1380 — la supervision des notifications.
 *
 * ## La propriete qui compte : superviser n'est pas lire
 *
 * Un SuperAdmin doit pouvoir dire « la livraison email est en panne » sans
 * jamais apprendre qui a ete invite, a quoi, ni ce que disait le message. Ces
 * tests construisent donc des donnees PORTEUSES DE SENTINELLES — une adresse,
 * un nom, un jeton — et verifient qu'aucune n'atteint l'ecran.
 *
 * C'est la mesure la plus importante du fichier : le reste compte des nombres,
 * celle-ci verifie une frontiere.
 *
 * ## Ce que l'ecran doit rendre LISIBLE
 *
 * Deux situations sont documentees comme observables et jamais reprises
 * automatiquement : une livraison bloquee en `sending` apres la mort d'un
 * worker, et une livraison `pending` que plus aucun job ne porte. Elles sont
 * construites ici, et l'ecran doit les compter.
 */
class TASK1380NotificationCockpitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $orgA;

    private Organization $orgB;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);

        $this->orgA = Organization::factory()->create(['name' => 'T1380 Organisation A', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['name' => 'T1380 Organisation B', 'is_active' => true]);

        $expediteur = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'email' => 'sentinelle-destinataire@exemple-t1380.test',
            'preferred_locale' => 'fr',
        ]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->orgA->id,
            'created_by' => $expediteur->id,
            'status' => 'active',
        ]);

        Queue::fake();
    }

    // =====================================================================
    // 1. La frontiere : superviser n'est pas lire
    // =====================================================================

    /**
     * **Aucune donnee identifiante n'atteint l'ecran.**
     *
     * Les sentinelles sont posees dans les VRAIES colonnes que le cockpit
     * pourrait etre tente de lire : l'adresse du destinataire, le jeton de
     * l'invitation, le corps archive, l'identifiant de l'objet metier.
     */
    public function test_the_screen_never_exposes_identifying_data(): void
    {
        $invitation = $this->inviterMembre();
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        // Une preuve historique complete, comme le pipeline en ecrit une.
        $journal = new EmailLog([
            'user_id' => (string) $this->membre->id,
            'to_email' => (string) $this->membre->email,
            'subject' => 'Sujet sentinelle T1380',
            'status' => EmailLog::STATUS_SENT,
            'organization_id' => (string) $this->orgA->id,
        ]);
        $journal->notification_id = (string) $livraison->notification_id;
        $journal->locale = 'fr';
        $journal->body_html = 'CORPS-SENTINELLE-T1380 [action-link-redacted]';
        $journal->body_hash = hash('sha256', 'peu importe');
        $journal->save();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.notifications-cockpit'))
            ->assertOk()
            ->getContent();

        $interdits = [
            (string) $this->membre->email => 'une adresse email en clair',
            (string) $invitation->token => 'un jeton d\'action vivant',
            'CORPS-SENTINELLE-T1380' => 'un corps de message archive',
            'Sujet sentinelle T1380' => 'un sujet de message',
            (string) $this->membre->id => 'un identifiant de destinataire',
            (string) $invitation->id => 'un identifiant d\'objet metier',
            (string) $journal->body_hash => 'une empreinte correlable',
        ];

        foreach ($interdits as $sentinelle => $quoi) {
            $this->assertStringNotContainsString(
                $sentinelle,
                $html,
                "L'ecran expose {$quoi} : superviser n'est pas lire."
            );
        }
    }

    /** Et l'ecran le DIT, plutot que de laisser croire a un oubli. */
    public function test_the_screen_states_what_it_does_not_show(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.notifications-cockpit'))
            ->assertOk()
            ->assertSee('data-cockpit-limites', false)
            ->assertSee('Superviser n\'est pas lire', false);
    }

    // =====================================================================
    // 2. La garde d'acces
    // =====================================================================

    /** Un membre ordinaire n'atteint pas cet ecran. */
    public function test_a_plain_member_cannot_reach_the_cockpit(): void
    {
        $reponse = $this->actingAs($this->membre)->get(route('admin.notifications-cockpit'));

        $this->assertContains($reponse->status(), [302, 403, 404], 'Un membre ordinaire ne doit pas y acceder.');
    }

    /** Un visiteur non plus. */
    public function test_a_guest_is_sent_away(): void
    {
        $this->get(route('admin.notifications-cockpit'))->assertRedirect(route('login'));
    }

    // =====================================================================
    // 3. Ce que l'ecran compte
    // =====================================================================

    /** Les etats de livraison sont comptes, et les « ignorees » a part. */
    public function test_deliveries_are_counted_and_skips_are_not_incidents(): void
    {
        $this->inviterMembre();
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        // Une livraison IGNOREE : une decision, pas une panne.
        DB::table('member_notification_deliveries')
            ->where('id', $livraison->id)
            ->update(['status' => NotificationDeliveryStatus::SKIPPED_PREFERENCE, 'diagnostic' => 'channel_disabled_by_member']);

        $etat = app(NotificationCockpitDiagnostics::class)->overview();

        $this->assertSame(1, $etat['livraisons'][NotificationDeliveryStatus::SKIPPED_PREFERENCE]);
        $this->assertSame(1, $etat['livraisons']['_ignorees']);
        $this->assertSame(
            0,
            $etat['livraisons']['_incidents'],
            'Un membre qui a coupe l\'email n\'est pas un incident.'
        );

        $this->actingAs($this->admin)
            ->get(route('admin.notifications-cockpit'))
            ->assertOk()
            ->assertSee('data-livraisons-ignorees', false)
            ->assertSee('data-cockpit-verdict="nominal"', false);
    }

    /** `ambiguous` compte comme incident, distinct de `failed`. */
    public function test_ambiguous_counts_as_an_incident_distinct_from_failed(): void
    {
        $this->inviterMembre();
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        DB::table('member_notification_deliveries')
            ->where('id', $livraison->id)
            ->update(['status' => NotificationDeliveryStatus::AMBIGUOUS, 'diagnostic' => 'transport_outcome_unknown']);

        $etat = app(NotificationCockpitDiagnostics::class)->overview();

        $this->assertSame(1, $etat['livraisons'][NotificationDeliveryStatus::AMBIGUOUS]);
        $this->assertSame(0, $etat['livraisons'][NotificationDeliveryStatus::FAILED], 'ambiguous n\'est pas failed.');
        $this->assertSame(1, $etat['livraisons']['_incidents']);

        // Le code de diagnostic est remonte — il est sur par construction.
        $this->assertSame(
            [['code' => 'transport_outcome_unknown', 'total' => 1]],
            $etat['diagnostics']
        );
    }

    /** Le catalogue est ITERE, jamais code en dur. */
    public function test_the_catalogue_is_iterated_from_the_registry(): void
    {
        $this->inviterMembre();

        $etat = app(NotificationCockpitDiagnostics::class)->overview();

        $this->assertCount(count(NotificationCatalogue::keys()), $etat['catalogue']);

        $entree = collect($etat['catalogue'])->firstWhere('cle', NotificationCatalogue::LOOP_INVITATION);
        $this->assertNotNull($entree);
        $this->assertSame(1, $entree['total'], 'Le volume emis est joint depuis les notifications.');
        $this->assertNotNull($entree['derniere']);

        $canaux = collect($entree['canaux'])->keyBy('canal');
        $this->assertFalse($canaux[NotificationCatalogue::CHANNEL_IN_APP]['configurable'], 'in_app est obligatoire.');
        $this->assertTrue($canaux[NotificationCatalogue::CHANNEL_EMAIL]['configurable'], 'email est reglable.');
    }

    // =====================================================================
    // 4. Ce qui demande une intervention HUMAINE
    // =====================================================================

    /**
     * **Une livraison bloquee en envoi est VUE.**
     *
     * Un worker mort apres la prise de travail laisse la livraison en `sending`
     * pour toujours : aucun rejeu ne la reprend. `claimed_at` est ce qui la rend
     * distinguable d'une livraison reellement en cours.
     */
    public function test_a_delivery_stuck_in_sending_is_surfaced(): void
    {
        $this->inviterMembre();
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        DB::table('member_notification_deliveries')
            ->where('id', $livraison->id)
            ->update([
                'status' => NotificationDeliveryStatus::SENDING,
                'claimed_at' => now()->subSeconds(NotificationCockpitDiagnostics::SECONDES_AVANT_BLOCAGE + 60),
                'attempts' => 1,
            ]);

        $etat = app(NotificationCockpitDiagnostics::class)->overview();
        $this->assertSame(1, $etat['alertes']['bloquees_en_envoi']);

        $this->actingAs($this->admin)
            ->get(route('admin.notifications-cockpit'))
            ->assertOk()
            ->assertSee('data-cockpit-verdict="attention"', false);
    }

    /** Une livraison prise a l'instant n'est PAS signalee. */
    public function test_a_delivery_just_claimed_is_not_flagged(): void
    {
        $this->inviterMembre();
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        DB::table('member_notification_deliveries')
            ->where('id', $livraison->id)
            ->update(['status' => NotificationDeliveryStatus::SENDING, 'claimed_at' => now()]);

        $etat = app(NotificationCockpitDiagnostics::class)->overview();

        $this->assertSame(
            0,
            $etat['alertes']['bloquees_en_envoi'],
            'Un worker vivant ne doit pas declencher une alerte : le seuil existe pour cela.'
        );
    }

    /** Une livraison en attente depuis trop longtemps est VUE. */
    public function test_a_long_pending_delivery_is_surfaced(): void
    {
        $this->inviterMembre();
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        DB::table('member_notification_deliveries')
            ->where('id', $livraison->id)
            ->update(['created_at' => now()->subSeconds(NotificationCockpitDiagnostics::SECONDES_AVANT_ATTENTE_ANORMALE + 60)]);

        $etat = app(NotificationCockpitDiagnostics::class)->overview();

        $this->assertSame(1, $etat['alertes']['en_attente_anciennes']);
    }

    /** Une reprise manuelle laisse une trace visible. */
    public function test_a_manual_retry_is_visible(): void
    {
        $this->inviterMembre();
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        DB::table('member_notification_deliveries')->where('id', $livraison->id)->update(['attempts' => 3]);

        $this->assertSame(1, app(NotificationCockpitDiagnostics::class)->overview()['alertes']['reprises_manuelles']);
    }

    // =====================================================================
    // 5. La file, et la quarantaine qu'on ne touche pas
    // =====================================================================

    /**
     * **La file DEDIEE est mesuree, et `default` reste une simple reference.**
     *
     * Melanger les deux ferait passer 201 jobs de quarantaine pour un retard de
     * notifications.
     */
    public function test_the_dedicated_queue_is_measured_apart_from_default(): void
    {
        DB::table('jobs')->insert([
            ['queue' => 'notifications-email', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(5)->timestamp, 'created_at' => now()->timestamp],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
        ]);

        $file = app(NotificationCockpitDiagnostics::class)->overview()['file'];

        $this->assertSame('notifications-email', $file['nom']);
        $this->assertSame(1, $file['en_attente'], 'Seule la file dediee est comptee.');
        $this->assertSame(2, $file['default_reference'], 'default est cite a part, pour qu\'une derive se voie.');
        $this->assertNotNull($file['plus_ancien']);
    }

    // =====================================================================
    // 6. Tenant
    // =====================================================================

    /**
     * **L'ecran voit TOUTES les Organizations — et n'en melange aucune.**
     *
     * La portee plateforme est assumee : `is_admin` n'est pas une appartenance.
     * Ce qui doit rester vrai, c'est que les volumes ne se confondent pas.
     */
    public function test_the_cockpit_sees_every_tenant_without_mixing_them(): void
    {
        $this->inviterMembre();

        // Une notification dans un AUTRE tenant.
        $autreExpediteur = User::factory()->create(['organization_id' => $this->orgB->id]);
        $autreMembre = User::factory()->create(['organization_id' => $this->orgB->id]);
        $autreLoop = Loop::factory()->create([
            'organization_id' => $this->orgB->id,
            'created_by' => $autreExpediteur->id,
            'status' => 'active',
        ]);
        app(LoopInvitationService::class)->invite($autreLoop, $autreExpediteur, $autreMembre->email);

        $activite = collect(app(NotificationCockpitDiagnostics::class)->overview()['activite'])->keyBy('nom');

        $this->assertSame(1, $activite['T1380 Organisation A']['total']);
        $this->assertSame(1, $activite['T1380 Organisation B']['total']);
        $this->assertSame(2, MemberNotification::query()->count());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function inviterMembre(): LoopInvitation
    {
        return app(LoopInvitationService::class)->invite(
            $this->loop,
            User::query()->where('organization_id', $this->orgA->id)->whereKeyNot($this->membre->id)->firstOrFail(),
            $this->membre->email,
        );
    }
}
