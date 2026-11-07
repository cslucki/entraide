<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use App\Support\Notifications\NotificationTargetResolver;
use App\Support\Notifications\NotificationWebPushDeliverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1779 — le livreur Web Push ne doit plus faire tomber la publication.
 *
 * ## Le defaut, et comment il a ete trouve
 *
 * `NotificationEmailDeliverer::deliver()` enveloppe son travail dans un
 * `catch (Throwable)` et conclut `failed` sans relancer.
 * `NotificationWebPushDeliverer::deliver()` **n'avait aucun `try/catch`**.
 * L'asymetrie a ete trouvee en T1778, en verifiant une affirmation que
 * j'avais faite trop vite (« une erreur SQL pendant le fan-out remonte dans
 * la requete ») : elle etait fausse pour l'email, et vraie pour le push.
 *
 * Consequence sous `QUEUE_CONNECTION=sync`, qui est l'etat de la PROD :
 * `SyncQueue::handleException()` termine par `throw $e`. Une erreur interne du
 * chemin push atteignait donc la requete HTTP de l'AUTEUR — qui voyait une 500
 * alors que son message etait publie — et la livraison restait bloquee en
 * `sending`, donc jamais reprise, puisque `prendre()` n'accepte que `pending`.
 *
 * ## La frontiere, et pourquoi elle n'est pas decorative
 *
 * | moment de l'incident | statut | ce que cela autorise |
 * |---|---|---|
 * | AVANT le premier `deposer()` | `failed` | on SAIT que rien n'est parti : une relance manuelle est sans risque |
 * | APRES le premier `deposer()` | **`ambiguous`** | le fournisseur a peut-etre accepte : **relancer est interdit** |
 *
 * Confondre les deux serait le pire des deux mondes : `failed` partout
 * inviterait a renvoyer un message deja parti, `ambiguous` partout
 * interdirait de rattraper un echec franc.
 *
 * ## Aucun envoi externe
 *
 * `Http::fake()` partout. Les deux incidents sont provoques par les
 * mecanismes de production eux-memes — une substitution de resolveur dans le
 * conteneur, un ecouteur `deleting` sur le modele — jamais par une doublure du
 * livreur teste : une doublure ecrite avec les memes hypotheses que le code
 * teste ne prouverait rien.
 */
