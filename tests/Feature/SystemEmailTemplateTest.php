<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Message;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AiBudgetExceeded;
use App\Notifications\NewMessageReceived;
use App\Notifications\TransactionStatusChanged;
use App\Notifications\WelcomeNotification;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SystemEmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // TASK-1230 : nom DETERMINISTE (Faker `company()` sinon) — ce nom est
        // rendu sur les pages admin (filtre + tableau) fouillees par
        // assertDontSee(), et cherche tel quel par assertDontSee($this->org->name).
        $this->org = Organization::factory()->create([
            'is_active' => true,
            'locale' => 'fr',
            'slug' => 'main',
            'name' => 'Main Sentinel Org',
        ]);
        $this->user = User::factory()->complete()->create([
            'name' => 'Martin',
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
            'city' => 'Paris',
            'organization_id' => $this->org->id,
            'preferred_locale' => 'fr',
        ]);
    }

    // ── Seeders ────────────────────────────────────────

    /**
     * TASK-1694 bug N — un fixture EXPLICITE pour les lignes par Organization.
     *
     * Quatre tests de ce fichier se servaient du seeder comme d'une usine a
     * lignes par tenant. Le seeder seme desormais du Global (decision de
     * Cyril) ; la resolution par tenant, elle, N'A PAS CHANGE — une
     * Organization qui a personnalise son email garde sa version.
     *
     * Ces tests gardent donc leur garantie, avec leur fixture ecrit ici :
     * reproduire l'ancienne forme du seeder, pour les slugs qu'ils lisent.
     */
    private function seedPerOrganizationTemplates(): void
    {
        foreach (Organization::where('is_active', true)->get() as $organization) {
            foreach (array_keys(SystemEmailTemplateSeeder::definitions()) as $slug) {
                foreach (['fr', 'en'] as $locale) {
                    SystemEmailTemplate::firstOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'locale' => $locale,
                            'slug' => $slug,
                        ],
                        SystemEmailTemplateSeeder::rowFor($slug, $locale) + ['enabled' => true],
                    );
                }
            }
        }
    }

    /**
     * TASK-1694 bug N — LA GARANTIE DE CE TEST A CHANGE, par decision produit.
     *
     * Il mesurait « chaque Organization active recoit chaque gabarit dans les
     * deux langues ». Cyril : « il faudrait qu'on ait uniquement des emails de
     * type Global. » Le seeder seme desormais UNE ligne par slug et par langue,
     * sans rattachement, et le resolveur (bug M) les rend atteignables.
     *
     * Ce que l'ancienne regle coutait, mesure le 23/10 : 21 lignes rattachees a
     * deux Organizations, toutes identiques au catalogue octet pour octet — et
     * OFSH, creee APRES le seeder, n'en avait aucune. Deux Organizations
     * servies differemment par la seule grace de leur date de creation.
     *
     * ## L'ancienne version de ce test passait A VIDE
     *
     * Constate en le remplacant : elle lisait le nombre de slugs DANS la base
     * (`whereNotNull('organization_id')->distinct()->count('slug')`), puis
     * comparait `$slugs * 2 * 3` au compte de ces memes lignes. Avec un seeder
     * qui ne rattache plus rien, cela donnait `0 === 0` — **vert sans rien
     * mesurer**. Un total lu dans la chose mesuree ne mesure pas cette chose.
     * Le compte vient maintenant du CATALOGUE, qui est la source.
     */
    public function test_seeder_creates_global_templates_independent_of_organizations(): void
    {
        Organization::factory()->create(['is_active' => true, 'slug' => 'org-a']);
        Organization::factory()->create(['is_active' => true, 'slug' => 'org-b']);
        Organization::factory()->create(['is_active' => false, 'slug' => 'inactive']);

        $this->seed(SystemEmailTemplateSeeder::class);

        $catalogue = count(SystemEmailTemplateSeeder::definitions());

        $this->assertSame(
            $catalogue * 2,
            SystemEmailTemplate::whereNull('organization_id')->count(),
            'une ligne par slug et par langue, et rien de plus',
        );

        $this->assertSame(
            0,
            SystemEmailTemplate::whereNotNull('organization_id')->count(),
            'le seeder ne rattache plus aucun gabarit a une Organization',
        );
    }

    /**
     * Et le nombre d'Organizations n'y change RIEN.
     *
     * C'est la moitie qui distingue vraiment les deux regles : l'ancien seeder
     * multipliait ses lignes par le nombre d'Organizations actives.
     */
    public function test_the_number_of_organizations_does_not_change_what_is_seeded(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);
        $avant = SystemEmailTemplate::count();

        Organization::factory()->count(3)->create(['is_active' => true]);
        $this->seed(SystemEmailTemplateSeeder::class);

        $this->assertSame($avant, SystemEmailTemplate::count());
    }

    public function test_seeder_does_not_duplicate(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);
        $count = SystemEmailTemplate::count();
        $this->seed(SystemEmailTemplateSeeder::class);

        $this->assertSame($count, SystemEmailTemplate::count());
    }

    public function test_seeder_does_not_overwrite_modified_templates(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $template = SystemEmailTemplate::where('slug', WelcomeNotification::SLUG)
            ->where('locale', 'fr')
            ->whereNull('organization_id')
            ->first();

        $template->update(['subject' => 'Custom subject']);

        $this->seed(SystemEmailTemplateSeeder::class);

        $template->refresh();
        $this->assertSame('Custom subject', $template->subject);
    }

    // ── Legacy backward compat ─────────────────────────

    public function test_legacy_template_without_org_still_works(): void
    {
        $userWithoutOrg = User::factory()->complete()->create([
            'name' => 'Bob Legacy',
            'first_name' => null,
            'email' => 'bob@example.com',
            'organization_id' => null,
            'preferred_locale' => 'fr',
        ]);

        SystemEmailTemplate::create([
            'slug' => WelcomeNotification::SLUG,
            'name' => 'Welcome',
            'subject' => 'Legacy {{ name }}',
            'content_html' => '<p>Legacy {{ name }}</p>',
            'enabled' => true,
        ]);

        $userWithoutOrg->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $userWithoutOrg->id,
            'subject' => 'Legacy Bob Legacy',
        ]);
    }

    /**
     * TASK-1694 bug G — CETTE REGLE EST REMPLACEE, et le test avec elle.
     *
     * Elle disait : un gabarit GLOBAL n'est jamais employe des que la personne
     * appartient a une Organization. Comme tout le monde en a une, la ligne
     * globale n'etait donc LISIBLE PAR PERSONNE — l'administration l'affichait,
     * le produit ne s'en servait jamais.
     *
     * Decision de Cyril : « je pense que c'est bien que l'email de verification
     * soit Global, on ne va pas creer un email pour chaque organisation. » Le
     * global devient donc un REPLI reel, apres les lignes du tenant.
     *
     * Le test est RETOURNE, pas supprime : il garde la question « qui gagne ? »,
     * et le test voisin `..._still_wins_over_the_global_one` garde l'autre
     * moitie — une Organization qui a personnalise n'est jamais recouverte.
     */
    public function test_global_template_is_used_when_the_org_has_none(): void
    {
        SystemEmailTemplate::create([
            'slug' => WelcomeNotification::SLUG,
            'name' => 'Global Welcome',
            'subject' => 'Global {{ name }}',
            'content_html' => '<p>Global</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Global '.$this->user->fullName,
        ]);
    }

    // ── Org + locale resolution ────────────────────────

    public function test_resolves_template_by_org_and_locale(): void
    {
        $this->seedPerOrganizationTemplates();

        $template = SystemEmailTemplate::where('slug', WelcomeNotification::SLUG)
            ->where('locale', 'fr')
            ->where('organization_id', $this->org->id)
            ->first();

        $this->assertNotNull($template);
        $this->assertStringContainsString('{{ organization }}', $template->subject);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Bienvenue sur '.$this->org->name.', Alice Martin !',
        ]);
    }

    public function test_resolves_template_in_english_when_user_prefers_en(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $this->user->update(['preferred_locale' => 'en']);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
        ]);

        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertStringContainsString('Welcome to', $log->subject);
    }

    public function test_falls_back_to_org_default_locale_when_preferred_not_available(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $this->user->update(['preferred_locale' => 'de']);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Bienvenue sur '.$this->org->name.', Alice Martin !',
        ]);
    }

    public function test_no_cross_org_fallback(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'other']);
        $this->seedPerOrganizationTemplates();

        SystemEmailTemplate::where('organization_id', $otherOrg->id)
            ->where('slug', WelcomeNotification::SLUG)
            ->where('locale', 'fr')
            ->update(['subject' => 'Other org subject']);

        $userInOtherOrg = User::factory()->complete()->create([
            'organization_id' => $otherOrg->id,
            'preferred_locale' => 'fr',
        ]);

        $userInOtherOrg->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $userInOtherOrg->id,
            'subject' => 'Other org subject',
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseMissing('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Other org subject',
        ]);
    }

    public function test_disabled_template_falls_back_to_blade(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        SystemEmailTemplate::where('slug', WelcomeNotification::SLUG)
            ->where('locale', 'fr')
            ->where('organization_id', $this->org->id)
            ->update(['enabled' => false]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
        ]);

        $log = EmailLog::where('user_id', $this->user->id)->first();
        // TASK-1694 bug G — le repli nomme l'ORGANIZATION, plus la marque.
        $this->assertStringContainsString('Bienvenue sur '.$this->user->organization->name, $log->subject);
    }

    // ── Welcome ────────────────────────────────────────

    public function test_welcome_uses_system_template_when_enabled(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => WelcomeNotification::SLUG,
            'name' => 'Welcome',
            'subject' => 'Bienvenue {{ name }} !',
            'content_html' => '<h1>Bienvenue {{ name }} !</h1>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Bienvenue Alice Martin !',
        ]);
    }

    public function test_welcome_falls_back_when_no_system_template(): void
    {
        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            // TASK-1694 bug G — le repli nomme l'ORGANIZATION, plus la marque.
            'subject' => 'Bienvenue sur '.($this->user->organization?->name ?: config('app.name')).' !',
        ]);
    }

    public function test_welcome_falls_back_when_system_template_disabled(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => WelcomeNotification::SLUG,
            'name' => 'Welcome',
            'subject' => 'Bienvenue {{ name }} !',
            'content_html' => '<h1>Bienvenue {{ name }} !</h1>',
            'enabled' => false,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            // TASK-1694 bug G — le repli nomme l'ORGANIZATION, plus la marque.
            'subject' => 'Bienvenue sur '.($this->user->organization?->name ?: config('app.name')).' !',
        ]);
    }

    public function test_system_email_view_renders_within_mail_layout(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => WelcomeNotification::SLUG,
            'name' => 'Welcome',
            'subject' => 'Bienvenue',
            'content_html' => '<p>Contenu personnalisé</p>',
            'enabled' => true,
        ]);

        $msg = (new WelcomeNotification)->toMail($this->user);
        $this->assertStringContainsString('emails.system-email', $msg->view);
        $this->assertStringContainsString('Contenu personnalisé', $msg->viewData['html']);
    }

    // ── Transaction status ─────────────────────────────

    public function test_transaction_status_uses_system_template_when_enabled(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'transaction_status_changed',
            'name' => 'Transaction Status',
            'subject' => 'Mise à jour — {{ status_label }}',
            'content_html' => '<p>Statut : {{ status_label }}</p>',
            'enabled' => true,
        ]);

        $transaction = Transaction::factory()->create([
            'buyer_id' => $this->user->id,
        ]);

        $this->user->notify(new TransactionStatusChanged($transaction));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Mise à jour — '.$transaction->status_label,
        ]);
    }

    public function test_transaction_status_falls_back_when_disabled(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'transaction_status_changed',
            'name' => 'Transaction Status',
            'subject' => 'Mise à jour — {{ status_label }}',
            'content_html' => '<p>Statut : {{ status_label }}</p>',
            'enabled' => false,
        ]);

        $transaction = Transaction::factory()->create([
            'buyer_id' => $this->user->id,
        ]);

        $this->user->notify(new TransactionStatusChanged($transaction));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
        ]);
        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertStringContainsString('Mise à jour de votre échange', $log->subject);
    }

    // ── New message ────────────────────────────────────

    public function test_new_message_uses_system_template_when_enabled(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'new_message',
            'name' => 'New Message',
            'subject' => 'Message de {{ sender_name }}',
            'content_html' => '<p>Message de {{ sender_name }} : {{ message_preview }}</p>',
            'enabled' => true,
        ]);

        $transaction = Transaction::factory()->create([
            'buyer_id' => $this->user->id,
        ]);
        $message = Message::factory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $senderName = $message->sender?->name ?? 'Système';

        $this->user->notify(new NewMessageReceived($transaction, $message));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Message de '.$senderName,
        ]);
    }

    // ── AI budget ──────────────────────────────────────

    public function test_ai_budget_uses_system_template_when_enabled(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'ai_budget_exceeded',
            'name' => 'AI Budget',
            'subject' => 'Budget — {{ scenario_id }}',
            'content_html' => '<p>Coût : {{ current_cost }} / {{ budget_limit }}</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new AiBudgetExceeded(
            scenarioId: 'scen-42',
            currentCost: 50.0,
            budgetLimit: 100.0,
        ));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Budget — scen-42',
        ]);
    }

    public function test_ai_budget_falls_back_when_no_template(): void
    {
        $this->user->notify(new AiBudgetExceeded(
            scenarioId: 'scen-42',
            currentCost: 50.0,
            budgetLimit: 100.0,
        ));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Alerte budget IA — scen-42',
        ]);
    }

    // ── Interpolation ──────────────────────────────────

    public function test_system_template_interpolates_all_core_vars(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => WelcomeNotification::SLUG,
            'name' => 'Welcome',
            'subject' => '{{ first_name }} {{ name }} {{ email }} {{ city }}',
            'content_html' => '<p>{{ first_name }} {{ name }} {{ email }} {{ city }}</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Alice Alice Martin alice@example.com Paris',
        ]);
    }

    public function test_unknown_vars_in_template_are_left_unchanged(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => WelcomeNotification::SLUG,
            'name' => 'Welcome',
            'subject' => 'Hello {{ unknown }}',
            'content_html' => '<p>{{ unknown }}</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Hello {{ unknown }}',
        ]);
    }

    public function test_html_in_template_content_is_escaped_properly(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => WelcomeNotification::SLUG,
            'name' => 'Welcome',
            'subject' => 'Test',
            'content_html' => '<p>{{ first_name }}</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertSame('Test', $log->subject);
    }

    // ── Superadmin routes ──────────────────────────────

    public function test_superadmin_sees_all_orgs(): void
    {
        $orgA = Organization::factory()->create(['is_active' => true, 'slug' => 'org-a']);
        $orgB = Organization::factory()->create(['is_active' => true, 'slug' => 'org-b']);

        SystemEmailTemplate::create([
            'organization_id' => $orgA->id, 'locale' => 'fr', 'slug' => WelcomeNotification::SLUG,
            'name' => 'A Welcome', 'subject' => 'A', 'content_html' => '<p>A</p>',
        ]);
        SystemEmailTemplate::create([
            'organization_id' => $orgB->id, 'locale' => 'en', 'slug' => WelcomeNotification::SLUG,
            'name' => 'B Welcome', 'subject' => 'B', 'content_html' => '<p>B</p>',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.system-email-templates'));

        $response->assertStatus(200);
        $response->assertSee('A Welcome');
        $response->assertSee('B Welcome');
    }

    public function test_superadmin_filters_by_organization(): void
    {
        $orgA = Organization::factory()->create(['is_active' => true, 'slug' => 'org-a']);
        $orgB = Organization::factory()->create(['is_active' => true, 'slug' => 'org-b']);

        SystemEmailTemplate::create([
            'organization_id' => $orgA->id, 'locale' => 'fr', 'slug' => WelcomeNotification::SLUG,
            'name' => 'Only A', 'subject' => 'A', 'content_html' => '<p>A</p>',
        ]);
        SystemEmailTemplate::create([
            'organization_id' => $orgB->id, 'locale' => 'en', 'slug' => WelcomeNotification::SLUG,
            'name' => 'Only B', 'subject' => 'B', 'content_html' => '<p>B</p>',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.system-email-templates', ['organization_id' => $orgA->id]));

        $response->assertStatus(200);
        $response->assertSee('Only A');
        $response->assertDontSee('Only B');
    }

    /**
     * TASK-1230 : admin a nom DETERMINISTE — la page admin rend `full_name`
     * (Faker `firstName()`/`lastName()` dans UserFactory) et
     * `assertDontSee('Bienvenue')` fouille tout le HTML. L'Organization de
     * setUp() porte deja un nom fixe (voir setUp). Meme correction a la
     * racine que TASK-1218 / TASK-1228.
     */
    public function test_superadmin_filters_by_locale(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);
        $admin = User::factory()->create([
            'is_admin' => true,
            'name' => 'Superadmin',
            'first_name' => 'Sentinel',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.system-email-templates', ['locale' => 'en']));

        $response->assertStatus(200);
        $response->assertSee('Welcome');
        $response->assertDontSee('Bienvenue');
    }

    // ── Org-admin routes ───────────────────────────────

    public function test_org_admin_sees_only_their_org_templates(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $otherOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'other-org']);
        $orgAdmin = User::factory()->create(['organization_id' => $otherOrg->id]);

        $otherOrg->update(['admin_id' => $orgAdmin->id]);

        $response = $this->actingAs($orgAdmin)->get(route('organization.admin.system-email-templates', $otherOrg));

        $response->assertStatus(200);

        $response->assertDontSee($this->org->name);
    }

    public function test_org_admin_cannot_see_other_org_via_url(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $otherOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'other-org']);
        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);

        $this->org->update(['admin_id' => $orgAdmin->id]);

        $response = $this->actingAs($orgAdmin)->get(route('organization.admin.system-email-templates', $otherOrg));

        $response->assertStatus(404);
    }

    public function test_org_admin_cannot_edit_other_org_template(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'other-org']);
        // TASK-1694 bug N — ce test a besoin d'une ligne RATTACHEE a l'autre
        // Organization : c'est la garde de tenant qu'il mesure. Le seeder, lui,
        // ne rattache plus rien.
        $this->seedPerOrganizationTemplates();

        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $orgAdmin->id]);

        $otherTemplate = SystemEmailTemplate::where('organization_id', $otherOrg->id)->first();

        $response = $this->actingAs($orgAdmin)->get(
            route('organization.admin.system-email-templates.edit', [$otherOrg, $otherTemplate])
        );

        $response->assertStatus(404);
    }
}
