<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\BlogTodo;
use App\Models\EmailLog;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\Blog\BlogTodoAssignedMailer;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmailDeliverer;
use App\Support\Notifications\NotificationTargetResolver;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1884 correctif 5 (#7) — une tache d'article confiee a quelqu'un le
 * previent par courriel : a la creation et a la reattribution, jamais soi-meme,
 * rien pour un changement de titre ou de statut.
 */
class TASK1884TodoAssignedEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $coAuteur;

    private User $autreCoAuteur;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true, 'is_active' => true, 'name' => 'Entraide du Port']);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Paul', 'name' => 'Durand']);
        $this->coAuteur = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Lina', 'name' => 'Morel', 'preferred_locale' => 'fr']);
        $this->autreCoAuteur = User::factory()->create(['organization_id' => $this->org->id]);
        app()->instance('current_organization', $this->org);

        $this->post = BlogPost::create([
            'user_id' => $this->auteur->id, 'organization_id' => $this->org->id,
            'title' => 'Bien accueillir les nouveaux membres', 'summary' => 'R', 'content' => 'x', 'status' => 'draft',
        ]);
        foreach ([$this->coAuteur, $this->autreCoAuteur] as $c) {
            $this->post->coAuthors()->attach($c->id, ['role' => 'coauthor', 'added_by' => $this->auteur->id]);
        }
    }

    private function notifies(): array
    {
        return MemberNotification::query()->where('notification_key', NotificationCatalogue::BLOG_TODO_ASSIGNED)
            ->orderBy('created_at')->pluck('recipient_id')->map(fn ($id) => (string) $id)->all();
    }

    private function creer(User $qui, ?string $assigne): BlogTodo
    {
        $id = $this->actingAs($qui)->postJson("/blog/{$this->post->slug}/todos", ['title' => 'Relire l’intro', 'assigned_to' => $assigne])
            ->assertCreated()->json('todo.id');

        return BlogTodo::findOrFail($id);
    }

    public function test_creation_et_reattribution_previennent_l_assigne_jamais_soi_meme(): void
    {
        // Pour soi-meme : rien.
        $todo = $this->creer($this->auteur, $this->auteur->id);
        $this->assertSame([], $this->notifies());

        // Creation confiee a Lina : Lina seule, in-app + courriel, pas de Push.
        $this->creer($this->auteur, $this->coAuteur->id);
        $this->assertSame([(string) $this->coAuteur->id], $this->notifies());
        $notification = MemberNotification::query()->where('recipient_id', $this->coAuteur->id)->firstOrFail();
        $this->assertSame((string) $this->auteur->id, (string) $notification->actor_id);
        $this->assertSame([NotificationCatalogue::CHANNEL_EMAIL], DB::table('member_notification_deliveries')->where('notification_id', $notification->id)->pluck('channel')->all());

        // Titre et statut changes : rien. Reattribution : le nouvel assigne.
        $this->actingAs($this->auteur)->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", ['title' => 'Autre', 'status' => 'done'])->assertOk();
        $this->assertCount(1, $this->notifies());
        $this->actingAs($this->auteur)->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", ['assigned_to' => $this->autreCoAuteur->id])->assertOk();
        $this->assertSame([(string) $this->coAuteur->id, (string) $this->autreCoAuteur->id], $this->notifies());

        // Le lien mene a l'editeur de l'article.
        $this->assertSame(
            route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $this->post->slug]),
            app(NotificationTargetResolver::class)->resolve($notification),
        );
    }

    public function test_le_courriel_reel_sujet_tronque_a_15_caracteres_et_tache_expurgee_au_journal(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);
        $this->creer($this->auteur, $this->coAuteur->id);
        $notification = MemberNotification::query()->where('recipient_id', $this->coAuteur->id)->firstOrFail();

        $methode = (new \ReflectionClass(NotificationEmailDeliverer::class))->getMethod('rendreDepuisGabarit');
        $methode->setAccessible(true);
        $rendu = fn (string $langue) => $methode->invoke(app(NotificationEmailDeliverer::class), $notification, 'https://exemple.test/modifier', $langue, $this->coAuteur->fresh());

        $fr = $rendu('fr');
        $this->assertSame('Relire l’intro sur « Bien accueillir… »', $fr['subject']);
        $this->assertStringContainsString('Bonjour Lina', $fr['body']);
        $this->assertStringContainsString('Paul', $fr['body']);
        $this->assertStringContainsString('Bien accueillir les nouveaux membres', $fr['body']);
        $this->assertStringContainsString('Tâche saisie le '.now()->locale('fr')->translatedFormat('j F Y'), $fr['body']);
        $this->assertStringNotContainsString('Relire', $fr['body_for_log']);
        $this->assertStringNotContainsString('Relire', $fr['subject_for_log']);
        $this->assertStringContainsString(BlogTodoAssignedMailer::TACHE_EXPURGEE, $fr['subject_for_log']);

        $this->assertSame('Relire l’intro on “Bien accueillir…”', $rendu('en')['subject']);

        // Titre court : pas de points de suspension quand rien n'est coupe.
        $this->assertSame('Court', BlogTodoAssignedMailer::titreCourt('Court'));
        $this->assertSame('Exactement 15 c', BlogTodoAssignedMailer::titreCourt('Exactement 15 c'));
    }

    public function test_le_journal_email_logs_n_archive_pas_le_titre_de_la_tache(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);
        $this->creer($this->auteur, $this->coAuteur->id);

        $livraison = DB::table('member_notification_deliveries')->where('channel', NotificationCatalogue::CHANNEL_EMAIL)->first();
        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->id);

        $journal = EmailLog::query()->where('to_email', $this->coAuteur->email)->latest('id')->first();
        $this->assertNotNull($journal);
        $this->assertStringNotContainsString('Relire', (string) $journal->subject);
        $this->assertStringNotContainsString('Relire', (string) $journal->body_html);
    }

    public function test_migration_et_catalogue_portent_les_memes_textes(): void
    {
        // TASK-1894 — le catalogue porte le texte REPRIS (signature et reglages
        // dans le cadre, lien principal en bouton) : la migration d'origine,
        // passee par la reprise de T1894, doit le redonner EXACTEMENT.
        $reprise = require database_path('migrations/2026_11_24_100100_habillage_organisation_tous_les_gabarits.php');
        $migration = require database_path('migrations/2026_11_23_120000_add_blog_todo_assigned_email_template.php');
        $gabarits = (fn () => $this->gabarits())->call($migration);

        foreach (['fr', 'en'] as $langue) {
            $ligne = SystemEmailTemplateSeeder::rowFor('blog_todo_assigned', $langue);
            $this->assertSame($gabarits[$langue]['subject'], $ligne['subject']);
            $this->assertSame($reprise::transformer($gabarits[$langue]['html']), $ligne['content_html']);
        }
    }
}
