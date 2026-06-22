<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class SetLocaleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        if (app()->bound('current_organization')) {
            app()->forgetInstance('current_organization');
        }
    }

    protected function tearDown(): void
    {
        if (app()->bound('current_organization')) {
            app()->forgetInstance('current_organization');
        }

        parent::tearDown();
    }

    public function test_uses_organization_locale_when_set_to_en(): void
    {
        $org = Organization::factory()->create(['locale' => 'en', 'is_active' => true]);
        app()->instance('current_organization', $org);

        $request = Request::create('/', 'GET');
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals('en', App::getLocale());
    }

    public function test_uses_organization_locale_when_set_to_fr(): void
    {
        $org = Organization::factory()->create(['locale' => 'fr', 'is_active' => true]);
        app()->instance('current_organization', $org);

        $request = Request::create('/', 'GET');
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals('fr', App::getLocale());
    }

    public function test_falls_back_to_config_locale_when_no_org_or_browser_locale(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals(config('app.locale', 'fr'), App::getLocale());
    }

    public function test_organization_locale_takes_priority_over_browser_locale(): void
    {
        $org = Organization::factory()->create(['locale' => 'fr', 'is_active' => true]);
        app()->instance('current_organization', $org);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals('fr', App::getLocale());
    }

    public function test_uses_config_locale_when_no_bound_organization(): void
    {
        $request = Request::create('/', 'GET');
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals(config('app.locale', 'fr'), App::getLocale());
    }
}
