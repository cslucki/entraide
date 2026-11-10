<?php

namespace Tests\Feature;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\EmailLog;
use App\Models\EntraideComment;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMessage;
use App\Models\MemberNotification;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Notifications\EmailLogContextResolver;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1800 — le « Contexte » d'un email dans `/admin/email-logs`.
 *
 * ## Ce que ce fichier verrouille
 *
 * `EmailLogContextResolver::resoudrePour()` traduit `notification_id` +
 * `object_type`/`object_id` (pipeline de notifications) ou `data->source`
 * (TASK-1659, mailers hors pipeline) en un libelle lisible — Boucle,
 * article, message prive, entraide, ou autre evenement. Chaque test couvre
 * une des sources de verite du catalogue (`NotificationCatalogue`), jamais
 * une recopie de son contrat.
 */
class TASK1800EmailLogContextTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $recipient;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->recipient = User::factory()->create(['organization_id' => $this->org->id]);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function notification(string $key, string $objectType, ?string $objectId): MemberNotification
    {
        return MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $this->recipient->id,
            'notification_key' => $key,
            'event_id' => (string) Str::uuid(),
            'object_type' => $objectType,
            'object_id' => $objectId,
        ]);
    }

    private function emailLog(?MemberNotification $notification = null, ?array $data = null): EmailLog
    {
        $log = EmailLog::factory()->create(['data' => $data ?? []]);

        if ($notification !== null) {
            $log->notification_id = (string) $notification->id;
            $log->save();
        }

        return $log->fresh();
    }

    // =====================================================================
    // Categorie "loop" — les quatre chemins vers une Boucle
    // =====================================================================

    public function test_une_mention_de_boucle_rend_boucle_et_son_nom(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => 'Boucle du jardin']);
        $message = LoopMessage::factory()->create(['loop_id' => $boucle->id, 'organization_id' => $this->org->id]);
        $notification = $this->notification(NotificationCatalogue::LOOP_MENTION_CREATED, NotificationCatalogue::OBJECT_LOOP_MESSAGE, $message->id);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Boucle : Boucle du jardin', $contexte[(string) $log->id]['label']);
    }

    public function test_un_ajout_direct_rend_boucle_et_son_nom(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => 'Boucle des voisins']);
        $notification = $this->notification(NotificationCatalogue::LOOP_MEMBER_ADDED, NotificationCatalogue::OBJECT_LOOP, (string) $boucle->id);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Boucle : Boucle des voisins', $contexte[(string) $log->id]['label']);
    }

    public function test_une_invitation_rend_boucle_et_son_nom(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => 'Boucle invitee']);
        $invitation = LoopInvitation::factory()->create(['loop_id' => $boucle->id, 'organization_id' => $this->org->id]);
        $notification = $this->notification(NotificationCatalogue::LOOP_INVITATION, NotificationCatalogue::OBJECT_LOOP_INVITATION, $invitation->id);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Boucle : Boucle invitee', $contexte[(string) $log->id]['label']);
    }

    public function test_une_demande_d_adhesion_rend_boucle_et_son_nom(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => 'Boucle demandee']);
        $demande = LoopJoinRequest::factory()->create(['loop_id' => $boucle->id, 'organization_id' => $this->org->id]);
        $notification = $this->notification(NotificationCatalogue::LOOP_JOIN_REQUEST_CREATED, NotificationCatalogue::OBJECT_LOOP_JOIN_REQUEST, $demande->id);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Boucle : Boucle demandee', $contexte[(string) $log->id]['label']);
    }

    // =====================================================================
    // Categorie "blog"
    // =====================================================================

    public function test_un_commentaire_de_blog_rend_article_et_son_titre(): void
    {
        $article = BlogPost::create([
            'user_id' => $this->recipient->id,
            'organization_id' => $this->org->id,
            'title' => 'Comment planter des tomates',
            'slug' => 'comment-planter-des-tomates-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        $commentaire = BlogComment::create([
            'blog_post_id' => $article->id,
            'user_id' => $this->recipient->id,
            'organization_id' => $this->org->id,
            'content' => 'Super article !',
            'is_approved' => true,
        ]);
        $notification = $this->notification(NotificationCatalogue::BLOG_COMMENT_CREATED, NotificationCatalogue::OBJECT_BLOG_COMMENT, $commentaire->id);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Article : Comment planter des tomates', $contexte[(string) $log->id]['label']);
    }

    public function test_un_ajout_de_co_auteur_rend_article_et_son_titre(): void
    {
        $article = BlogPost::create([
            'user_id' => $this->recipient->id,
            'organization_id' => $this->org->id,
            'title' => 'Co-ecrit a quatre mains',
            'slug' => 'co-ecrit-a-quatre-mains-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);
        $notification = $this->notification(NotificationCatalogue::BLOG_CO_AUTHOR_ADDED, NotificationCatalogue::OBJECT_BLOG_POST, (string) $article->id);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Article : Co-ecrit a quatre mains', $contexte[(string) $log->id]['label']);
    }

    // =====================================================================
    // Categorie "transaction" — le "message prive" demande par Cyril
    // =====================================================================

    public function test_une_proposition_de_transaction_rend_message_prive_et_son_sujet(): void
    {
        $categorie = Category::factory()->create();
        $service = Service::factory()
            ->forUser($this->recipient)
            ->forCategory($categorie)
            ->create(['organization_id' => $this->org->id, 'status' => 'active', 'title' => 'Aide au jardinage']);
        $transaction = Transaction::factory()
            ->forService($service)
            ->create(['organization_id' => $this->org->id]);

        $notification = $this->notification(NotificationCatalogue::TRANSACTION_PROPOSAL_CREATED, NotificationCatalogue::OBJECT_TRANSACTION, $transaction->id);
        $log = $this->emailLog($notification);

        // La garde reelle : ce test tourne SANS `current_organization` liee —
        // exactement le contexte de l'ecran SuperAdmin. Si `Transaction`
        // n'etait pas lu avec son scope retire, cette assertion rougirait
        // avec « Message privé » SANS le sujet (fail-closed silencieux).
        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Message privé : Aide au jardinage', $contexte[(string) $log->id]['label']);
    }

    // =====================================================================
    // Categorie "entraide"
    // =====================================================================

    public function test_un_commentaire_d_entraide_rend_entraide_et_le_titre_de_l_annonce(): void
    {
        $categorie = Category::factory()->create();
        $demande = ServiceRequest::factory()
            ->forUser($this->recipient)
            ->create([
                'organization_id' => $this->org->id,
                'category_id' => $categorie->id,
                'status' => 'open',
                'title' => 'Besoin d’un coup de main pour demenager',
            ]);
        $commentaire = EntraideComment::create([
            'organization_id' => $this->org->id,
            'commentable_type' => $demande->getMorphClass(),
            'commentable_id' => $demande->getKey(),
            'user_id' => $this->recipient->id,
            'content' => 'Je peux aider samedi.',
            'is_approved' => true,
        ]);
        $notification = $this->notification(NotificationCatalogue::ENTRAIDE_COMMENT_CREATED, NotificationCatalogue::OBJECT_ENTRAIDE_COMMENT, $commentaire->id);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Entraide : Besoin d’un coup de main pour demenager', $contexte[(string) $log->id]['label']);
    }

    // =====================================================================
    // Objet disparu, notification disparue, hors pipeline, ou rien
    // =====================================================================

    public function test_une_boucle_supprimee_rend_un_libelle_honnete(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => 'Boucle ephemere']);
        $notification = $this->notification(NotificationCatalogue::LOOP_MEMBER_ADDED, NotificationCatalogue::OBJECT_LOOP, (string) $boucle->id);
        $log = $this->emailLog($notification);

        $boucle->forceDelete();

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Boucle supprimée', $contexte[(string) $log->id]['label']);
    }

    public function test_une_notification_supprimee_rend_un_libelle_honnete(): void
    {
        $notification = $this->notification(NotificationCatalogue::LOOP_MEMBER_ADDED, NotificationCatalogue::OBJECT_LOOP, (string) Str::uuid());
        $log = $this->emailLog($notification);

        $notification->delete();

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Notification supprimée', $contexte[(string) $log->id]['label']);
    }

    public function test_un_envoi_hors_pipeline_avec_source_connue_rend_son_libelle(): void
    {
        $log = $this->emailLog(null, ['source' => 'admin-test']);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Test d’envoi (admin)', $contexte[(string) $log->id]['label']);
    }

    public function test_un_envoi_hors_pipeline_avec_source_inconnue_affiche_la_source_brute(): void
    {
        $log = $this->emailLog(null, ['source' => 'un-futur-mailer-non-catalogue']);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('Autre : un-futur-mailer-non-catalogue', $contexte[(string) $log->id]['label']);
    }

    public function test_un_envoi_sans_notification_ni_source_rend_un_tiret(): void
    {
        $log = $this->emailLog(null, []);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame('—', $contexte[(string) $log->id]['label']);
    }

    // =====================================================================
    // Resolution EN MASSE — la PREUVE, pas seulement la bonne reponse
    // =====================================================================

    /**
     * Cinq lignes, CINQ Boucles distinctes, UNE SEULE lecture de `loops` —
     * jamais une par ligne affichee. Meme discipline que
     * `TASK1778FanOutSqlCostTest`/`TASK1799NotificationFanOutCacheTest` :
     * une propriete relative, pas un chiffre absolu.
     */
    public function test_la_resolution_de_plusieurs_boucles_ne_fait_qu_une_lecture_de_loops(): void
    {
        $logs = collect();

        for ($i = 0; $i < 5; $i++) {
            $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => "Boucle $i"]);
            $notification = $this->notification(NotificationCatalogue::LOOP_MEMBER_ADDED, NotificationCatalogue::OBJECT_LOOP, (string) $boucle->id);
            $logs->push($this->emailLog($notification));
        }

        $lectures = 0;
        DB::listen(function (QueryExecuted $e) use (&$lectures): void {
            if (str_contains($e->sql, 'from "loops"')) {
                $lectures++;
            }
        });

        $contexte = app(EmailLogContextResolver::class)->resoudrePour($logs, $this->admin);
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertCount(5, $contexte);
        $this->assertLessThanOrEqual(
            1,
            $lectures,
            "La table loops a ete lue {$lectures} fois pour 5 lignes : la resolution en masse ne joue plus."
        );
    }

    // =====================================================================
    // Integration — la colonne apparait bien dans l'ecran
    // =====================================================================

    public function test_l_ecran_affiche_le_contexte_dans_le_tableau(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => 'Boucle visible a l ecran']);
        $notification = $this->notification(NotificationCatalogue::LOOP_MEMBER_ADDED, NotificationCatalogue::OBJECT_LOOP, (string) $boucle->id);
        $this->emailLog($notification);

        $response = $this->actingAs($this->admin)->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $response->assertSee('Contexte');
        $response->assertSee('Boucle : Boucle visible a l ecran');
    }

    // =====================================================================
    // Les liens — demande de Cyril (10/11, meme jour) : « voir de quoi il
    // s'agit ». Chaque route cible a sa PROPRE garde, lue et jamais recopiee.
    // =====================================================================

    /**
     * `admin.loops.show` accepte tout `is_admin`, quelle que soit son
     * Organization personnelle (`AdminLoopController::assertOrgAccess()`).
     * Toujours offert.
     */
    public function test_l_ajout_direct_a_un_lien_toujours_offert_vers_la_boucle(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => 'Boucle liee']);
        $notification = $this->notification(NotificationCatalogue::LOOP_MEMBER_ADDED, NotificationCatalogue::OBJECT_LOOP, (string) $boucle->id);
        $log = $this->emailLog($notification);

        // Un admin D'UNE AUTRE Organization : si le lien exigeait une
        // appartenance, il serait absent. Il ne doit pas l'exiger ici.
        $autreOrg = Organization::factory()->create();
        $adminDunAutreTenant = User::factory()->create(['is_admin' => true, 'organization_id' => $autreOrg->id]);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $adminDunAutreTenant);

        $this->assertSame(route('admin.loops.show', $boucle->id), $contexte[(string) $log->id]['url']);
    }

    /**
     * Une mention porte DEUX liens : la Boucle (toujours, `admin.loops.show`
     * accepte tout SuperAdmin) ET la SOURCE du message (`url_message`) — la
     * MEME URL publique que `NotificationTargetResolver::loopMessage()`
     * construit deja pour le lien de l'email (`organization.loops.show` +
     * `?message=`), pas un ecran admin. Elle exige l'appartenance via
     * `organization.member` : offerte seulement quand `organization_id` de
     * l'admin correspond. « Un lien vers la Boucle elle-meme ET si possible
     * le message lui-meme », demande de Cyril, mais jamais un lien mort.
     */
    public function test_une_mention_a_un_lien_vers_la_boucle_et_vers_le_message_quand_l_admin_est_du_meme_tenant(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id]);
        $message = LoopMessage::factory()->create(['loop_id' => $boucle->id, 'organization_id' => $this->org->id]);
        $notification = $this->notification(NotificationCatalogue::LOOP_MENTION_CREATED, NotificationCatalogue::OBJECT_LOOP_MESSAGE, $message->id);
        $log = $this->emailLog($notification);

        $adminDuMemeTenant = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $adminDuMemeTenant);

        $this->assertSame(route('admin.loops.show', $boucle->id), $contexte[(string) $log->id]['url']);
        $this->assertSame(
            route('organization.loops.show', ['organization' => $this->org->slug, 'loop' => $boucle->slug, 'message' => $message->id]),
            $contexte[(string) $log->id]['url_message']
        );
    }

    public function test_une_mention_garde_son_lien_boucle_mais_perd_le_lien_message_quand_l_admin_est_d_un_autre_tenant(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id]);
        $message = LoopMessage::factory()->create(['loop_id' => $boucle->id, 'organization_id' => $this->org->id]);
        $notification = $this->notification(NotificationCatalogue::LOOP_MENTION_CREATED, NotificationCatalogue::OBJECT_LOOP_MESSAGE, $message->id);
        $log = $this->emailLog($notification);

        $autreOrg = Organization::factory()->create();
        $adminDunAutreTenant = User::factory()->create(['is_admin' => true, 'organization_id' => $autreOrg->id]);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $adminDunAutreTenant);

        // Le lien vers la BOUCLE reste offert : `admin.loops.show` n'exige
        // aucune appartenance.
        $this->assertSame(route('admin.loops.show', $boucle->id), $contexte[(string) $log->id]['url']);
        $this->assertNull($contexte[(string) $log->id]['url_message']);
        // Le libelle, lui, reste exact.
        $this->assertStringStartsWith('Boucle : ', $contexte[(string) $log->id]['label']);
    }

    /** `AdminBlogController::edit()` ne porte aucune garde d'Organization : toujours offert. */
    public function test_un_article_a_un_lien_toujours_offert(): void
    {
        $article = BlogPost::create([
            'user_id' => $this->recipient->id,
            'organization_id' => $this->org->id,
            'title' => 'Article lie',
            'slug' => 'article-lie-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        $notification = $this->notification(NotificationCatalogue::BLOG_CO_AUTHOR_ADDED, NotificationCatalogue::OBJECT_BLOG_POST, (string) $article->id);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertSame(route('admin.blog.edit', $article->id), $contexte[(string) $log->id]['url']);
    }

    /**
     * `admin.messages.show` prend un MESSAGE, pas une Transaction : le
     * resolveur lie au PREMIER message de l'echange — celui qui porte la
     * proposition — et seulement si l'admin est du meme tenant (meme garde
     * que pour la mention).
     */
    public function test_un_message_prive_a_un_lien_vers_le_premier_message_quand_l_admin_est_du_meme_tenant(): void
    {
        $categorie = Category::factory()->create();
        $service = Service::factory()
            ->forUser($this->recipient)
            ->forCategory($categorie)
            ->create(['organization_id' => $this->org->id, 'status' => 'active']);
        $transaction = Transaction::factory()->forService($service)->create(['organization_id' => $this->org->id]);
        $premier = Message::factory()->forTransaction($transaction)->create([
            'organization_id' => $this->org->id,
            'created_at' => now()->subMinutes(10),
        ]);
        Message::factory()->forTransaction($transaction)->create([
            'organization_id' => $this->org->id,
            'created_at' => now(),
        ]);

        $notification = $this->notification(NotificationCatalogue::TRANSACTION_PROPOSAL_CREATED, NotificationCatalogue::OBJECT_TRANSACTION, $transaction->id);
        $log = $this->emailLog($notification);

        $adminDuMemeTenant = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $adminDuMemeTenant);

        $this->assertSame(route('admin.messages.show', $premier->id), $contexte[(string) $log->id]['url']);
    }

    public function test_un_message_prive_n_a_aucun_lien_quand_l_admin_est_d_un_autre_tenant(): void
    {
        $categorie = Category::factory()->create();
        $service = Service::factory()
            ->forUser($this->recipient)
            ->forCategory($categorie)
            ->create(['organization_id' => $this->org->id, 'status' => 'active']);
        $transaction = Transaction::factory()->forService($service)->create(['organization_id' => $this->org->id]);
        Message::factory()->forTransaction($transaction)->create(['organization_id' => $this->org->id]);

        $notification = $this->notification(NotificationCatalogue::TRANSACTION_PROPOSAL_CREATED, NotificationCatalogue::OBJECT_TRANSACTION, $transaction->id);
        $log = $this->emailLog($notification);

        $autreOrg = Organization::factory()->create();
        $adminDunAutreTenant = User::factory()->create(['is_admin' => true, 'organization_id' => $autreOrg->id]);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $adminDunAutreTenant);

        $this->assertNull($contexte[(string) $log->id]['url']);
    }

    /** Aucune route admin ne couvre Service/ServiceRequest : jamais de lien. */
    public function test_une_entraide_n_a_jamais_de_lien(): void
    {
        $categorie = Category::factory()->create();
        $demande = ServiceRequest::factory()
            ->forUser($this->recipient)
            ->create(['organization_id' => $this->org->id, 'category_id' => $categorie->id, 'status' => 'open']);
        $commentaire = EntraideComment::create([
            'organization_id' => $this->org->id,
            'commentable_type' => $demande->getMorphClass(),
            'commentable_id' => $demande->getKey(),
            'user_id' => $this->recipient->id,
            'content' => 'Je peux aider.',
            'is_approved' => true,
        ]);
        $notification = $this->notification(NotificationCatalogue::ENTRAIDE_COMMENT_CREATED, NotificationCatalogue::OBJECT_ENTRAIDE_COMMENT, $commentaire->id);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertNull($contexte[(string) $log->id]['url']);
    }

    /** Une ligne hors pipeline (`data->source`) ne porte jamais de lien. */
    public function test_un_envoi_hors_pipeline_n_a_jamais_de_lien(): void
    {
        $log = $this->emailLog(null, ['source' => 'admin-test']);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertNull($contexte[(string) $log->id]['url']);
    }

    /** L'ecran rend bien un `<a href>` quand le lien est offert. */
    public function test_l_ecran_rend_un_lien_cliquable_quand_il_est_offert(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => 'Boucle avec lien']);
        $notification = $this->notification(NotificationCatalogue::LOOP_MEMBER_ADDED, NotificationCatalogue::OBJECT_LOOP, (string) $boucle->id);
        $this->emailLog($notification);

        $response = $this->actingAs($this->admin)->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $response->assertSee('href="'.route('admin.loops.show', $boucle->id).'"', false);
    }

    /** Une mention rend bien LES DEUX liens (Boucle + message) dans l'ecran. */
    public function test_l_ecran_rend_les_deux_liens_pour_une_mention(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id]);
        $message = LoopMessage::factory()->create(['loop_id' => $boucle->id, 'organization_id' => $this->org->id]);
        $notification = $this->notification(NotificationCatalogue::LOOP_MENTION_CREATED, NotificationCatalogue::OBJECT_LOOP_MESSAGE, $message->id);
        $this->emailLog($notification);

        $adminDuMemeTenant = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);

        $response = $this->actingAs($adminDuMemeTenant)->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $response->assertSee('href="'.route('admin.loops.show', $boucle->id).'"', false);
        $response->assertSee(
            'href="'.route('organization.loops.show', ['organization' => $this->org->slug, 'loop' => $boucle->slug, 'message' => $message->id]).'"',
            false
        );
        $response->assertSee('Voir le message');
    }

    // =====================================================================
    // L'EXPEDITEUR — Cyril : « comme pour Destinataire, un lien Se connecter
    // sous ». C'est l'ACTEUR de la notification, jamais le destinataire.
    // =====================================================================

    public function test_le_resolveur_rend_l_acteur_comme_expediteur(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id]);
        $message = LoopMessage::factory()->create(['loop_id' => $boucle->id, 'organization_id' => $this->org->id]);
        $acteur = User::factory()->create(['organization_id' => $this->org->id]);
        $notification = MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $this->recipient->id,
            'notification_key' => NotificationCatalogue::LOOP_MENTION_CREATED,
            'event_id' => (string) Str::uuid(),
            'object_type' => NotificationCatalogue::OBJECT_LOOP_MESSAGE,
            'object_id' => $message->id,
            'actor_id' => $acteur->id,
        ]);
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertNotNull($contexte[(string) $log->id]['expediteur']);
        $this->assertSame($acteur->id, $contexte[(string) $log->id]['expediteur']->id);
    }

    public function test_le_resolveur_rend_null_quand_la_notification_n_a_aucun_acteur(): void
    {
        $notification = $this->notification(NotificationCatalogue::LOOP_MEMBER_ADDED, NotificationCatalogue::OBJECT_LOOP, (string) Str::uuid());
        $log = $this->emailLog($notification);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertNull($contexte[(string) $log->id]['expediteur']);
    }

    public function test_un_envoi_hors_pipeline_n_a_aucun_expediteur(): void
    {
        $log = $this->emailLog(null, ['source' => 'admin-test']);

        $contexte = app(EmailLogContextResolver::class)->resoudrePour(collect([$log]), $this->admin);

        $this->assertNull($contexte[(string) $log->id]['expediteur']);
    }

    public function test_l_ecran_affiche_l_expediteur_et_son_lien_se_connecter_sous(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id]);
        $message = LoopMessage::factory()->create(['loop_id' => $boucle->id, 'organization_id' => $this->org->id]);
        $acteur = User::factory()->complete()->create(['organization_id' => $this->org->id, 'first_name' => 'Camille']);
        $notification = MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $this->recipient->id,
            'notification_key' => NotificationCatalogue::LOOP_MENTION_CREATED,
            'event_id' => (string) Str::uuid(),
            'object_type' => NotificationCatalogue::OBJECT_LOOP_MESSAGE,
            'object_id' => $message->id,
            'actor_id' => $acteur->id,
        ]);
        $this->emailLog($notification);

        $response = $this->actingAs($this->admin)->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $response->assertSee('Expéditeur');
        $response->assertSee($acteur->email);
        // Le lien « Se connecter sous » est un FORMULAIRE POST (changer
        // d'identite est une ECRITURE de session), jamais un `<a href>` — voir
        // le docblock de `admin.messages.partials.se-connecter-sous`.
        $response->assertSee('action="'.route('admin.users.login-as', $acteur).'"', false);
    }

    public function test_l_ecran_affiche_systeme_quand_la_ligne_n_a_aucun_expediteur(): void
    {
        $log = $this->emailLog(null, ['source' => 'admin-test']);

        $response = $this->actingAs($this->admin)->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $response->assertSee('Système');
    }

    /**
     * Garde la plus importante de ce correctif : `AdminEmailLogsController`
     * chargeait `user:id,name,email` — SANS `banned_at` ni `is_admin`. Le
     * partial `se-connecter-sous` lirait alors `null` pour ces deux colonnes
     * et sa propre garde laisserait PASSER le lien sur un destinataire banni.
     * Corrige par l'ajout des deux colonnes au `with()` du controleur.
     */
    public function test_l_ecran_n_offre_pas_le_lien_se_connecter_sous_pour_un_destinataire_banni(): void
    {
        $destinataireBanni = User::factory()->create(['organization_id' => $this->org->id, 'banned_at' => now()]);
        $log = EmailLog::factory()->create(['user_id' => $destinataireBanni->id, 'to_email' => $destinataireBanni->email]);

        $response = $this->actingAs($this->admin)->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $response->assertDontSee('action="'.route('admin.users.login-as', $destinataireBanni).'"', false);
    }

    public function test_l_ecran_n_offre_pas_le_lien_se_connecter_sous_pour_un_expediteur_banni(): void
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id]);
        $message = LoopMessage::factory()->create(['loop_id' => $boucle->id, 'organization_id' => $this->org->id]);
        $acteurBanni = User::factory()->create(['organization_id' => $this->org->id, 'banned_at' => now()]);
        $notification = MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $this->recipient->id,
            'notification_key' => NotificationCatalogue::LOOP_MENTION_CREATED,
            'event_id' => (string) Str::uuid(),
            'object_type' => NotificationCatalogue::OBJECT_LOOP_MESSAGE,
            'object_id' => $message->id,
            'actor_id' => $acteurBanni->id,
        ]);
        $this->emailLog($notification);

        $response = $this->actingAs($this->admin)->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $response->assertDontSee('action="'.route('admin.users.login-as', $acteurBanni).'"', false);
    }

    // =====================================================================
    // Le Sujet mene au detail — Cyril : « eviter de defiler jusqu'a Actions ».
    // =====================================================================

    public function test_le_sujet_mene_au_detail_de_la_ligne(): void
    {
        $log = EmailLog::factory()->create(['subject' => 'Sujet cliquable']);

        $response = $this->actingAs($this->admin)->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $response->assertSee('href="'.route('admin.email-logs.show', $log).'"', false);
        $response->assertSeeInOrder(['href="'.route('admin.email-logs.show', $log).'"', 'Sujet cliquable'], false);
    }
}
