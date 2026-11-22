<?php

namespace Tests\Feature;

use App\Models\SystemEmailTemplate;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1883 — gabarit systeme global FR/EN `weekly_help_digest` : la migration
 * et le seeder portent EXACTEMENT les memes textes.
 */
class TASK1883WeeklyHelpDigestTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_migration_seme_le_gabarit_global_identique_au_catalogue(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $ligne = SystemEmailTemplate::query()->whereNull('organization_id')
                ->where('slug', 'weekly_help_digest')->where('locale', $locale)->firstOrFail();
            $catalogue = SystemEmailTemplateSeeder::rowFor('weekly_help_digest', $locale);

            $this->assertSame($catalogue['subject'], $ligne->subject);
            $this->assertSame($catalogue['content_html'], $ligne->content_html);
            $this->assertStringContainsString('{demande_aide;3}', $ligne->content_html);
            $this->assertStringContainsString('{proposition_aide;3}', $ligne->content_html);
            $this->assertStringContainsString('{billets_blog;3}', $ligne->content_html);
            $this->assertStringContainsString('{{ unsubscribe_url }}', $ligne->content_html);
        }
    }
}
