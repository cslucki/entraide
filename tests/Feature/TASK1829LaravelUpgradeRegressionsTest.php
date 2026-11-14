<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationMember;
use App\Http\Middleware\EnsureOrganizationMemberExplained;
use Illuminate\Support\Facades\Route;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * TASK-1829 — deux regressions DECOUVERTES par la montee Laravel 13.7 ->
 * 13.42 (prerequis Managed Queue, mandat §1), ni l'une ni l'autre causee
 * par le code de notifications lui-meme. Gardees ICI, independamment des
 * suites deja existantes qui les ont revelees
 * (`TASK1623RemoteUploadTest`, `TASK1732SiblingAccountByInvitationTest`),
 * pour que la CAUSE reste explicite si l'une d'elles rougit de nouveau sur
 * une future montee de version.
 */
class TASK1829LaravelUpgradeRegressionsTest extends TestCase
{
    /**
     * Laravel 13.19+ embarque son propre `illuminate/image`, lie au MEME nom
     * de service `'image'` qu'`intervention/image-laravel`. Sans
     * {@see \App\Providers\InterventionImageBindingProvider}, `app('image')`
     * rend `Illuminate\Image\ImageManager` — une classe SANS `decode()` —
     * et toute la chaine d'upload d'images (`CreateFeedPost`, `MessageThread`,
     * `LoopChat`, `EditFeedPost`, `GenerateServiceThumbnail`) leve.
     */
    public function test_the_image_binding_resolves_to_intervention_and_not_the_native_laravel_manager(): void
    {
        $image = app('image');

        $this->assertInstanceOf(ImageManager::class, $image);
        $this->assertTrue(method_exists($image, 'decode'), 'le manager resolu doit exposer decode()');
    }

    /** La reliaison reste definitive, meme apres une seconde resolution. */
    public function test_the_image_binding_stays_correct_on_a_second_resolution(): void
    {
        app('image');

        $this->assertInstanceOf(ImageManager::class, app('image'));
    }

    /**
     * `Router::resolveMiddleware()` (Laravel 13.42) exclut un middleware
     * parametre des que sa CLASSE sous-jacente — ou une classe DONT IL
     * HERITE — figure dans la liste d'exclusion, et plus seulement par
     * correspondance exacte de chaine. Le groupe du tableau de bord fait
     * `Route::middleware(...)->withoutMiddleware(['organization.member'])` :
     * si la garde « explain » redevenait un jour un parametre de la MEME
     * classe (`organization.member:explain`) plutot qu'une classe
     * independante, cette exclusion la retirerait de nouveau en silence —
     * c'est exactement ce qui a vide la garde du tableau de bord (mesure
     * dans `TASK1732SiblingAccountByInvitationTest`).
     */
    public function test_the_dashboard_route_keeps_its_membership_guard_after_the_without_middleware_exclusion(): void
    {
        $route = Route::getRoutes()->getByName('organization.dashboard');

        $this->assertNotNull($route, 'la route organization.dashboard doit exister');

        // `gatherMiddleware()` rend les ALIAS bruts, AVANT exclusion : c'est
        // `Router::gatherRouteMiddleware()` qui applique la pipeline complete
        // (resolution + exclusion), celle que le dispatch reel emprunte.
        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertContains(
            EnsureOrganizationMemberExplained::class,
            $middleware,
            'le tableau de bord doit porter sa propre garde, en classe independante'
        );
        $this->assertNotContains(
            EnsureOrganizationMember::class,
            $middleware,
            'la garde PLATE (404 muet) ne doit jamais rester EN PLUS de la variante explain'
        );
    }
}
