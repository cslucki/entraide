<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1809 (micro-sequence A) — le socle visuel premium BouclePro.
 *
 * Toute notification rendue via `->markdown()` (les 7 notifications
 * natives, et tout gabarit administre passant par `emails.system-email`)
 * doit desormais porter la palette BouclePro (`config('mail.markdown.theme')
 * => 'bouclepro'`), pas le theme generique livre par Laravel. La structure
 * en tableaux elle-meme vient du framework, deja robuste — ce correctif ne
 * change QUE la palette, voir `resources/views/vendor/mail/html/themes/bouclepro.css`.
 */
class TASK1809MarkdownThemeTest extends TestCase
{
    use RefreshDatabase;

    /** Le theme configure est bien le notre, pas le defaut du framework. */
    public function test_the_configured_markdown_theme_is_bouclepro(): void
    {
        $this->assertSame('bouclepro', config('mail.markdown.theme'));
    }

    /** Le fichier de theme existe bien la ou la configuration le pointe. */
    public function test_the_theme_file_exists_at_the_configured_path(): void
    {
        $paths = config('mail.markdown.paths');

        $this->assertNotEmpty($paths);
        $this->assertFileExists($paths[0].'/html/themes/bouclepro.css');
    }

    /** Une notification native rendue porte la couleur de marque, pas le noir par defaut du framework. */
    public function test_a_native_notification_carries_the_bouclepro_accent_color(): void
    {
        $organization = Organization::factory()->create();
        $membre = User::factory()->create(['organization_id' => $organization->id, 'preferred_locale' => 'fr']);

        $rendu = (new WelcomeNotification)->toMail($membre)->render();

        $this->assertStringContainsString('#4338ca', $rendu);
        $this->assertStringContainsString('<table', $rendu);
    }
}
