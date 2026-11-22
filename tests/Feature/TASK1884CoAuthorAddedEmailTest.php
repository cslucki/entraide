<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmailDeliverer;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1884 correctif 4 (#1) — nommer un co-auteur le previent par in-app,
 * Web Push ET courriel (gabarit systeme global `blog_co_author_added`).
 */
class TASK1884CoAuthorAddedEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $coAuteur;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'name' => 'Entraide du Port']);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Paul', 'name' => 'Durand']);
        $this->coAuteur = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Lina', 'name' => 'Morel', 'preferred_locale' => 'fr']);
        $this->post = BlogPost::withoutGlobalScopes()->forceCreate([
            'user_id' => $this->auteur->id, 'organization_id' => $this->org->id,
            'title' => 'Bien accueillir les nouveaux', 'slug' => 'bien-accueillir', 'content' => '<p>x</p>', 'status' => 'draft',
        ]);
    }

    private function nommer(): MemberNotification
    {
        $this->actingAs($this->auteur)
            ->postJson(route('organization.blog.co-authors.store', ['organization' => $this->org->slug, 'post' => $this->post->slug]), ['user_id' => $this->coAuteur->id])
            ->assertSuccessful();

        return MemberNotification::query()
            ->where('recipient_id', $this->coAuteur->id)
            ->where('notification_key', NotificationCatalogue::BLOG_CO_AUTHOR_ADDED)
            ->firstOrFail();
    }

    private function rendu(MemberNotification $notification, string $langue): array
    {
        $methode = (new \ReflectionClass(NotificationEmailDeliverer::class))->getMethod('rendreDepuisGabarit');
        $methode->setAccessible(true);

        return $methode->invoke(app(NotificationEmailDeliverer::class), $notification, 'https://exemple.test/modifier', $langue, $this->coAuteur->fresh());
    }

    public function test_le_co_auteur_est_prevenu_sur_les_trois_canaux_et_le_courriel_se_desactive(): void
    {
        $notification = $this->nommer();

        $this->assertSame((string) $this->auteur->id, (string) $notification->actor_id);
        $this->assertSame(
            [NotificationCatalogue::CHANNEL_EMAIL, NotificationCatalogue::CHANNEL_WEB_PUSH],
            DB::table('member_notification_deliveries')->where('notification_id', $notification->id)->pluck('channel')->sort()->values()->all(),
        );

        $canaux = NotificationCatalogue::definition(NotificationCatalogue::BLOG_CO_AUTHOR_ADDED)['channels'];
        $this->assertTrue($canaux[NotificationCatalogue::CHANNEL_EMAIL]['configurable']);
        $this->assertFalse($canaux[NotificationCatalogue::CHANNEL_IN_APP]['configurable']);
    }

    /** Le piege T1751/T1761 : sans branche du livreur, le courriel part par le repli, sans nom. */
    public function test_le_courriel_reel_vient_du_gabarit_de_la_base_en_francais_et_en_anglais(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);
        $notification = $this->nommer();

        $fr = $this->rendu($notification, 'fr');
        $this->assertSame('Paul vous a nommé co-auteur d’un article', $fr['subject']);
        $this->assertStringContainsString('Bonjour Lina', $fr['body']);
        $this->assertStringContainsString('Bien accueillir les nouveaux', $fr['body']);
        $this->assertStringContainsString('https://exemple.test/modifier', $fr['body']);
        $this->assertStringContainsString('L’équipe Entraide du Port', $fr['body']);
        $this->assertStringNotContainsString('https://exemple.test/modifier', $fr['body_for_log'], 'Lien expurge dans la copie archivee.');

        $this->assertSame('Paul made you co-author of an article', $this->rendu($notification, 'en')['subject']);

        // Un gabarit modifie dans l'admin est bien celui qui part.
        SystemEmailTemplate::query()->whereNull('organization_id')->where('slug', 'blog_co_author_added')->where('locale', 'fr')
            ->update(['subject' => 'Nouveau : {{ author_first_name }} et vous']);
        $this->assertSame('Nouveau : Paul et vous', $this->rendu($notification, 'fr')['subject']);
    }

    public function test_migration_et_catalogue_portent_les_memes_textes(): void
    {
        $migration = require database_path('migrations/2026_11_23_110000_add_blog_co_author_added_email_template.php');
        $gabarits = (fn () => $this->gabarits())->call($migration);
        $catalogue = SystemEmailTemplateSeeder::rowFor('blog_co_author_added', 'fr');

        $this->assertSame($gabarits['fr']['subject'], $catalogue['subject']);
        $this->assertSame($gabarits['fr']['html'], $catalogue['content_html']);
        $this->assertSame($gabarits['en']['html'], SystemEmailTemplateSeeder::rowFor('blog_co_author_added', 'en')['content_html']);
    }
}
