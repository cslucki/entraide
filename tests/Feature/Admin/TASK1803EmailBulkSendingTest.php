<?php

namespace Tests\Feature\Admin;

use App\Models\BulkEmailSend;
use App\Models\BulkEmailSendRecipient;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * TASK-1803 — l'outil « Envoi emails » du superadmin (`/admin/email-bulksending`).
 *
 * Cyril : cibler toutes les Organizations ou une seule, tous les membres ou
 * seulement ceux sans présentation (bio vide), un contenu libre (brut ou
 * HTML via TipTap) ou un modèle existant, et retrouver l'envoi dans
 * « Emails envoyés ».
 *
 * Pas de vraie file d'attente (`QUEUE_CONNECTION=sync`) : l'envoi se fait en
 * lots plafonnés (50), d'où les tests sur le comptage de lots et le statut
 * de progression plutôt qu'un simple "tout part d'un coup".
 */
class TASK1803EmailBulkSendingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // TASK-1803 — `UserFactory` assigne TOUJOURS une Organization par
        // defaut (la "par defaut" resolue, ou une neuve a defaut) : sans ce
        // `null` explicite, ce compte superadmin serait lui-meme un "membre"
        // d'une Organization, et fausserait silencieusement tous les
        // comptages "toutes les Organizations" ci-dessous.
        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
            'organization_id' => null,
        ]);
        Mail::fake();
    }

    private function member(Organization $org, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $org->id,
            'email_verified_at' => now(),
        ], $overrides));
    }

    // =========================================================================
    // Comptage des 4 combinaisons cible x filtre
    // =========================================================================

    public function test_preview_counts_all_organizations_all_members(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->member($orgA);
        $this->member($orgA);
        $this->member($orgB);
        // Banni : ne doit JAMAIS etre compte (activeAccount()).
        $this->member($orgB, ['banned_at' => now()]);

        $html = $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.preview'), $this->payload())
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('admin.bulk_email_recipient_count', ['count' => 3])), $html);
    }

    public function test_preview_counts_one_organization_all_members(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->member($orgA);
        $this->member($orgA);
        $this->member($orgB);

        $html = $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.preview'), $this->payload(['organization_id' => $orgA->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('admin.bulk_email_recipient_count', ['count' => 2])), $html);
    }

    public function test_preview_counts_all_organizations_no_presentation(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->member($orgA, ['bio' => null]);
        $this->member($orgA, ['bio' => 'Une presentation complete.']);
        $this->member($orgB, ['bio' => '']);

        $html = $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.preview'), $this->payload(['member_filter' => 'no_presentation']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('admin.bulk_email_recipient_count', ['count' => 2])), $html);
    }

    public function test_preview_counts_one_organization_no_presentation(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->member($orgA, ['bio' => null]);
        $this->member($orgA, ['bio' => 'Une presentation complete.']);
        // Meme filtre, AUTRE Organization : ne doit pas etre compte ici.
        $this->member($orgB, ['bio' => null]);

        $html = $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.preview'), $this->payload([
                'organization_id' => $orgA->id,
                'member_filter' => 'no_presentation',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('admin.bulk_email_recipient_count', ['count' => 1])), $html);
    }

    // =========================================================================
    // L'apercu interpole les variables du destinataire courant, et navigue
    // entre les destinataires reels (correctif demande par Cyril apres
    // recette : l'ecran affichait `{{ first_name }}` au mot pres).
    // =========================================================================

    public function test_preview_interpolates_variables_for_the_first_recipient(): void
    {
        $org = Organization::factory()->create(['name' => 'OFSH']);
        $this->member($org, ['first_name' => 'Stephanie']);

        $html = $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.preview'), $this->payload([
                'organization_id' => $org->id,
                'content_raw' => 'Bonjour {{ first_name }}, ton organisation : {{ organization }}.',
            ]))
            ->assertOk()
            ->getContent();

        // Pas d'assertion "ne contient pas {{ first_name }}" : le formulaire
        // repasse le `content_raw` BRUT en input cache pour `store()` (il
        // doit survivre tel quel a chaque destinataire du lot), seul le
        // bloc d'APERCU doit montrer la valeur interpolee.
        $this->assertStringContainsString('Bonjour Stephanie, ton organisation : OFSH.', $html);
    }

    public function test_preview_next_and_previous_navigate_between_real_recipients(): void
    {
        $org = Organization::factory()->create();
        // `resolveRecipients()` trie par `id` (UUID ordonnes) : l'ordre de
        // creation EST l'ordre de l'apercu, comme ailleurs dans ce fichier.
        $this->member($org, ['first_name' => 'Alice']);
        $this->member($org, ['first_name' => 'Bruno']);

        $payload = $this->payload([
            'organization_id' => $org->id,
            'content_raw' => 'Bonjour {{ first_name }}.',
        ]);

        $first = $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.preview'), $payload)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bonjour Alice.', $first);
        $this->assertStringNotContainsString('Bonjour Bruno.', $first);

        $second = $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.preview'), array_merge($payload, ['preview_index' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bonjour Bruno.', $second);
        $this->assertStringNotContainsString('Bonjour Alice.', $second);

        // Un index perime (le filtre ne cible plus que 2 personnes) retombe
        // sur le DERNIER destinataire plutot que de planter.
        $outOfRange = $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.preview'), array_merge($payload, ['preview_index' => 999]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bonjour Bruno.', $outOfRange);
    }

    // =========================================================================
    // store() fige les destinataires, sendBatch() plafonne a 50
    // =========================================================================

    public function test_store_snapshots_recipients_and_caps_at_zero_sent(): void
    {
        $org = Organization::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->member($org);
        }

        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.store'), $this->payload(['organization_id' => $org->id]))
            ->assertRedirect();

        $send = BulkEmailSend::sole();
        $this->assertSame(5, $send->total_recipients);
        $this->assertSame(0, $send->sent_count);
        $this->assertSame(BulkEmailSendRecipient::query()->where('bulk_email_send_id', $send->id)->count(), 5);
        $this->assertSame(5, BulkEmailSendRecipient::where('status', 'pending')->count());
    }

    public function test_send_batch_processes_at_most_50_and_leaves_rest_pending(): void
    {
        $org = Organization::factory()->create();
        for ($i = 0; $i < 60; $i++) {
            $this->member($org);
        }

        $response = $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.store'), $this->payload(['organization_id' => $org->id]));
        $send = BulkEmailSend::sole();

        $this->actingAs($this->superAdmin)->post(route('admin.email-bulksending.batch', $send))->assertRedirect();

        $send->refresh();
        $this->assertSame(50, $send->sent_count + $send->failed_count);
        $this->assertSame(10, BulkEmailSendRecipient::where('bulk_email_send_id', $send->id)->where('status', 'pending')->count());
        $this->assertSame(BulkEmailSend::STATUS_SENDING, $send->status);

        // Second lot : les 10 restants, l'envoi passe en termine.
        $this->actingAs($this->superAdmin)->post(route('admin.email-bulksending.batch', $send))->assertRedirect();
        $send->refresh();
        $this->assertSame(60, $send->sent_count + $send->failed_count);
        $this->assertSame(BulkEmailSend::STATUS_COMPLETED, $send->status);
    }

    public function test_store_with_zero_matching_recipients_stays_a_draft_and_errors_back(): void
    {
        // Aucun membre dans aucune Organization : le filtre ne matche personne.
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.store'), $this->payload())
            ->assertRedirect(route('admin.email-bulksending'))
            ->assertSessionHasErrors('organization_id');

        // La ligne d'en-tete est conservee (audit : « quelqu'un a tente un
        // envoi qui n'a matche personne »), mais JAMAIS en etat `sending` —
        // sans destinataire, `show()` n'aurait rien a progresser.
        $send = BulkEmailSend::sole();
        $this->assertSame(0, $send->total_recipients);
        $this->assertSame(BulkEmailSend::STATUS_DRAFT, $send->status);
        $this->assertSame(0, BulkEmailSendRecipient::count());
    }

    // =========================================================================
    // Format brut vs HTML vs modele
    // =========================================================================

    public function test_plain_format_sends_as_text_without_markdown_conversion(): void
    {
        $org = Organization::factory()->create();
        $this->member($org);

        $send = $this->createAndSendOne($org, [
            'content_format' => 'plain',
            'content_raw' => 'Ceci est **gras** en markdown.',
        ]);

        $log = EmailLog::sole();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        // Le format brut NE DOIT PAS appliquer markdown() : le `**gras**`
        // reste litteral, jamais converti en `<strong>`.
        $this->assertStringContainsString('**gras**', $log->body_html);
        $this->assertStringNotContainsString('<strong>', $log->body_html);
    }

    public function test_html_format_sends_markdown_converted_to_html(): void
    {
        $org = Organization::factory()->create();
        $this->member($org);

        $this->createAndSendOne($org, [
            'content_format' => 'html',
            'content_raw' => 'Ceci est **gras** en markdown.',
        ]);

        $log = EmailLog::sole();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertStringContainsString('<strong>gras</strong>', $log->body_html);
    }

    public function test_template_source_sends_template_content_html_untouched_by_markdown(): void
    {
        $org = Organization::factory()->create();
        $member = $this->member($org, ['first_name' => 'Camille']);

        $template = EmailTemplate::factory()->create([
            'subject' => 'Bonjour {{ first_name }}',
            // Du HTML ecrit a la main : jamais passe par markdown() au send,
            // sinon ces balises seraient echappees en texte litteral.
            'content_html' => '<p>Bienvenue <strong>{{ first_name }}</strong> !</p>',
        ]);

        $this->actingAs($this->superAdmin)->post(route('admin.email-bulksending.store'), [
            'organization_id' => $org->id,
            'member_filter' => 'all',
            'content_source' => 'template',
            'email_template_id' => $template->id,
        ])->assertRedirect();

        $send = BulkEmailSend::sole();
        $this->actingAs($this->superAdmin)->post(route('admin.email-bulksending.batch', $send));

        $log = EmailLog::sole();
        $this->assertSame('Bonjour Camille', $log->subject);
        $this->assertStringContainsString('<strong>Camille</strong>', $log->body_html);
        $this->assertSame($template->id, $log->template_id);
    }

    public function test_variable_interpolation_is_per_recipient(): void
    {
        $org = Organization::factory()->create();
        $this->member($org, ['first_name' => 'Alice']);
        $this->member($org, ['first_name' => 'Bruno']);

        $this->createAndSendOne($org, [
            'content_format' => 'plain',
            'content_raw' => 'Bonjour {{ first_name }} !',
        ], sendBatch: false);

        $send = BulkEmailSend::sole();
        $this->actingAs($this->superAdmin)->post(route('admin.email-bulksending.batch', $send));

        $bodies = EmailLog::pluck('body_html')->sort()->values();
        $this->assertSame(['Bonjour Alice !', 'Bonjour Bruno !'], $bodies->all());
    }

    // =========================================================================
    // Journalisation visible sur /admin/email-logs
    // =========================================================================

    public function test_email_log_written_with_bulk_email_source_and_visible_on_logs_screen(): void
    {
        $org = Organization::factory()->create();
        $member = $this->member($org);

        $this->createAndSendOne($org, [
            'content_format' => 'plain',
            'content_raw' => 'Un message.',
        ]);

        $log = EmailLog::sole();
        $this->assertSame('bulk-email', $log->data['source']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.email-logs', ['source' => 'bulk-email']))
            ->assertOk()
            ->assertSee($member->email);
    }

    // =========================================================================
    // Garde SuperAdmin sur chaque action
    // =========================================================================

    public function test_non_superadmin_is_blocked_from_every_bulk_sending_action(): void
    {
        $org = Organization::factory()->create();
        $member = $this->member($org);
        $send = BulkEmailSend::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($member)->get(route('admin.email-bulksending'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.email-bulksending.preview'), $this->payload())->assertForbidden();
        $this->actingAs($member)->post(route('admin.email-bulksending.store'), $this->payload())->assertForbidden();
        $this->actingAs($member)->get(route('admin.email-bulksending.show', $send))->assertForbidden();
        $this->actingAs($member)->post(route('admin.email-bulksending.batch', $send))->assertForbidden();
    }

    // =========================================================================
    // Aides
    // =========================================================================

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'organization_id' => 'all',
            'member_filter' => 'all',
            'content_source' => 'custom',
            'subject' => 'Sujet de test',
            'content_format' => 'plain',
            'content_raw' => 'Contenu de test.',
        ], $overrides);
    }

    /** @param array<string, mixed> $contentOverrides */
    private function createAndSendOne(Organization $org, array $contentOverrides, bool $sendBatch = true): BulkEmailSend
    {
        $this->actingAs($this->superAdmin)->post(route('admin.email-bulksending.store'), $this->payload(array_merge(
            ['organization_id' => $org->id],
            $contentOverrides,
        )))->assertRedirect();

        $send = BulkEmailSend::sole();

        if ($sendBatch) {
            $this->actingAs($this->superAdmin)->post(route('admin.email-bulksending.batch', $send));
        }

        return $send;
    }
}
