<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1885 C10 (demande Cyril, 22/11) — l'email de confirmation d'inscription
 * demande de verifier que « relai-smtp.net » n'arrive pas dans les spams.
 *
 * La migration COMPLETE le gabarit : elle ajoute un paragraphe a la fin,
 * conserve une edition humaine, ne duplique rien si on la rejoue, et `down()`
 * ne retire que ce qu'elle a pose.
 */
class TASK1885VerificationEmailSpamHintTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'confirmation-email-suite-inscription';

    private function migration(): object
    {
        return require database_path('migrations/2026_11_23_210000_add_spam_hint_to_verification_email_template.php');
    }

    private function contenu(string $locale): string
    {
        return (string) DB::table('system_email_templates')->where('slug', self::SLUG)->where('locale', $locale)->value('content_html');
    }

    public function test_seeded_template_already_carries_the_hint(): void
    {
        $this->seed(\Database\Seeders\SystemEmailTemplateSeeder::class);

        $this->assertStringContainsString('relai-smtp.net', $this->contenu('fr'));
        $this->assertStringContainsString('relai-smtp.net', $this->contenu('en'));
        $this->assertStringContainsString('{{ url }}', $this->contenu('fr'));
    }

    public function test_migration_appends_keeps_human_edits_is_idempotent_and_reversible(): void
    {
        $this->seed(\Database\Seeders\SystemEmailTemplateSeeder::class);

        // Etat « deja installe » : une version personnalisee, sans la mention.
        $perso = '<h1>Salut {{ name }}</h1><p>Texte maison.</p><p><a href="{{ url }}">Go</a></p>';
        DB::table('system_email_templates')->where('slug', self::SLUG)->where('locale', 'fr')->update(['content_html' => $perso]);

        $this->migration()->up();
        $apres = $this->contenu('fr');
        $this->assertStringStartsWith($perso, $apres);
        $this->assertStringEndsWith('(spam).</p>', $apres);
        $this->assertSame(1, substr_count($apres, 'relai-smtp.net'));

        // Rejouee : rien de plus.
        $this->migration()->up();
        $this->assertSame($apres, $this->contenu('fr'));

        // La version anglaise portait deja la mention (seeder) : intacte.
        $this->assertSame(1, substr_count($this->contenu('en'), 'relai-smtp.net'));

        $this->migration()->down();
        $this->assertSame($perso, $this->contenu('fr'));
    }
}
