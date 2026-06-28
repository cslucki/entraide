<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\Organization;
use App\Models\User;
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

    public function test_user_preferred_locale_en_takes_priority_over_org_fr(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'en']);
        $org = Organization::factory()->create(['locale' => 'fr', 'is_active' => true]);

        app()->instance('current_organization', $org);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals('en', App::getLocale());
    }

    public function test_user_preferred_locale_fr_takes_priority_over_org_en(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'fr']);
        $org = Organization::factory()->create(['locale' => 'en', 'is_active' => true]);

        app()->instance('current_organization', $org);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals('fr', App::getLocale());
    }

    public function test_user_without_preferred_locale_uses_org_locale(): void
    {
        $user = User::factory()->create(['preferred_locale' => null]);
        $org = Organization::factory()->create(['locale' => 'en', 'is_active' => true]);

        app()->instance('current_organization', $org);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals('en', App::getLocale());
    }

    public function test_session_locale_stays_above_user_preferred_locale(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'fr']);
        $org = Organization::factory()->create(['locale' => 'en', 'is_active' => true]);

        app()->instance('current_organization', $org);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put('locale', 'en');
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals('en', App::getLocale());
    }

    public function test_visitor_without_user_uses_org_locale(): void
    {
        $org = Organization::factory()->create(['locale' => 'en', 'is_active' => true]);

        app()->instance('current_organization', $org);

        $request = Request::create('/', 'GET');
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals('en', App::getLocale());
    }

    public function test_invalid_preferred_locale_does_not_break_middleware(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'de']);
        $org = Organization::factory()->create(['locale' => 'fr', 'is_active' => true]);

        app()->instance('current_organization', $org);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertEquals('fr', App::getLocale());
    }
}