class TASK1779WebPushDelivererRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteur;

    private User $membre;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ PAS de `Http::fake()` ici. Deux appels a `Http::fake()`
        // FUSIONNENT leurs stubs et c'est le PREMIER qui repond : un fake pose
        // en `setUp()` rendrait inoperant tout `Http::fake()` d'un test
        // voulant une autre reponse. Le defaut s'est produit en ecrivant ce
        // fichier — le test du 410 recevait 201 et passait pour la mauvaise
        // raison. Chaque test declare donc SA reponse, une seule fois.

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        $this->boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'status' => 'active',
        ]);

        foreach ([$this->auteur, $this->membre] as $personne) {
            LoopMember::create([
                'loop_id' => $this->boucle->id,
                'user_id' => $personne->id,
                'organization_id' => $this->organization->id,
                'role' => $personne->is($this->auteur) ? 'owner' : 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $this->poserLaPaireVapidDuServeur();
    }

    // =====================================================================
    // 1. Un incident AVANT le transport : `failed`, et rien ne remonte
    // =====================================================================

    /**
     * **La garde centrale.** `deliver()` ne doit plus jamais relancer.
     *
     * L'incident est provoque la ou il peut reellement survenir : le resolveur
     * de cibles, substitue dans le conteneur par une version qui leve. C'est
     * le vrai chemin d'appel (`app(NotificationTargetResolver::class)` dans
     * `livrer()`), pas un detournement du livreur.
     */
    public function test_un_incident_AVANT_le_transport_conclut_failed_sans_relancer(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $this->abonnerUnAppareil();
        $notification = $this->notificationPush();
        $livraison = $this->livraisonPush($notification);

        $this->substituerLeResolveurDeCiblesPourQuIlLeve();

        // Aucune exception ne doit sortir d'ici.
        app(NotificationWebPushDeliverer::class)->deliver((string) $notification->id);

        $livraison->refresh();

        $this->assertSame(NotificationDeliveryStatus::FAILED, $livraison->status);
        $this->assertSame('delivery_internal_error', $livraison->diagnostic);
        Http::assertNothingSent();
    }

    /** Le diagnostic est un CODE STABLE, jamais le message d'exception. */
    public function test_le_diagnostic_ne_contient_jamais_le_message_d_exception(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $this->abonnerUnAppareil();
        $notification = $this->notificationPush();
        $livraison = $this->livraisonPush($notification);

        $this->substituerLeResolveurDeCiblesPourQuIlLeve('fragment-de-requete-SQL-a-ne-jamais-stocker');

        app(NotificationWebPushDeliverer::class)->deliver((string) $notification->id);

        $diagnostic = (string) $livraison->refresh()->diagnostic;

        $this->assertStringNotContainsString('fragment-de-requete-SQL', $diagnostic);
        $this->assertLessThanOrEqual(120, mb_strlen($diagnostic), 'La colonne a une borne : un message de moteur la depasserait.');
    }

    // =====================================================================
    // 2. Un incident APRES le transport : `ambiguous`, JAMAIS `failed`
    // =====================================================================

    /**
     * **La frontiere, mesuree.**
     *
     * Le fournisseur rend 410 (« cet endpoint n'existe plus »), ce qui
     * declenche `$appareil->delete()` — donc APRES que `deposer()` a ete
     * appele. Un ecouteur `deleting` fait echouer cette suppression.
     *
     * Le transport a ete sollicite : on ne peut plus affirmer que rien n'est
     * parti. `ambiguous` est le seul statut honnete.
     */
    public function test_un_incident_APRES_le_transport_conclut_ambiguous_et_non_failed(): void
    {
        Http::fake(['*' => Http::response('', 410)]);

        $this->abonnerUnAppareil();
        $notification = $this->notificationPush();
        $livraison = $this->livraisonPush($notification);

        PushSubscription::deleting(function (): void {
            throw new RuntimeException('suppression refusee');
        });

        app(NotificationWebPushDeliverer::class)->deliver((string) $notification->id);

        $livraison->refresh();

        $this->assertSame(
            NotificationDeliveryStatus::AMBIGUOUS,
            $livraison->status,
            'Le transport avait ete sollicite : conclure `failed` inviterait a renvoyer un message peut-etre parti.'
        );
        $this->assertSame('push_outcome_unknown', $livraison->diagnostic);
    }

    /** Une livraison ambigue n'est JAMAIS renvoyee automatiquement. */
    public function test_une_livraison_ambigue_n_est_jamais_renvoyee(): void
    {
        $this->abonnerUnAppareil();
        $notification = $this->notificationPush();
        $livraison = $this->livraisonPush($notification);

        // `pending -> ambiguous` est REFUSEE par le modele : seule
        // `sending -> ambiguous` existe (`NotificationDeliveryStatus::TRANSITIONS`).
        // Reproduire l'etat reel exige donc de passer par `sending`, ce qui est
        // exactement le chemin qu'un worker suit. Forcer la transition
        // interdite ferait lever la garde du modele et le test mesurerait
        // cette garde au lieu du rejeu.
        $livraison->forceFill(['status' => NotificationDeliveryStatus::SENDING])->save();
        $livraison->forceFill([
            'status' => NotificationDeliveryStatus::AMBIGUOUS,
            'diagnostic' => 'push_outcome_unknown',
        ])->save();

        Http::fake(['*' => Http::response('', 201)]);

        app(NotificationWebPushDeliverer::class)->deliver((string) $notification->id);

        Http::assertNothingSent();
        $this->assertSame(
            NotificationDeliveryStatus::AMBIGUOUS,
            $livraison->refresh()->status,
            'La prise atomique n accepte que `pending` : une ambigue reste ambigue.'
        );
    }

    // =====================================================================
    // 3. La publication HTTP survit a l'incident
    // =====================================================================

    /**
     * **Ce que le defaut coutait vraiment.**
     *
     * Sous `sync`, publier une demande d'aide livre le push EN LIGNE. Avant
     * cette TASK, un incident interne du chemin push remontait jusqu'ici :
     * l'auteur voyait une erreur alors que son message etait publie, et la
     * transaction du producteur etait deja commitee (`afterCommit`).
     *
     * Le message doit etre publie, la notification in-app creee, et la
     * livraison push conclue — sans exception.
     */
    public function test_la_publication_survit_a_un_incident_du_chemin_push(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $this->abonnerUnAppareil();
        $this->substituerLeResolveurDeCiblesPourQuIlLeve();

        $message = app(LoopMessageService::class)->sendHelpRequestMessage(
            $this->boucle, $this->auteur, 'J’ai besoin d’un coup de main.',
            'Relecture', 'Critique', 'Cette semaine', 'relecture',
        );

        $this->assertNotNull($message->getKey(), 'Le message doit etre publie malgre l incident du push.');

        $notification = MemberNotification::query()
            ->where('recipient_id', $this->membre->id)
            ->where('notification_key', NotificationCatalogue::LOOP_HELP_REQUEST_CREATED)
            ->first();

        $this->assertNotNull($notification, 'La notification in-app ne depend pas du push.');

        $push = MemberNotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
            ->first();

        $this->assertNotNull($push);
        $this->assertNotSame(
            NotificationDeliveryStatus::SENDING,
            $push->status,
            'Une livraison laissee en `sending` ne serait JAMAIS reprise : `prendre()` n accepte que `pending`.'
        );
    }

    // =====================================================================
    // 4. Les gardes d'avant restent en place
    // =====================================================================

    /** Le cas passant n'a pas bouge : un appareil abonne recoit son push. */
    public function test_le_cas_passant_envoie_toujours(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $this->abonnerUnAppareil();
        $notification = $this->notificationPush();
        $livraison = $this->livraisonPush($notification);

        app(NotificationWebPushDeliverer::class)->deliver((string) $notification->id);

        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->refresh()->status);
        Http::assertSentCount(1);
    }

    /** Le depart de l'Organization reste `skipped_unreachable`, pas `failed`. */
    public function test_la_garde_de_tenant_rend_toujours_son_propre_statut(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $this->abonnerUnAppareil();
        $notification = $this->notificationPush();
        $livraison = $this->livraisonPush($notification);

        $autre = Organization::factory()->create(['is_active' => true]);
        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $this->membre->id)
            ->update(['organization_id' => $autre->id]);

        app(NotificationWebPushDeliverer::class)->deliver((string) $notification->id);

        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->refresh()->status);
        $this->assertSame('recipient_left_organization', $livraison->diagnostic);
        Http::assertNothingSent();
    }

    // ── Outillage ───────────────────────────────────────────────────────────

    /**
     * Substitue le resolveur de cibles par une version qui leve.
     *
     * C'est le chemin d'appel REEL : `livrer()` fait
     * `app(NotificationTargetResolver::class)->resolve(...)`. Rien du livreur
     * teste n'est detourne.
     */
    private function substituerLeResolveurDeCiblesPourQuIlLeve(string $message = 'base injoignable'): void
    {
        $this->app->bind(NotificationTargetResolver::class, function () use ($message) {
            return new class($message) extends NotificationTargetResolver
            {
                public function __construct(private readonly string $message) {}

                public function resolve(MemberNotification $notification): ?string
                {
                    throw new RuntimeException($this->message);
                }
            };
        });
    }

    /** Une notification push PENDING, planifiee par le vrai chemin. */
    private function notificationPush(): MemberNotification
    {
        Queue::fake();

        app(LoopMessageService::class)->sendHelpRequestMessage(
            $this->boucle, $this->auteur, 'J’ai besoin d’un coup de main.',
            'Relecture', 'Critique', 'Cette semaine', 'relecture',
        );

        return MemberNotification::query()
            ->where('recipient_id', $this->membre->id)
            ->where('notification_key', NotificationCatalogue::LOOP_HELP_REQUEST_CREATED)
            ->firstOrFail();
    }

    private function livraisonPush(MemberNotification $notification): MemberNotificationDelivery
    {
        return MemberNotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
            ->firstOrFail();
    }

    /** Un appareil avec des clefs P-256 REELLES : le chiffrement refuse une valeur bidon. */
    private function abonnerUnAppareil(): void
    {
        $paire = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detail = openssl_pkey_get_details($paire);
        $publique = "\x04"
            .str_pad($detail['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($detail['ec']['y'], 32, "\0", STR_PAD_LEFT);

        app(\App\Services\Push\PushSubscriptionStore::class)->revendiquer(
            $this->membre,
            'https://fcm.googleapis.com/fcm/send/t1779-'.bin2hex(random_bytes(6)),
            self::base64Url($publique),
            self::base64Url(random_bytes(16)),
            'Android',
        );
    }

    private function poserLaPaireVapidDuServeur(): void
    {
        config()->set('webpush.vapid.subject', 'mailto:banc@exemple.test');

        $serveur = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detail = openssl_pkey_get_details($serveur);

        config()->set('webpush.vapid.public_key', self::base64Url(
            "\x04"
            .str_pad($detail['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($detail['ec']['y'], 32, "\0", STR_PAD_LEFT)
        ));
        config()->set('webpush.vapid.private_key', self::base64Url(
            str_pad($detail['ec']['d'], 32, "\0", STR_PAD_LEFT)
        ));
    }

    private static function base64Url(string $binaire): string
    {
        return rtrim(strtr(base64_encode($binaire), '+/', '-_'), '=');
    }
}
