<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/** FICHIER TEMPORAIRE D'AUDIT — a supprimer. */
class ZzTempAuditPilesTest extends TestCase
{
    public function test_piles_effectives(): void
    {
        $noms = [
            'password.update',
            'profile.edit',
            'organization.profile.edit',
            'profile.update',
            'organization.profile.update',
            'account.close',
            'password.confirm',
        ];

        foreach ($noms as $nom) {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(fn ($r) => $r->getName() === $nom);

            if ($route === null) {
                dump($nom.' => ROUTE ABSENTE');

                continue;
            }

            $pile = RouteFacade::gatherRouteMiddleware($route);
            $aAuth = in_array(\Illuminate\Auth\Middleware\Authenticate::class, $pile, true);
            $aVerif = in_array(\App\Http\Middleware\RequireVerifiedEmail::class, $pile, true);
            $aApprob = in_array(\App\Http\Middleware\RequireApprovedAccount::class, $pile, true);

            dump(sprintf(
                '%-34s uri=%-34s auth=%-5s RequireVerifiedEmail=%-5s RequireApprovedAccount=%s',
                $nom,
                implode('|', $route->methods()).' /'.$route->uri(),
                var_export($aAuth, true),
                var_export($aVerif, true),
                var_export($aApprob, true),
            ));
        }

        $this->assertTrue(true);
    }
}
