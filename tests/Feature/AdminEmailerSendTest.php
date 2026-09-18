<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CapturesMailHtml;
use Tests\TestCase;

class AdminEmailerSendTest extends TestCase
{
    use CapturesMailHtml;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_admin_can_access_send_form()
    {
        $template = EmailTemplate::factory()->create();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-templates.send', $template));

        $response->assertStatus(200);
        $response->assertViewIs('admin.email-templates.send');
        $response->assertViewHas('template');
        $response->assertViewHas('users');
    }

    public function test_send_form_shows_available_variables()
    {
        $template = EmailTemplate::factory()->create([
            'subject' => 'Bonjour {{ first_name }}',
            'content_html' => '<p>Bonjour {{ first_name }}</p>',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-templates.send', $template));

        $response->assertStatus(200);
    }

    public function test_admin_can_send_to_single_user()
    {
        $this->captureMailHtml();

        $template = EmailTemplate::factory()->create([
            'subject' => 'Bonjour {{ first_name }}',
            'content_html' => '<p>Bonjour {{ first_name }}</p>',
        ]);
        $user = User::factory()->create(['first_name' => 'Jean']);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
            ]);

        $response->assertRedirect(route('admin.email-templates.show', $template));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('email_logs', [
            'template_id' => $template->id,
            'user_id' => $user->id,
            'to_email' => $user->email,
            'status' => 'sent',
        ]);

        // TASK-1423 — l'envoi est MESURE, pas suppose : un seul Mail::html,
        // au bon destinataire, avec l'objet interpole.
        $this->assertSame(1, $this->capturedMailCount());
        $this->assertSame([$user->email], $this->capturedMailTo());
        $this->assertSame('Bonjour Jean', $this->capturedMailSubject());
    }

    public function test_send_without_confirmation_redirects_to_confirm_for_multiple()
    {
        $template = EmailTemplate::factory()->create();
        $users = User::factory()->count(2)->create();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => $users->pluck('id')->toArray(),
            ]);

        $response->assertRedirect(route('admin.email-templates.send.confirm', [
            'emailTemplate' => $template,
            'user_ids' => $users->pluck('id')->toArray(),
        ]));
    }

    public function test_max_50_recipients_is_enforced()
    {
        $template = EmailTemplate::factory()->create();
        $userIds = User::factory()->count(51)->create()->pluck('id')->toArray();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => $userIds,
                'confirmed' => '1',
            ]);

        $response->assertSessionHasErrors('user_ids');
    }

    public function test_duplicate_user_ids_are_deduplicated()
    {
        $this->captureMailHtml();

        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id, $user->id, $user->id],
                'confirmed' => '1',
            ]);

        $response->assertRedirect(route('admin.email-templates.show', $template));

        $this->assertDatabaseHas('email_logs', [
            'template_id' => $template->id,
            'user_id' => $user->id,
        ]);
        $this->assertEquals(1, EmailLog::where('template_id', $template->id)->count());
        $this->assertSame(1, $this->capturedMailCount(), 'un seul Mail::html pour trois ids identiques');
    }

    public function test_email_log_is_created_per_recipient()
    {
        $this->captureMailHtml();

        $template = EmailTemplate::factory()->create();
        $users = User::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => $users->pluck('id')->toArray(),
                'confirmed' => '1',
            ]);

        $this->assertEquals(3, EmailLog::where('template_id', $template->id)->count());
        $this->assertSame(3, $this->capturedMailCount());
        $this->assertEqualsCanonicalizing($users->pluck('email')->all(), array_merge($this->capturedMailTo(0), $this->capturedMailTo(1), $this->capturedMailTo(2)));
    }

    public function test_email_log_data_includes_emailer_source()
    {
        $this->captureMailHtml();

        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
            ]);

        $log = EmailLog::where('template_id', $template->id)->first();
        $this->assertEquals('emailer', $log->data['source']);
    }

    /**
     * TASK-1423 — `Mail::assertNothingOutgoing()` etait un faux oracle :
     * `Mail::fake()` retransmet `Mail::html()` au vrai mailer, donc rien
     * n'etait jamais compte comme « sortant ». Ce que l'on peut prouver,
     * c'est que l'UNIQUE chemin de sortie est `Mail::html()` (intercepte
     * ici, sans transport) et qu'il a ete emprunte exactement une fois.
     */
    public function test_no_real_emails_are_sent()
    {
        $this->captureMailHtml();

        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
            ]);

        $this->assertSame(1, $this->capturedMailCount(), 'un envoi, intercepte avant tout transport');
        $this->assertSame([$user->email], $this->capturedMailTo());
    }
}
