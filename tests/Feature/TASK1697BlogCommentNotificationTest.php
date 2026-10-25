<?php

namespace Tests\Feature;

use App\Livewire\BlogComments;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1697 volet c — « quand un utilisateur fait un commentaire, il faut que
 * l'auteur de l'article recoive une notification » (Cyril).
 *
 * Canal : **dans l'application** seulement, arbitrage de Cyril. Le catalogue
 * sait faire l'email ; l'ajouter tiendra en une ligne.
 *
 * ## Ce que l'ajout d'un type de notification exige vraiment
 *
 * Pas un `create()`. Quatre pieces, parce que trois autorites refusent ce
 * qu'elles ne declarent pas :
 *
 * 1. une entree au `NotificationCatalogue` (cle, `object_type`, canaux) ;
 * 2. une branche au `NotificationTargetResolver`, **fail-closed** : un
 *    `object_type` inconnu ne mene nulle part ;
 * 3. un libelle `notifications.keys.blog_comment_created` ;
 * 4. l'emission, par `NotificationEmitter` et jamais en direct.
 *
 * ## L'`event_id` est deterministe
 *
 * `uuid5(NAMESPACE_URL, 'bouclepro:notification:<cle>:<commentaire>')` — meme
 * idiome que `LoopService`. Rejouer le producteur sur le meme fait **constate**
 * la premiere notification au lieu d'en creer une seconde.
 */
