<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1897 — les vitrines publiques ne chargent plus aucune police depuis
 * Google (fonts.googleapis.com / fonts.gstatic.com) : l'IP du visiteur ne part
 * plus chez Google. Bunny Fonts, deja utilise par les layouts, les remplace.
 */
class TASK1897BunnyFontsInsteadOfGoogleFontsTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function vues(): array
    {
        return [
            'about' => ['about.blade.php', ['inter:400,500,600,700,800,900']],
            'about-ofsh' => ['organization/about-ofsh.blade.php', ['inter:400,500,600,700,800,900']],
            'about-launchpals' => ['organization/about-launchpals.blade.php', ['inter:400,500,600,700,800,900']],
            'ofsh-hero' => ['organization/ofsh-hero.blade.php', ['inter:400,500,600,700,800,900']],
            'hero-v2' => ['organization/hero-v2.blade.php', ['inter:400,500,600,700,800,900|caveat:500,600']],
            'artscilab-hero' => ['organization/artscilab-hero.blade.php', ['archivo:600,700,800,900|caveat:600,700|inter:400,500,600,700']],
        ];
    }

    /** @param  list<string>  $familles */
    #[DataProvider('vues')]
    public function test_la_vue_charge_ses_polices_chez_bunny_et_jamais_chez_google(string $vue, array $familles): void
    {
        $source = file_get_contents(resource_path('views/'.$vue));

        $this->assertStringNotContainsString('fonts.googleapis.com', $source);
        $this->assertStringNotContainsString('fonts.gstatic.com', $source);

        foreach ($familles as $famille) {
            $this->assertStringContainsString('https://fonts.bunny.net/css?family='.$famille.'&display=swap', $source,
                'Familles ou graisses perdues lors du passage a Bunny Fonts.');
        }
    }
}
