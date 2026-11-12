<?php

namespace Tests\Feature;

use App\Livewire\ViewFeedPost;
use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Feed\FeedCommentMailer;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventEmitter;
use App\Support\Feed\FeedEventRenderer;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationTargetResolver;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1810, correctif 2 (demande Cyril, 12/11) — « sur le flux, faut
 * envoyer une notification par email + app + pwa, quand quelqu'un commente
 * un evenement du flux ».
 *
 * ## Le destinataire est TOUJOURS `$post->user`
 *
 * « On a toujours un emetteur ou quelqu'un qui est evoque, donc c'est cette
 * personne qu'il faut notifier » — mesure dans `FeedEventEmitter::emettre()`
 * et `CreateFeedPost` : `feed_posts.user_id` vaut toujours cette personne,
 * automatique ou manuelle. Une seule regle de ciblage suffit donc pour les
 * deux familles d'item.
 *
 * ## La reaction est UNIQUEMENT in-app
 *
 * « Si la personne fait juste une reaction, la notification est juste dans
 * l'application, pas besoin d'envoyer un email » — premiere cle du
 * catalogue sans canal asynchrone, et c'est ce que ce fichier verifie
 * directement sur `member_notification_deliveries`.
 */
class TASK1810FeedCommentReactionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteur;

    private User $commentateur;

    private FeedPost $annonce;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->auteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->commentateur = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->annonce = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->auteur->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'title' => 'Une annonce a commenter',
            'content' => 'Contenu de l’annonce manuelle.',
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    // ── 1. Le destinataire est l'auteur de l'item ───────────────────────────

    public function test_commenter_une_annonce_manuelle_previent_son_auteur(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->set('commentForms.'.$this->annonce->id, 'Un commentaire.')
            ->call('addComment', $this->annonce->id)
            ->assertOk();

        $this->assertSame(1, MemberNotification::count());

        $notification = MemberNotification::firstOrFail();
        $this->assertSame((string) $this->auteur->id, (string) $notification->recipient_id);
        $this->assertSame(NotificationCatalogue::FEED_POST_COMMENT_CREATED, $notification->notification_key);
        $this->assertSame((string) $this->commentateur->id, (string) $notification->actor_id);
    }

    public function test_commenter_sa_propre_annonce_ne_previent_personne(): void
    {
        Livewire::actingAs($this->auteur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->set('commentForms.'.$this->annonce->id, 'Je commente ma propre annonce.')
            ->call('addComment', $this->annonce->id)
            ->assertOk();

        $this->assertSame(0, MemberNotification::count());
    }

    /**
     * L'item evoque un acteur, pas un auteur d'annonce : `$post->user` est
     * l'ACTEUR de l'evenement automatique (mesure dans
     * `FeedEventEmitter::emettre()`), et c'est donc lui qui doit etre
     * prevenu d'un commentaire — jamais le commentateur.
     */
    public function test_commenter_un_evenement_automatique_previent_l_acteur_de_l_evenement(): void
    {
        $presentateur = User::factory()->create(['organization_id' => $this->organization->id]);

        $evenement = app(FeedEventEmitter::class)->emettre(
            FeedEventCatalogue::PRESENTATION_TERMINEE,
            $presentateur,
            $presentateur,
            $this->organization,
        );

        $this->assertNotNull($evenement);
        $this->assertSame((string) $presentateur->id, (string) $evenement->user_id);

        Livewire::actingAs($this->commentateur)
            ->test(ViewFeedPost::class, ['feedPost' => $evenement])
            ->set('commentForms.'.$evenement->id, 'Bravo pour ta presentation !')
            ->call('addComment', $evenement->id)
            ->assertOk();

        $notification = MemberNotification::firstOrFail();
        $this->assertSame((string) $presentateur->id, (string) $notification->recipient_id);
    }

    // ── 2. Les trois canaux pour un commentaire, in-app SEUL pour une reaction ──

    public function test_le_catalogue_declare_les_trois_canaux_pour_un_commentaire(): void
    {
        $cle = NotificationCatalogue::FEED_POST_COMMENT_CREATED;

        $this->assertSame(
            [
                NotificationCatalogue::CHANNEL_IN_APP,
                NotificationCatalogue::CHANNEL_EMAIL,
                NotificationCatalogue::CHANNEL_WEB_PUSH,
            ],
            NotificationCatalogue::channelsFor($cle)
        );
    }

    public function test_commenter_planifie_email_et_web_push_sans_aucun_reglage(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->set('commentForms.'.$this->annonce->id, 'Trois canaux attendus.')
            ->call('addComment', $this->annonce->id);

        $notification = MemberNotification::firstOrFail();

        $canaux = DB::table('member_notification_deliveries')
            ->where('notification_id', $notification->id)
            ->pluck('channel')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([
                NotificationCatalogue::CHANNEL_EMAIL,
                NotificationCatalogue::CHANNEL_WEB_PUSH,
            ])->sort()->values()->all(),
            $canaux
        );
    }

    /**
     * « Pas besoin d'envoyer un email » : AUCUNE ligne de livraison, ni EMAIL
     * ni WEB_PUSH — la preuve du canal application est la ligne
     * `MemberNotification` elle-meme (meme doctrine que
     * `TASK1786EntraideCommentsTest::test_les_trois_canaux_sont_planifies_sans_aucun_reglage`).
     */
    public function test_reagir_cree_une_notification_in_app_uniquement(): void
    {
        $emoji = Reaction::REACTION_TYPES[0];

        Livewire::actingAs($this->commentateur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->call('toggleReaction', $this->annonce->id, $emoji)
            ->assertOk();

        $notification = MemberNotification::firstOrFail();
        $this->assertSame((string) $this->auteur->id, (string) $notification->recipient_id);
        $this->assertSame(NotificationCatalogue::FEED_POST_REACTION_CREATED, $notification->notification_key);

        $this->assertSame(
            0,
            DB::table('member_notification_deliveries')->where('notification_id', $notification->id)->count(),
            'Une reaction ne doit planifier AUCUNE livraison asynchrone (ni email, ni web push).'
        );
    }

    public function test_reagir_sur_sa_propre_annonce_ne_previent_personne(): void
    {
        Livewire::actingAs($this->auteur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->call('toggleReaction', $this->annonce->id, Reaction::REACTION_TYPES[0])
            ->assertOk();

        $this->assertSame(0, MemberNotification::count());
    }

    /**
     * L'identite de l'evenement est (item, personne) — voir le docblock de
     * `prevenirApresReactionFlux()`. Retirer sa reaction (second clic du
     * meme type) puis en poser une NOUVELLE (clic d'un type different) crée
     * deux lignes `Reaction` distinctes, mais ne doit notifier qu'UNE fois.
     */
    public function test_retirer_puis_remettre_une_reaction_ne_duplique_pas_la_notification(): void
    {
        [$premier, $second] = [Reaction::REACTION_TYPES[0], Reaction::REACTION_TYPES[1]];

        $composant = Livewire::actingAs($this->commentateur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->call('toggleReaction', $this->annonce->id, $premier)
            ->assertOk();

        $this->assertSame(1, MemberNotification::count());

        // Retrait (second clic du MEME type) puis nouvelle reaction.
        $composant->call('toggleReaction', $this->annonce->id, $premier)->assertOk();
        $this->assertDatabaseMissing('reactions', ['reactionable_id' => $this->annonce->id, 'user_id' => $this->commentateur->id]);

        $composant->call('toggleReaction', $this->annonce->id, $second)->assertOk();

        $this->assertSame(1, MemberNotification::count(), 'Le va-et-vient ne doit jamais produire une seconde notification.');
    }

    // ── 3. La cible ──────────────────────────────────────────────────────────

    public function test_la_notification_de_commentaire_mene_a_l_item_avec_une_ancre(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->set('commentForms.'.$this->annonce->id, 'Un commentaire cible.')
            ->call('addComment', $this->annonce->id);

        $notification = MemberNotification::firstOrFail();
        $commentaire = FeedPostComment::where('feed_post_id', $this->annonce->id)->firstOrFail();

        $cible = app(NotificationTargetResolver::class)->resolve($notification);

        $this->assertNotNull($cible);
        $this->assertStringContainsString((string) $this->annonce->id, $cible);
        $this->assertStringEndsWith('#commentaire-'.$commentaire->id, $cible);
    }

    public function test_la_notification_de_reaction_mene_a_l_item(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->call('toggleReaction', $this->annonce->id, Reaction::REACTION_TYPES[0]);

        $notification = MemberNotification::firstOrFail();
        $cible = app(NotificationTargetResolver::class)->resolve($notification);

        $this->assertNotNull($cible);
        $this->assertStringContainsString((string) $this->annonce->id, $cible);
    }

    public function test_un_commentaire_supprime_ne_mene_nulle_part(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->set('commentForms.'.$this->annonce->id, 'Je serai retire.')
            ->call('addComment', $this->annonce->id);

        $notification = MemberNotification::firstOrFail();

        FeedPostComment::where('feed_post_id', $this->annonce->id)->first()->update(['is_approved' => false]);

        $this->assertNull(app(NotificationTargetResolver::class)->resolve($notification));
    }

    // ── 4. L'email emploie le gabarit administrable, pas le repli ───────────

    /**
     * Meme raisonnement que `TASK1786EntraideCommentsTest::
     * test_l_email_emploie_le_gabarit_administrable_et_pas_le_repli` : une
     * branche de `NotificationEmailDeliverer` oubliee est SILENCIEUSE. La
     * mesure cherche donc le texte EXACT demande par Cyril, que seul le
     * gabarit administrable produit.
     */
    public function test_l_email_porte_le_sujet_et_le_contexte_demandes(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $this->commentateur->update(['first_name' => 'Alex', 'name' => 'Alex Martin']);

        $commentaire = FeedPostComment::create([
            'feed_post_id' => $this->annonce->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->commentateur->id,
            'content' => 'Un commentaire bien rempli.',
            'is_approved' => true,
        ]);

        $compose = app(FeedCommentMailer::class)->compose(
            $commentaire,
            $this->auteur,
            $this->commentateur,
            'https://exemple.test/cible#commentaire-'.$commentaire->id,
            'fr'
        );

        $this->assertSame('Commentaire sur le flux', $compose['subject']);
        $this->assertStringContainsString('Alex', $compose['body']);
        $this->assertStringContainsString(e($this->annonce->title), $compose['body']);
        $this->assertStringContainsString('Un commentaire bien rempli.', $compose['body']);
    }

    public function test_le_journal_expurge_le_texte_ecrit_par_le_membre(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $commentaire = FeedPostComment::create([
            'feed_post_id' => $this->annonce->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->commentateur->id,
            'content' => 'PHRASE-PRIVEE-DU-MEMBRE',
            'is_approved' => true,
        ]);

        $pourJournal = app(FeedCommentMailer::class)->compose(
            $commentaire,
            $this->auteur,
            $this->commentateur,
            '[action-link-redacted]',
            'fr',
            pourJournal: true
        );

        $this->assertStringNotContainsString('PHRASE-PRIVEE-DU-MEMBRE', $pourJournal['body']);
        $this->assertStringContainsString(FeedCommentMailer::EXTRAIT_EXPURGE, $pourJournal['body']);
    }

    /**
     * Le contexte repris pour un evenement AUTOMATIQUE est EXACTEMENT
     * `FeedEventRenderer::libelle()` — « on reprend ce qui est affiche dans
     * le flux » (demande explicite).
     */
    public function test_le_contexte_d_un_evenement_automatique_reprend_le_libelle_du_flux(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $presentateur = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Sam']);

        $evenement = app(FeedEventEmitter::class)->emettre(
            FeedEventCatalogue::PRESENTATION_TERMINEE,
            $presentateur,
            $presentateur,
            $this->organization,
        );

        $commentaire = FeedPostComment::create([
            'feed_post_id' => $evenement->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->commentateur->id,
            'content' => 'Bravo !',
            'is_approved' => true,
        ]);

        $compose = app(FeedCommentMailer::class)->compose(
            $commentaire,
            $presentateur,
            $this->commentateur,
            'https://exemple.test/cible',
            'fr'
        );

        $libelleAttendu = FeedEventRenderer::libelle($evenement->fresh());

        $this->assertNotNull($libelleAttendu);
        $this->assertStringContainsString(e($libelleAttendu), $compose['body']);
    }

    // ── 5. Cross-tenant : la garde existante du trait tient aussi pour la notif ──

    public function test_commenter_l_annonce_d_une_autre_organization_ne_notifie_personne(): void
    {
        $autre = Organization::factory()->create(['is_active' => true]);
        $etrangere = FeedPost::create([
            'organization_id' => $autre->id,
            'user_id' => User::factory()->create(['organization_id' => $autre->id])->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Annonce etrangere.',
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Livewire::actingAs($this->commentateur)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->set('commentForms.'.$etrangere->id, 'Tentative hors tenant.')
            ->call('addComment', $etrangere->id);

        $this->assertDatabaseMissing('feed_post_comments', ['feed_post_id' => $etrangere->id]);
        $this->assertSame(0, MemberNotification::count());
    }
}
