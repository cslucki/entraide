<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopNotificationPreference;
use App\Models\LoopPoll;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1879 (demande Cyril, 22/11) — comme la Veille, trois annonces ChatLoop
 * jusque-la muettes notifient maintenant les membres en in-app + Web Push
 * (jamais par e-mail) : un Sondage pose, un Article publie dans la Boucle, un
 * depot de fichiers dans Dossiers. Seules les CREATIONS notifient.
 */
class TASK1879NotificationsCollectivesBoucleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Loop $loop;

    private User $auteur;

    private User $membre;

    private User $parti;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');
        Mail::fake();

        $this->organization = Organization::factory()->create(['is_active' => true, 'is_public' => true]);
        $this->auteur = $this->personne();
        $this->membre = $this->personne();
        $this->parti = $this->personne();

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
        ]);

        $this->adhesion($this->auteur, 'owner');
        $this->adhesion($this->membre, 'member');
        $this->adhesion($this->parti, 'member', 'removed');
    }

    public function test_un_sondage_pose_notifie_les_membres_mais_pas_sa_cloture(): void
    {
        $sondage = LoopPoll::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'question' => 'Quel creneau ?',
            'options' => ['Lundi', 'Mardi'],
            'status' => 'open',
        ]);

        $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'created');
        $this->assertDestinataires(NotificationCatalogue::LOOP_POLL_CREATED);

        $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'closed');
        $this->assertSame(1, $this->notifications(NotificationCatalogue::LOOP_POLL_CREATED)->count(), 'la cloture ne notifie pas');
    }

    public function test_un_article_publie_notifie_une_seule_fois(): void
    {
        $post = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->auteur->id,
            'title' => 'Article de test',
            'content' => '<p>Contenu.</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->service()->sendArticlePublishedMessage($this->loop, $this->auteur, $post);
        $this->service()->sendArticlePublishedMessage($this->loop, $this->auteur, $post);

        $this->assertDestinataires(NotificationCatalogue::LOOP_ARTICLE_PUBLISHED);
    }

    public function test_un_depot_de_plusieurs_fichiers_notifie_une_seule_fois(): void
    {
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => null,
            'loop_id' => $this->loop->id,
        ]);
        $fichiers = DossierFile::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->auteur->id,
        ]);

        $this->service()->sendDossierFileMessage($this->loop, $this->auteur, $fichiers);

        $this->assertDestinataires(NotificationCatalogue::LOOP_DOSSIER_FILE_UPLOADED);
    }

    public function test_canaux_in_app_et_web_push_jamais_email_et_sourdine_par_boucle(): void
    {
        foreach ([
            NotificationCatalogue::LOOP_POLL_CREATED,
            NotificationCatalogue::LOOP_ARTICLE_PUBLISHED,
            NotificationCatalogue::LOOP_DOSSIER_FILE_UPLOADED,
        ] as $cle) {
            $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_IN_APP), $cle);
            $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_WEB_PUSH), $cle);
            $this->assertTrue(NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_WEB_PUSH), $cle);
            $this->assertNull(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_EMAIL), $cle);
            $this->assertSame((string) $this->loop->id, NotificationCatalogue::loopScopeFor($cle, (string) $this->loop->id), $cle);
        }

        $reglage = new LoopNotificationPreference(['collective_enabled' => false]);
        $reglage->organization_id = (string) $this->organization->id;
        $reglage->loop_id = (string) $this->loop->id;
        $reglage->user_id = (string) $this->membre->id;
        $reglage->save();

        $sondage = LoopPoll::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'question' => 'En sourdine ?',
            'options' => ['Oui', 'Non'],
            'status' => 'open',
        ]);
        $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'created');

        $this->assertSame(0, $this->notifications(NotificationCatalogue::LOOP_POLL_CREATED)->count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    private function assertDestinataires(string $cle): void
    {
        $notifications = $this->notifications($cle);

        $this->assertSame([$this->membre->id], $notifications->pluck('recipient_id')->all(), "{$cle} : membres actifs sauf l'auteur");
        $this->assertSame($this->loop->id, $notifications->first()->object_id);
        $this->assertFalse(
            $notifications->flatMap->deliveries->contains('channel', NotificationCatalogue::CHANNEL_EMAIL),
            "{$cle} : aucune livraison e-mail",
        );
        Mail::assertNothingSent();
    }

    private function notifications(string $cle)
    {
        return MemberNotification::query()->where('notification_key', $cle)->get();
    }

    private function service(): LoopMessageService
    {
        return app(LoopMessageService::class);
    }

    private function personne(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'approved_at' => now(),
        ]);
    }

    private function adhesion(User $user, string $role, string $status = 'active'): void
    {
        LoopMember::create([
            'loop_id' => $this->loop->id,
            'user_id' => $user->id,
            'organization_id' => $this->organization->id,
            'role' => $role,
            'status' => $status,
        ]);
    }
}
