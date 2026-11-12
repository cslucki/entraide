<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * TASK-1809 (micro-sequence C) — l'apercu FIDELE d'un gabarit systeme.
 *
 * Avant ce correctif, l'iframe de `/admin/system-email-templates/{id}/edit`
 * affichait le HTML brut du textarea : ni habillage, ni interpolation — les
 * `{{ token }}` restaient litteraux. Ce fichier protege le contraire : un
 * rendu REEL (theme + variables de demonstration), jamais un envoi reel.
 */
class TASK1809SystemEmailTemplatePreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
    }

    private function gabarit(array $overrides = []): SystemEmailTemplate
    {
        return SystemEmailTemplate::query()->create(array_merge([
            'slug' => 'preview_1809',
            'locale' => 'fr',
            'organization_id' => null,
            'name' => 'Gabarit apercu T1809',
            'subject' => 'Bonjour {{ recipient_name }}',
            'content_html' => '<h1>T1809_APERCU_TEXTE</h1><p>Pour {{ recipient_name }}</p>',
            'variables' => ['recipient_name'],
            'enabled' => true,
        ], $overrides));
    }

    /** Les variables de demonstration sont reellement interpolees — plus de token litteral. */
    public function test_the_preview_interpolates_demo_variables(): void
    {
        $gabarit = $this->gabarit();

        $reponse = $this->actingAs($this->superAdmin)
            ->postJson(route('admin.system-email-templates.preview', $gabarit), [
                'subject' => $gabarit->subject,
                'content_html' => $gabarit->content_html,
            ])
            ->assertOk();

        $this->assertStringNotContainsString('{{ recipient_name }}', $reponse->json('subject'));
        $this->assertStringNotContainsString('{{ recipient_name }}', $reponse->json('html'));
        $this->assertStringContainsString('T1809_APERCU_TEXTE', $reponse->json('html'));
    }

    /** Le rendu est HABILLE — meme theme que l'envoi reel, pas un fragment nu. */
    public function test_the_preview_carries_the_real_theme(): void
    {
        $gabarit = $this->gabarit();

        $reponse = $this->actingAs($this->superAdmin)
            ->postJson(route('admin.system-email-templates.preview', $gabarit), [
                'subject' => $gabarit->subject,
                'content_html' => $gabarit->content_html,
            ])
            ->assertOk();

        $this->assertStringContainsString('<table', $reponse->json('html'));
        $this->assertStringContainsString('#4338ca', $reponse->json('html'));
    }

    /** L'apercu reflete le DRAFT envoye, pas ce qui est deja enregistre en base. */
    public function test_the_preview_reflects_the_draft_not_the_saved_row(): void
    {
        $gabarit = $this->gabarit();

        $reponse = $this->actingAs($this->superAdmin)
            ->postJson(route('admin.system-email-templates.preview', $gabarit), [
                'subject' => 'Sujet jamais enregistre',
                'content_html' => '<p>T1809_BROUILLON_NON_ENREGISTRE</p>',
            ])
            ->assertOk();

        $this->assertStringContainsString('T1809_BROUILLON_NON_ENREGISTRE', $reponse->json('html'));
        $this->assertStringNotContainsString('T1809_APERCU_TEXTE', $reponse->json('html'));

        // L'apercu n'a RIEN enregistre : la ligne en base garde son contenu d'origine.
        $this->assertSame($gabarit->getOriginal('content_html'), $gabarit->fresh()->content_html);
    }

    /** Aucun email n'est reellement envoye par un apercu. */
    public function test_the_preview_never_sends_a_real_email(): void
    {
        Mail::fake();
        $gabarit = $this->gabarit();

        $this->actingAs($this->superAdmin)
            ->postJson(route('admin.system-email-templates.preview', $gabarit), [
                'subject' => $gabarit->subject,
                'content_html' => $gabarit->content_html,
            ])
            ->assertOk();

        Mail::assertNothingSent();
    }

    /** Un non-admin ne peut pas appeler l'apercu. */
    public function test_a_non_admin_cannot_call_the_preview(): void
    {
        $gabarit = $this->gabarit();
        $membre = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($membre)
            ->postJson(route('admin.system-email-templates.preview', $gabarit), [
                'subject' => $gabarit->subject,
                'content_html' => $gabarit->content_html,
            ])
            ->assertForbidden();
    }

    /** L'apercu d'un gabarit EN utilise des variables de demonstration EN. */
    public function test_the_preview_uses_locale_appropriate_demo_values(): void
    {
        $gabarit = $this->gabarit([
            'locale' => 'en',
            'subject' => 'Hello {{ recipient_name }}',
            'content_html' => '<p>{{ recipient_name }}</p>',
        ]);

        $reponse = $this->actingAs($this->superAdmin)
            ->postJson(route('admin.system-email-templates.preview', $gabarit), [
                'subject' => $gabarit->subject,
                'content_html' => $gabarit->content_html,
            ])
            ->assertOk();

        $this->assertStringContainsString('Jordan Smith', $reponse->json('html'));
    }
}