class TASK1697BlogCommentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteur;

    private User $lecteur;

    private BlogPost $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1697n',
            'is_active' => true,
            'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->auteur = $this->membre();
        $this->lecteur = $this->membre();
        $this->article = $this->articlePublie($this->auteur);
    }

    public function test_l_auteur_est_prevenu_quand_on_commente_son_article(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Beau travail.')
            ->call('addComment');

        $commentaire = BlogComment::firstOrFail();

        $this->assertDatabaseHas('member_notifications', [
            'organization_id' => $this->organization->id,
            'recipient_id' => $this->auteur->id,
            'notification_key' => NotificationCatalogue::BLOG_COMMENT_CREATED,
            'object_type' => NotificationCatalogue::OBJECT_BLOG_COMMENT,
            'object_id' => $commentaire->id,
            'actor_id' => $this->lecteur->id,
        ]);
    }

    /** Se notifier soi-meme est du bruit. */
    public function test_commenter_son_propre_article_ne_previent_personne(): void
    {
        Livewire::actingAs($this->auteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Je complete mon propre article.')
            ->call('addComment');

        $this->assertDatabaseCount('blog_comments', 1);
        $this->assertDatabaseCount('member_notifications', 0);
    }

    public function test_une_reponse_previent_aussi_l_auteur(): void
    {
        $racine = $this->commentaire($this->lecteur, 'Un commentaire.');
        $tiers = $this->membre();

        Livewire::actingAs($tiers)
            ->test(BlogComments::class, ['post' => $this->article])
            ->call('startReply', (string) $racine->id)
            ->set('replyContent', 'Je reponds.')
            ->call('addReply');

        $this->assertDatabaseHas('member_notifications', [
            'recipient_id' => $this->auteur->id,
            'notification_key' => NotificationCatalogue::BLOG_COMMENT_CREATED,
            'actor_id' => $tiers->id,
        ]);
    }

    /**
     * L'auteur a quitte l'Organization : rien n'est emis, rien ne casse, et le
     * commentaire est quand meme ecrit. Les invariants refusent un destinataire
     * hors tenant — c'est au producteur de resoudre l'appartenance AVANT
     * d'appeler, ce que dit le docblock de `NotificationEmitter`.
     */
    public function test_un_auteur_parti_ailleurs_n_est_pas_prevenu_et_rien_ne_casse(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1697m', 'is_active' => true]);
        $this->auteur->forceFill(['organization_id' => $autreOrg->id])->saveQuietly();

        // TASK-1697 — pourquoi le journal est surveille ici.
        //
        // Mesure par sabotage : retirer la pre-verification d'appartenance ne
        // changeait RIEN aux deux comptages ci-dessous. Les invariants de
        // l'emetteur refusent le destinataire hors tenant de toute facon, et le
        // `catch (\Throwable)` du producteur absorbe. La garde restait verte.
        //
        // Ce que la pre-verification achete n'est donc pas l'absence de
        // notification — c'est de ne pas traiter un cas ORDINAIRE comme un
        // incident. Un auteur qui a change d'Organization n'est pas une panne,
        // et ne doit pas remplir le journal d'avertissements.
        \Illuminate\Support\Facades\Log::spy();

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Un commentaire.')
            ->call('addComment')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('blog_comments', 1);
        $this->assertDatabaseCount('member_notifications', 0);

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    }

    /** L'`event_id` deterministe : rejouer ne duplique pas. */
    public function test_rejouer_l_emission_ne_cree_pas_de_doublon(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Un commentaire.')
            ->call('addComment');

        $this->assertDatabaseCount('member_notifications', 1);

        $commentaire = BlogComment::firstOrFail();
        $avant = MemberNotification::firstOrFail();

        // On rejoue le producteur sur le MEME fait, par la porte privee.
        $composant = new BlogComments;
        $composant->postId = (string) $this->article->id;
        $this->actingAs($this->lecteur);

        $methode = new \ReflectionMethod(BlogComments::class, 'prevenirLAuteur');
        $methode->invoke($composant, $this->article, $commentaire);

        $this->assertDatabaseCount('member_notifications', 1);
        $this->assertSame((string) $avant->id, (string) MemberNotification::firstOrFail()->id);
    }

    // ── La cible, resolue au clic ───────────────────────────────────────────

    public function test_la_notification_mene_au_fil_de_l_article(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Un commentaire.')
            ->call('addComment');

        $cible = app(NotificationTargetResolver::class)->resolve(MemberNotification::firstOrFail());

        $this->assertSame(
            route('organization.blog.show', [
                'organization' => $this->organization->slug,
                'post' => $this->article->slug,
            ]).'#commentaires',
            $cible
        );
    }

    public function test_un_commentaire_supprime_ne_mene_nulle_part(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Un commentaire.')
            ->call('addComment');

        BlogComment::firstOrFail()->delete();

        $this->assertNull(
            app(NotificationTargetResolver::class)->resolve(MemberNotification::firstOrFail())
        );
    }

    /** Un article repasse en brouillon ne doit pas s'ouvrir par une notification. */
    public function test_un_article_depublie_ne_mene_nulle_part(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Un commentaire.')
            ->call('addComment');

        $this->article->forceFill(['status' => 'draft'])->saveQuietly();

        $this->assertNull(
            app(NotificationTargetResolver::class)->resolve(MemberNotification::firstOrFail())
        );
    }

    // ── Le catalogue et l'ecran ─────────────────────────────────────────────

    public function test_le_catalogue_declare_la_cle_en_in_app_reglable(): void
    {
        $this->assertTrue(NotificationCatalogue::has(NotificationCatalogue::BLOG_COMMENT_CREATED));
        $this->assertSame(
            [NotificationCatalogue::CHANNEL_IN_APP],
            NotificationCatalogue::channelsFor(NotificationCatalogue::BLOG_COMMENT_CREATED)
        );
        $this->assertTrue(NotificationCatalogue::channelDefault(
            NotificationCatalogue::BLOG_COMMENT_CREATED, NotificationCatalogue::CHANNEL_IN_APP
        ));

        // Reglable, et c'est la difference avec une invitation : un commentaire
        // ne vous est pas adresse et n'appelle aucune reponse de vous.
        $this->assertTrue(NotificationCatalogue::channelIsConfigurable(
            NotificationCatalogue::BLOG_COMMENT_CREATED, NotificationCatalogue::CHANNEL_IN_APP
        ));

        // Pas de canal email : le catalogue ne declare que ce qui EXISTE.
        $this->assertFalse(NotificationCatalogue::allowsChannel(
            NotificationCatalogue::BLOG_COMMENT_CREATED, NotificationCatalogue::CHANNEL_EMAIL
        ));
    }

    public function test_la_cle_porte_un_libelle_dans_les_deux_langues(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            $libelle = __('notifications.keys.blog_comment_created');

            $this->assertNotSame('notifications.keys.blog_comment_created', $libelle,
                "Aucun libelle en [{$locale}] : l'ecran afficherait le repli generique.");
            $this->assertNotSame(__('notifications.key_fallback'), $libelle);
        }
    }

    public function test_la_notification_est_listee_dans_le_centre(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Un commentaire.')
            ->call('addComment');

        app()->setLocale('fr');

        $this->actingAs($this->auteur)
            ->get(route('organization.notifications.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->assertSee(__('notifications.keys.blog_comment_created'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function membre(): User
    {
        return User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    private function articlePublie(User $auteur): BlogPost
    {
        return BlogPost::create([
            'user_id' => $auteur->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article commentable',
            'slug' => 'article-commentable-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    private function commentaire(User $qui, string $contenu): BlogComment
    {
        return BlogComment::create([
            'blog_post_id' => $this->article->id,
            'user_id' => $qui->id,
            'organization_id' => $this->organization->id,
            'content' => $contenu,
            'is_approved' => true,
        ]);
    }
}
