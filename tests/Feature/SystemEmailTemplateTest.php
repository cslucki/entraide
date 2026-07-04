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
        $this->org = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'main']);
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

    public function test_seeder_creates_templates_for_active_orgs(): void
    {
        Organization::factory()->create(['is_active' => true, 'slug' => 'org-a']);
        Organization::factory()->create(['is_active' => true, 'slug' => 'org-b']);
        Organization::factory()->create(['is_active' => false, 'slug' => 'inactive']);

        $this->seed(SystemEmailTemplateSeeder::class);

        $this->assertSame(5 * 2 * 3, SystemEmailTemplate::count());
        $this->assertSame(10, SystemEmailTemplate::where('organization_id', $this->org->id)->count());
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

        $template = SystemEmailTemplate::where('slug', 'welcome')
            ->where('locale', 'fr')
            ->where('organization_id', $this->org->id)
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
            'slug' => 'welcome',
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

    public function test_global_template_not_used_when_user_has_org(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'welcome',
            'name' => 'Global Welcome',
            'subject' => 'Global {{ name }}',
            'content_html' => '<p>Global</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Bienvenue sur Entraide !',
        ]);
    }

    // ── Org + locale resolution ────────────────────────

    public function test_resolves_template_by_org_and_locale(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $template = SystemEmailTemplate::where('slug', 'welcome')
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
        $this->seed(SystemEmailTemplateSeeder::class);

        SystemEmailTemplate::where('organization_id', $otherOrg->id)
            ->where('slug', 'welcome')
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

        SystemEmailTemplate::where('slug', 'welcome')
            ->where('locale', 'fr')
            ->where('organization_id', $this->org->id)
            ->update(['enabled' => false]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
        ]);

        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertStringContainsString('Bienvenue sur Entraide', $log->subject);
    }

    // ── Welcome ────────────────────────────────────────

    public function test_welcome_uses_system_template_when_enabled(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'welcome',
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
            'subject' => 'Bienvenue sur Entraide !',
        ]);
    }

    public function test_welcome_falls_back_when_system_template_disabled(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'welcome',
            'name' => 'Welcome',
            'subject' => 'Bienvenue {{ name }} !',
            'content_html' => '<h1>Bienvenue {{ name }} !</h1>',
            'enabled' => false,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Bienvenue sur Entraide !',
        ]);
    }

    public function test_system_email_view_renders_within_mail_layout(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'welcome',
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
            'slug' => 'welcome',
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
            'slug' => 'welcome',
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
            'slug' => 'welcome',
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
            'organization_id' => $orgA->id, 'locale' => 'fr', 'slug' => 'welcome',
            'name' => 'A Welcome', 'subject' => 'A', 'content_html' => '<p>A</p>',
        ]);
        SystemEmailTemplate::create([
            'organization_id' => $orgB->id, 'locale' => 'en', 'slug' => 'welcome',
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
            'organization_id' => $orgA->id, 'locale' => 'fr', 'slug' => 'welcome',
            'name' => 'Only A', 'subject' => 'A', 'content_html' => '<p>A</p>',
        ]);
        SystemEmailTemplate::create([
            'organization_id' => $orgB->id, 'locale' => 'en', 'slug' => 'welcome',
            'name' => 'Only B', 'subject' => 'B', 'content_html' => '<p>B</p>',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.system-email-templates', ['organization_id' => $orgA->id]));

        $response->assertStatus(200);
        $response->assertSee('Only A');
        $response->assertDontSee('Only B');
    }

    public function test_superadmin_filters_by_locale(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);
        $admin = User::factory()->create(['is_admin' => true]);

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

        $response->assertStatus(403);
    }

    public function test_org_admin_cannot_edit_other_org_template(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'other-org']);
        $this->seed(SystemEmailTemplateSeeder::class);

        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $orgAdmin->id]);

        $otherTemplate = SystemEmailTemplate::where('organization_id', $otherOrg->id)->first();

        $response = $this->actingAs($orgAdmin)->get(
            route('organization.admin.system-email-templates.edit', [$otherOrg, $otherTemplate])
        );

        $response->assertStatus(403);
    }
}
