<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushSubscriptionStore;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1823 — rendre lisible, sur « Appareils Push », QUI semble avoir un
 * problème de notifications et POURQUOI.
 *
 * ## La frontière que tout ce fichier défend
 *
 * Deux axes, jamais confondus :
 *
 * - l'ABONNEMENT se juge sur ses propres colonnes (`created_env`,
 *   `vapid_key_hash`) : éligible, clé incompatible, à vérifier ;
 * - le COMPTE se juge sur ses livraisons : un incident Web Push dans la
 *   fenêtre de 30 jours.
 *
 * `member_notification_deliveries` ne porte AUCUN identifiant d'abonnement
 * (clé `(notification_id, channel)`). Un incident ne peut donc jamais être
 * imputé à un appareil, et l'écran doit porter l'indicateur sur TOUTES les
 * lignes du compte — c'est ce que vérifie
 * `test_l_incident_de_compte_marque_TOUTES_les_lignes_du_compte`.
 *
 * ## Pas de seuil d'ancienneté
 *
 * MASTER a explicitement écarté le seuil de 45 jours que j'avais proposé :
 * `last_seen_at` s'AFFICHE, il ne CLASSE pas. Un appareil silencieux depuis
 * des mois peut être parfaitement joignable — rien ne permet d'en décider.
 */
