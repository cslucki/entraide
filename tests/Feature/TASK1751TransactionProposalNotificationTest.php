<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1751 (CDC `TODO/SPECS/261104-Notification-entraide.md`) — repondre a
 * une annonce previent son auteur.
 *
 * ## Ce que ce fichier garde, par ordre d'importance
 *
 * 1. **LE destinataire.** Les deux boutons passent par le MEME `store()`, et
 *    les roles s'y INVERSENT : cote service l'acteur devient `buyer`, cote
 *    demande il devient `seller` et `$buyer` est REASSIGNE a l'auteur de
 *    l'annonce. Notifier l'acteur au lieu de sa contrepartie est le defaut le
 *    plus probable de cette TASK, et le plus silencieux — la notification
 *    partirait, simplement a la mauvaise personne. Les deux sens sont donc
 *    testes separement.
 * 2. **La cible** mene a la CONVERSATION, ou la decision se prend.
 * 3. **L'idempotence** : une double soumission ne notifie qu'une fois.
 * 4. **L'acteur n'est jamais notifie de son propre geste.**
 */
class TASK1751TransactionProposalNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteurDeLAnnonce;

    private User $repondant;

    private Category $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1751',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->auteurDeLAnnonce = User::factory()->create([
            'organization_id' => $this->organization->id,
            'points_balance' => 500,
        ]);

        $this->repondant = User::factory()->create([
            'organization_id' => $this->organization->id,
            'points_balance' => 500,
        ]);

        $this->categorie = Category::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    /** @return array<int, MemberNotification> */
    private function notifications(): array
    {
        return MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::TRANSACTION_PROPOSAL_CREATED)
            ->get()
            ->all();
    }

    private function service(): Service
    {
        return Service::factory()->create([
            'user_id' => $this->auteurDeLAnnonce->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie->id,
            'status' => 'active',
            'points_cost' => 10,
        ]);
    }

    private function demande(): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'user_id' => $this->auteurDeLAnnonce->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie->id,
            'status' => 'open',
        ]);
    }

    private function repondreAuService(Service $service): void
    {
        $this->actingAs($this->repondant)
            ->post(route('organization.transactions.store', ['organization' => $this->organization->slug]), [
                'service_id' => $service->id,
                'points_proposed' => 10,
            ]);
    }

    private function repondreALaDemande(ServiceRequest $demande): void
    {
        $this->actingAs($this->repondant)
            ->post(route('organization.transactions.store', ['organization' => $this->organization->slug]), [
                'request_id' => $demande->id,
                'points_proposed' => 10,
            ]);
    }

    // ── 1. LE destinataire, dans les DEUX sens ─────────────────────────────

    /**
     * Cote SERVICE : l'acteur devient `buyer`, le notifie est `$service->user`.
     */
    public function test_repondre_a_un_SERVICE_previent_celui_qui_l_a_publie(): void
    {
        $this->repondreAuService($this->service());

        $notifications = $this->notifications();

        $this->assertCount(1, $notifications, 'Une proposition sur un service doit emettre UNE notification.');
        $this->assertSame($this->auteurDeLAnnonce->id, $notifications[0]->recipient_id,
            'Le notifie doit etre celui qui a publie le service, pas celui qui vient de repondre.');
        $this->assertSame($this->repondant->id, $notifications[0]->actor_id);
    }

    /**
     * Cote DEMANDE : l'acteur devient `seller`, et `$buyer` est REASSIGNE a
     * l'auteur de la demande quelques lignes plus haut dans `store()`.
     *
     * C'est LE piege de cette TASK. Sans ce test, une inversion ne se verrait
     * que sur le sens non teste — et personne ne le remarquerait avant une
     * plainte en production.
     */
    public function test_repondre_a_une_DEMANDE_previent_celui_qui_l_a_publiee(): void
    {
        $this->repondreALaDemande($this->demande());

        $notifications = $this->notifications();

        $this->assertCount(1, $notifications, 'Une proposition sur une demande doit emettre UNE notification.');
        $this->assertSame($this->auteurDeLAnnonce->id, $notifications[0]->recipient_id,
            'Le notifie doit etre l\'auteur de la demande — `$buyer` est reassigne juste avant.');
        $this->assertSame($this->repondant->id, $notifications[0]->actor_id);
    }

    /**
     * La RECIPROQUE des deux gardes ci-dessus : celui qui clique n'est JAMAIS
     * notifie. Sans elle, un code qui notifierait TOUT LE MONDE passerait les
     * deux tests precedents.
     */
    public function test_celui_qui_repond_n_est_jamais_notifie(): void
    {
        $this->repondreAuService($this->service());
        $this->repondreALaDemande($this->demande());

        foreach ($this->notifications() as $notification) {
            $this->assertNotSame($this->repondant->id, $notification->recipient_id,
                'Celui qui vient de cliquer a ete notifie de son propre geste.');
        }

        $this->assertCount(2, $this->notifications());
    }

    // ── 2. La cible ────────────────────────────────────────────────────────

    /**
     * Le lien mene a la CONVERSATION, pas a la fiche de l'annonce.
     *
     * C'est la que « Accepter » et « Refuser » sont rendus. Renvoyer vers le
     * service ferait relire au destinataire sa propre annonce.
     */
    public function test_le_lien_mene_a_la_conversation_de_l_echange(): void
    {
        $this->repondreAuService($this->service());

        $notification = $this->notifications()[0];
        $transaction = Transaction::withoutGlobalScopes()->firstOrFail();

        $cible = app(NotificationTargetResolver::class)->resolve($notification);

        $this->assertNotNull($cible, 'Sans branche `OBJECT_TRANSACTION`, la livraison email conclurait `skipped_unreachable`.');
        $this->assertStringContainsString((string) $transaction->id, $cible);
        $this->assertStringContainsString('/messages', $cible);
    }

    /**
     * Un tiers etranger a l'echange n'obtient AUCUN lien.
     *
     * La cible est resolue au moment du CLIC, pas de l'emission : la garde
     * d'appartenance doit etre relue la, sinon on fabriquerait un lien vers
     * une conversation ou la personne se ferait refuser a l'arrivee.
     */
    public function test_un_tiers_n_obtient_aucun_lien(): void
    {
        $this->repondreAuService($this->service());

        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        $notification = $this->notifications()[0];
        $notification->recipient_id = $etranger->id;

        $this->assertNull(app(NotificationTargetResolver::class)->resolve($notification));
    }

    /** Et une notification sans objet ne resout rien plutot que de lever. */
    public function test_une_notification_sans_objet_ne_resout_rien(): void
    {
        $this->repondreAuService($this->service());

        $notification = $this->notifications()[0];
        $notification->object_id = null;

        $this->assertNull(app(NotificationTargetResolver::class)->resolve($notification));
    }

    // ── 3. L'idempotence ───────────────────────────────────────────────────

    /**
     * Une double soumission ne notifie qu'une fois.
     *
     * Protege par l'idempotence sur `(event_id, recipient_id)`, et c'est
     * pourquoi `eventId` vaut l'id de la Transaction : ici l'evenement et
     * l'objet coincident, une Transaction n'etant creee qu'une fois.
     *
     * La seconde soumission est de toute facon refusee par la garde « vous
     * avez deja une transaction en cours » — ce test verifie que le compte
     * reste a 1 quelle que soit celle des deux protections qui agit.
     */
    public function test_une_double_soumission_ne_notifie_qu_une_fois(): void
    {
        $service = $this->service();

        $this->repondreAuService($service);
        $this->repondreAuService($service);

        $this->assertCount(1, $this->notifications());
    }

    // ── 4. Le catalogue ────────────────────────────────────────────────────

    /**
     * Les reglages de canaux, et surtout celui qui ne se coupe pas.
     *
     * `in_app` non configurable : on ne se desabonne pas d'apprendre qu'on a
     * une decision a prendre. Une preference contraire ecrite a la main en
     * base doit rester sans effet (doctrine du catalogue).
     */
    public function test_les_canaux_declares_sont_ceux_decides(): void
    {
        $definition = NotificationCatalogue::definition(NotificationCatalogue::TRANSACTION_PROPOSAL_CREATED);

        $this->assertNotNull($definition, 'La cle doit exister au catalogue.');
        $this->assertSame(NotificationCatalogue::INTENTION_SOLLICITATION_DIRECTE, $definition['intention']);
        $this->assertSame(NotificationCatalogue::OBJECT_TRANSACTION, $definition['object_type']);

        $canaux = $definition['channels'];

        $this->assertFalse($canaux[NotificationCatalogue::CHANNEL_IN_APP]['configurable'],
            'Le canal in-app ne doit pas pouvoir etre coupe.');
        $this->assertTrue($canaux[NotificationCatalogue::CHANNEL_IN_APP]['default']);

        $this->assertTrue($canaux[NotificationCatalogue::CHANNEL_EMAIL]['default']);
        $this->assertTrue($canaux[NotificationCatalogue::CHANNEL_EMAIL]['configurable']);

        $this->assertTrue($canaux[NotificationCatalogue::CHANNEL_WEB_PUSH]['default']);
        $this->assertTrue($canaux[NotificationCatalogue::CHANNEL_WEB_PUSH]['configurable']);
    }

    // ── 5. Le courriel vient du gabarit ADMINISTRABLE ──────────────────────

    /**
     * LA garde qui realise la demande de Cyril.
     *
     * Il voulait que le modele soit visible et modifiable dans
     * `/admin/system-email-templates`. Il ne suffit donc pas qu'un email
     * parte : il doit venir du GABARIT, pas du repli en fichier de langue.
     *
     * `fallback_reason` est l'instrument exact — c'est le champ que le moteur
     * renseigne quand il n'a pas trouve le gabarit. Sans cette assertion, un
     * gabarit absent du seeder, desactive, ou mal nomme enverrait le texte de
     * secours pendant des mois sans que rien ne rougisse : les deux textes se
     * ressemblent assez pour qu'une lecture humaine n'y voie rien.
     */
    public function test_le_courriel_vient_du_GABARIT_et_non_du_repli(): void
    {
        $service = $this->service();
        $service->update(['title' => 'Relire un dossier 1751']);

        $this->repondreAuService($service);

        $transaction = Transaction::withoutGlobalScopes()->firstOrFail();

        $rendu = app(\App\Services\Transactions\TransactionProposalMailer::class)->compose(
            $transaction,
            $this->auteurDeLAnnonce,
            $this->repondant,
            'https://exemple.test/echange',
            'fr',
        );

        $this->assertNull($rendu['fallback_reason'],
            'Le courriel est tombe sur le repli : le gabarit administrable est introuvable ou desactive.');

        // Le gabarit est bien INTERPOLE, et pas rendu avec ses accolades.
        $this->assertStringContainsString('Relire un dossier 1751', $rendu['subject']);
        $this->assertStringNotContainsString('{{', $rendu['subject']);
        $this->assertStringNotContainsString('{{', $rendu['body']);
        $this->assertStringContainsString('https://exemple.test/echange', $rendu['body']);
    }

    /** Le gabarit existe dans les DEUX langues, en portee Globale. */
    public function test_le_gabarit_est_pose_en_global_dans_les_deux_langues(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->assertDatabaseHas('system_email_templates', [
                'slug' => \App\Services\Transactions\TransactionProposalMailer::SLUG,
                'locale' => $locale,
                'organization_id' => null,
                'enabled' => true,
            ]);
        }
    }

    /**
     * Le repli EXISTE et fonctionne — sinon la garde ci-dessus ne protegerait
     * rien d'utile : un gabarit retire par un SuperAdmin laisserait le
     * courriel sans contenu du tout.
     */
    public function test_le_repli_rend_un_contenu_quand_le_gabarit_est_retire(): void
    {
        $service = $this->service();
        $this->repondreAuService($service);

        \App\Models\SystemEmailTemplate::query()
            ->where('slug', \App\Services\Transactions\TransactionProposalMailer::SLUG)
            ->delete();

        $rendu = app(\App\Services\Transactions\TransactionProposalMailer::class)->compose(
            Transaction::withoutGlobalScopes()->firstOrFail(),
            $this->auteurDeLAnnonce,
            $this->repondant,
            'https://exemple.test/echange',
            'fr',
        );

        $this->assertNotNull($rendu['fallback_reason'], 'Le retrait du gabarit doit etre DIAGNOSTIQUE, pas silencieux.');
        $this->assertNotEmpty($rendu['subject']);
        $this->assertStringContainsString('https://exemple.test/echange', $rendu['body']);
    }

    /**
     * Le texte du Push ne nomme NI la personne NI l'annonce.
     *
     * Il s'affiche sur un ecran VERROUILLE (CDC §6.4/6.5). La garde porte sur
     * l'absence de variable d'interpolation : un texte qui en porterait une
     * pourrait, demain, rendre un nom.
     */
    public function test_le_texte_du_push_ne_nomme_personne(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (['push_title', 'push_body'] as $partie) {
                $texte = __('notifications.'.$partie.'.transaction_proposal_created');

                $this->assertStringNotContainsString(':', $texte,
                    "Le {$partie} ({$locale}) porte une variable : il pourrait nommer quelqu'un sur un ecran verrouille.");
                $this->assertStringNotContainsString('{{', $texte);
            }
        }
    }
}
