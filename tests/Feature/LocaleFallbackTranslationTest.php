<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class LocaleFallbackTranslationTest extends TestCase
{
    public function test_french_translation_for_home_enter_main_loop(): void
    {
        App::setLocale('fr');
        $this->assertEquals('Entrer dans la boucle principale', __('home.enter_main_loop'));
    }

    public function test_english_translation_for_home_enter_main_loop(): void
    {
        App::setLocale('en');
        $this->assertEquals('Enter the main loop', __('home.enter_main_loop'));
    }

    public function test_french_navigation_keys(): void
    {
        App::setLocale('fr');
        $this->assertEquals('Échanges', __('navigation.exchanges'));
        $this->assertEquals('Tableau de bord', __('navigation.dashboard'));
        $this->assertEquals('Déconnexion', __('navigation.logout'));
    }

    public function test_english_navigation_keys(): void
    {
        App::setLocale('en');
        $this->assertEquals('Exchanges', __('navigation.exchanges'));
        $this->assertEquals('Dashboard', __('navigation.dashboard'));
        $this->assertEquals('Log out', __('navigation.logout'));
    }

    public function test_supports_replacement_parameters(): void
    {
        App::setLocale('fr');
        $this->assertEquals('Proposer mon aide', __('navigation.offer_service', ['service' => 'service']));

        App::setLocale('en');
        $this->assertEquals('Offer my help', __('navigation.offer_service', ['service' => 'service']));
    }
}