class TASK1823PushDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.public_key', 'clef-courante-t1823');

        $this->org = Organization::factory()->create(['is_active' => true]);
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
            'email' => 'superadmin-t1823-'.bin2hex(random_bytes(4)).'@exemple.com',
        ]);
    }

    private function membre(?Organization $org = null): User
    {
        return User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
    }

    /** Un abonnement dans la situation voulue, posé sans passer par la réconciliation. */
    private function abonnement(User $proprietaire, string $situation): PushSubscription
    {
        $abonnement = app(PushSubscriptionStore::class)->revendiquer(
            $proprietaire,
            'https://fcm.googleapis.com/fcm/send/t1823-'.bin2hex(random_bytes(8)),
            str_repeat('A', 87),
            str_repeat('B', 22),
            'Android',
        );

        $etats = [
            PushSubscriptionStore::SITUATION_ELIGIBLE => [
                'created_env' => (string) config('app.env'),
                'vapid_key_hash' => PushSubscription::empreinteCleVapid(),
            ],
            PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE => [
                'created_env' => (string) config('app.env'),
                'vapid_key_hash' => hash('sha256', 'une-clef-qui-n-a-plus-cours'),
            ],
            // « À vérifier » par l'ORIGINE : stampé sur un autre environnement.
            'a_verifier_autre_env' => [
                'created_env' => 'production',
                'vapid_key_hash' => hash('sha256', 'clef-de-production'),
            ],
            // « À vérifier » par l'EMPREINTE : antérieur à la colonne.
            'a_verifier_sans_empreinte' => [
                'created_env' => (string) config('app.env'),
                'vapid_key_hash' => null,
            ],
        ];

        $abonnement->forceFill($etats[$situation])->save();

        return $abonnement->fresh();
    }

    /** Une livraison Web Push terminale pour ce compte, datée. */
    private function livraison(User $destinataire, string $statut, int $ilYAJours = 0): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $destinataire->id,
            'status' => 'active',
        ]);

        $notification = MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $destinataire->id,
            'notification_key' => NotificationCatalogue::LOOP_MEMBER_ADDED,
            'event_id' => (string) Str::uuid(),
            'object_type' => NotificationCatalogue::OBJECT_LOOP,
            'object_id' => (string) $loop->id,
        ]);

        $livraison = MemberNotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
        ]);

        // La machine d'états interdit `pending -> failed` : on emprunte le
        // même chemin que le livreur.
        $livraison->forceFill(['status' => 'sending', 'claimed_at' => now(), 'attempts' => 1])->save();
        $livraison->forceFill(['status' => $statut, 'diagnostic' => 'test_t1823'])->save();

        // `updated_at` porte la date de l'incident : c'est elle que la
        // fenêtre de 30 jours interroge. Écrite hors Eloquent pour ne pas
        // être écrasée par l'horodatage automatique.
        \Illuminate\Support\Facades\DB::table('member_notification_deliveries')
            ->where('id', $livraison->id)
            ->update(['updated_at' => now()->subDays($ilYAJours)]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. Les compteurs du bento
    // ═══════════════════════════════════════════════════════════════════

    public function test_les_compteurs_separent_les_trois_situations_d_abonnement(): void
    {
        $this->abonnement($this->membre(), PushSubscriptionStore::SITUATION_ELIGIBLE);
        $this->abonnement($this->membre(), PushSubscriptionStore::SITUATION_ELIGIBLE);
        $this->abonnement($this->membre(), PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE);
        $this->abonnement($this->membre(), 'a_verifier_autre_env');
        $this->abonnement($this->membre(), 'a_verifier_sans_empreinte');

        $compteurs = app(PushSubscriptionStore::class)->compteursDeSituation();

        $this->assertSame(5, $compteurs['total']);
        $this->assertSame(2, $compteurs['eligibles']);
        $this->assertSame(1, $compteurs['cles_incompatibles']);
        $this->assertSame(2, $compteurs['a_verifier']);
    }

    public function test_les_trois_situations_sont_exhaustives_et_disjointes(): void
    {
        foreach ([PushSubscriptionStore::SITUATION_ELIGIBLE, PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE, 'a_verifier_autre_env', 'a_verifier_sans_empreinte'] as $situation) {
            $this->abonnement($this->membre(), $situation);
        }

        $compteurs = app(PushSubscriptionStore::class)->compteursDeSituation();

        $this->assertSame(
            $compteurs['total'],
            $compteurs['eligibles'] + $compteurs['cles_incompatibles'] + $compteurs['a_verifier'],
            'Aucun abonnement ne doit echapper aux trois situations, ni etre compte deux fois.',
        );
    }

    public function test_un_compte_a_PLUSIEURS_appareils_n_est_compte_qu_une_fois(): void
    {
        $proprietaire = $this->membre();
        $this->abonnement($proprietaire, PushSubscriptionStore::SITUATION_ELIGIBLE);
        $this->abonnement($proprietaire, PushSubscriptionStore::SITUATION_ELIGIBLE);
        $this->abonnement($proprietaire, PushSubscriptionStore::SITUATION_ELIGIBLE);

        $this->livraison($proprietaire, 'failed', 2);
        $this->livraison($proprietaire, 'failed', 5);

        $compteurs = app(PushSubscriptionStore::class)->compteursDeSituation();

        $this->assertSame(3, $compteurs['total'], 'Trois appareils.');
        $this->assertSame(
            1,
            $compteurs['comptes_en_incident'],
            'Le compteur d\'incidents compte des COMPTES, pas des appareils ni des livraisons.',
        );
    }

    public function test_sans_clef_servie_rien_n_est_declare_eligible(): void
    {
        config()->set('webpush.vapid.public_key', '');

        $this->abonnement($this->membre(), PushSubscriptionStore::SITUATION_ELIGIBLE);
        $this->abonnement($this->membre(), PushSubscriptionStore::SITUATION_ELIGIBLE);

        $compteurs = app(PushSubscriptionStore::class)->compteursDeSituation();

        $this->assertSame(0, $compteurs['eligibles']);
        $this->assertSame(0, $compteurs['cles_incompatibles']);
        $this->assertSame(2, $compteurs['a_verifier'], 'Sans clef a comparer, on ne conclut rien : tout est « a verifier ».');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. La fenêtre de 30 jours — la MÊME partout
    // ═══════════════════════════════════════════════════════════════════

    public function test_un_incident_hors_fenetre_ne_compte_pas(): void
    {
        $recent = $this->membre();
        $ancien = $this->membre();

        $this->abonnement($recent, PushSubscriptionStore::SITUATION_ELIGIBLE);
        $this->abonnement($ancien, PushSubscriptionStore::SITUATION_ELIGIBLE);

        $this->livraison($recent, 'failed', 29);
        $this->livraison($ancien, 'failed', 31);

        $ids = app(PushSubscriptionStore::class)->idsDesComptesAvecIncidentRecent();

        $this->assertContains((string) $recent->id, $ids);
        $this->assertNotContains((string) $ancien->id, $ids, 'La fenetre est de 30 jours, et elle doit mordre.');
    }

    public function test_un_skipped_n_est_PAS_un_incident(): void
    {
        $membre = $this->membre();
        $this->abonnement($membre, PushSubscriptionStore::SITUATION_ELIGIBLE);

        $this->livraison($membre, 'skipped_preference', 1);
        $this->livraison($membre, 'skipped_unreachable', 1);

        $this->assertSame(
            [],
            app(PushSubscriptionStore::class)->idsDesComptesAvecIncidentRecent(),
            'Un `skipped_*` est une DECISION (preference coupee, destinataire injoignable), pas un incident.',
        );
    }

    public function test_un_ambiguous_EST_un_incident(): void
    {
        $membre = $this->membre();
        $this->abonnement($membre, PushSubscriptionStore::SITUATION_ELIGIBLE);
        $this->livraison($membre, 'ambiguous', 1);

        $this->assertContains(
            (string) $membre->id,
            app(PushSubscriptionStore::class)->idsDesComptesAvecIncidentRecent(),
            '`ambiguous` signifie « on ne sait plus si c\'est parti » : cela appelle une verification humaine.',
        );
    }

    public function test_un_incident_sur_un_AUTRE_canal_ne_compte_pas(): void
    {
        $membre = $this->membre();
        $this->abonnement($membre, PushSubscriptionStore::SITUATION_ELIGIBLE);

        $loop = Loop::factory()->create(['organization_id' => $this->org->id, 'created_by' => $membre->id, 'status' => 'active']);
        $notification = MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $membre->id,
            'notification_key' => NotificationCatalogue::LOOP_MEMBER_ADDED,
            'event_id' => (string) Str::uuid(),
            'object_type' => NotificationCatalogue::OBJECT_LOOP,
            'object_id' => (string) $loop->id,
        ]);
        $email = MemberNotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => NotificationCatalogue::CHANNEL_EMAIL,
        ]);
        $email->forceFill(['status' => 'sending', 'claimed_at' => now(), 'attempts' => 1])->save();
        $email->forceFill(['status' => 'failed', 'diagnostic' => 'test_t1823'])->save();

        $this->assertSame(
            [],
            app(PushSubscriptionStore::class)->idsDesComptesAvecIncidentRecent(),
            'Cet ecran parle de Web Push : un echec EMAIL n\'y a pas sa place.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. Les filtres — ils doivent sélectionner ce que les pastilles annoncent
    // ═══════════════════════════════════════════════════════════════════

    public function test_chaque_filtre_de_situation_rend_exactement_ses_lignes(): void
    {
        $eligible = $this->abonnement($this->membre(), PushSubscriptionStore::SITUATION_ELIGIBLE);
        $incompatible = $this->abonnement($this->membre(), PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE);
        $autreEnv = $this->abonnement($this->membre(), 'a_verifier_autre_env');
        $sansEmpreinte = $this->abonnement($this->membre(), 'a_verifier_sans_empreinte');

        $magasin = app(PushSubscriptionStore::class);

        $ids = fn (string $etat) => collect($magasin->tous(null, 25, $etat)->items())->pluck('id')->map(fn ($i) => (string) $i)->all();

        $this->assertSame([(string) $eligible->id], $ids(PushSubscriptionStore::SITUATION_ELIGIBLE));
        $this->assertSame([(string) $incompatible->id], $ids(PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE));

        $aVerifier = $ids(PushSubscriptionStore::SITUATION_A_VERIFIER);
        sort($aVerifier);
        $attendu = [(string) $autreEnv->id, (string) $sansEmpreinte->id];
        sort($attendu);
        $this->assertSame($attendu, $aVerifier);
    }

    public function test_le_filtre_d_incident_rend_TOUTES_les_lignes_du_compte(): void
    {
        $enIncident = $this->membre();
        $sain = $this->membre();

        $a = $this->abonnement($enIncident, PushSubscriptionStore::SITUATION_ELIGIBLE);
        $b = $this->abonnement($enIncident, PushSubscriptionStore::SITUATION_ELIGIBLE);
        $this->abonnement($sain, PushSubscriptionStore::SITUATION_ELIGIBLE);

        $this->livraison($enIncident, 'failed', 3);

        $lignes = app(PushSubscriptionStore::class)->tous(null, 25, PushSubscriptionStore::SITUATION_INCIDENT_COMPTE);
        $ids = collect($lignes->items())->pluck('id')->map(fn ($i) => (string) $i)->all();
        sort($ids);
        $attendu = [(string) $a->id, (string) $b->id];
        sort($attendu);

        $this->assertSame(
            $attendu,
            $ids,
            'L\'incident appartient au COMPTE : ses DEUX appareils remontent, sans qu\'aucun soit designe coupable.',
        );
    }

    public function test_le_filtre_par_organization_borne_aux_membres_de_celle_ci(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);

        $ici = $this->abonnement($this->membre(), PushSubscriptionStore::SITUATION_ELIGIBLE);
        $ailleurs = $this->abonnement($this->membre($autreOrg), PushSubscriptionStore::SITUATION_ELIGIBLE);

        $lignes = app(PushSubscriptionStore::class)->tous(null, 25, null, (string) $this->org->id);
        $ids = collect($lignes->items())->pluck('id')->map(fn ($i) => (string) $i)->all();

        $this->assertSame([(string) $ici->id], $ids);
        $this->assertNotContains((string) $ailleurs->id, $ids);
    }

    public function test_les_filtres_se_COMBINENT_avec_la_recherche(): void
    {
        $cible = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Alpha Zebra']);
        $autre = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Beta Yak']);

        $attendu = $this->abonnement($cible, PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE);
        $this->abonnement($cible, PushSubscriptionStore::SITUATION_ELIGIBLE);
        $this->abonnement($autre, PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE);

        $lignes = app(PushSubscriptionStore::class)->tous(
            'Alpha Zebra',
            25,
            PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE,
            (string) $this->org->id,
        );

        $ids = collect($lignes->items())->pluck('id')->map(fn ($i) => (string) $i)->all();

        $this->assertSame([(string) $attendu->id], $ids, 'q + etat + org doivent se composer, pas se remplacer.');
    }

    public function test_les_filtres_survivent_a_la_pagination(): void
    {
        $membre = $this->membre();

        for ($i = 0; $i < 3; $i++) {
            $this->abonnement($membre, PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE);
        }
        $this->abonnement($membre, PushSubscriptionStore::SITUATION_ELIGIBLE);

        $page = app(PushSubscriptionStore::class)->tous(null, 2, PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE);

        $this->assertSame(3, $page->total(), 'Le total pagine doit refleter le FILTRE, pas la table entiere.');
        $this->assertCount(2, $page->items());
    }

    public function test_un_etat_inconnu_ne_rend_rien_plutot_que_tout(): void
    {
        $this->abonnement($this->membre(), PushSubscriptionStore::SITUATION_ELIGIBLE);

        $page = app(PushSubscriptionStore::class)->tous(null, 25, 'situation-inventee');

        $this->assertSame(
            0,
            $page->total(),
            'Un parametre non reconnu doit fermer, jamais ouvrir : sinon un filtre mal ecrit rendrait la table entiere.',
        );
    }

    public function test_la_liste_des_organizations_ne_contient_que_celles_qui_ont_des_abonnements(): void
    {
        $avec = $this->org;
        $sans = Organization::factory()->create(['is_active' => true]);

        $this->abonnement($this->membre($avec), PushSubscriptionStore::SITUATION_ELIGIBLE);

        $ids = app(PushSubscriptionStore::class)->organizationsAvecAbonnements()->pluck('id')->map(fn ($i) => (string) $i)->all();

        $this->assertContains((string) $avec->id, $ids);
        $this->assertNotContains((string) $sans->id, $ids);
    }
}
