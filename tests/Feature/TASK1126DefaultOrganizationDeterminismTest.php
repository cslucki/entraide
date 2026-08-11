<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveUrlOrganization;
use App\Models\Organization;
use App\Support\Tenancy\DefaultOrganizationResolver;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Le tenant de repli ne doit jamais dependre du plan de requete.
 *
 * Deux endroits resolvent une Organization par defaut. TASK-1121 avait durci
 * `DefaultOrganizationResolver` ; **son jumeau dans le middleware est reste
 * non trie** jusqu'ici, et `first()` sans `orderBy` laisse le moteur decider.
 *
 * Le defaut s'est manifeste sur le Quality Gate de TASK-1126 :
 * `T3BlogEditorAiAdminTest::test_ai_remaining_blocks_cross_organization`
 * attendait 404 et recevait 200. Avec deux Organizations actives, PostgreSQL
 * liait tantot l'une tantot l'autre ; quand il liait celle de l'autre
 * Organization, le controle d'appartenance comparait cette Organization a
 * elle-meme et laissait passer.
 *
 * Invisible sous SQLite : le parcours suit l'ordre d'insertion, la premiere
 * creee gagne toujours. Le test etait vert 20 fois sur 20 en local.
 */
class TASK1126DefaultOrganizationDeterminismTest extends TestCase
{
    private function resoudreViaMiddleware(): ?Organization
    {
        $methode = new ReflectionMethod(ResolveUrlOrganization::class, 'resolveDefaultOrganization');
        $methode->setAccessible(true);

        return $methode->invoke(app(ResolveUrlOrganization::class));
    }

    public function test_the_middleware_falls_back_to_the_oldest_active_organization(): void
    {
        $ancienne = Organization::factory()->create(['is_active' => true]);
        $recente = Organization::factory()->create(['is_active' => true]);

        $this->assertSame(
            $ancienne->id,
            $this->resoudreViaMiddleware()?->id,
            'le tenant de repli depend du plan de requete',
        );

        // Repete : un tri correct rend la meme reponse a chaque appel, une
        // absence de tri peut se trahir sur un seul essai comme sur dix.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($ancienne->id, $this->resoudreViaMiddleware()?->id);
        }

        $this->assertNotSame($recente->id, $this->resoudreViaMiddleware()?->id);
    }

    public function test_an_explicit_default_still_wins_over_seniority(): void
    {
        Organization::factory()->create(['is_active' => true]);
        $designee = Organization::factory()->create(['is_active' => true, 'is_default' => true]);

        // Le tri ne doit pas avoir renverse la priorite : `is_default` d'abord,
        // l'anciennete seulement pour departager.
        $this->assertSame($designee->id, $this->resoudreViaMiddleware()?->id);
    }

    public function test_both_resolvers_agree(): void
    {
        Organization::factory()->create(['is_active' => true]);
        Organization::factory()->create(['is_active' => true]);

        // Deux chemins de repli qui repondraient differemment donneraient deux
        // tenants selon la porte empruntee.
        $this->assertSame(
            DefaultOrganizationResolver::resolve()?->id,
            $this->resoudreViaMiddleware()?->id,
            'les deux resolveurs de repli divergent',
        );
    }
}
