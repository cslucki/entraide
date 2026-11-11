<?php

namespace Tests\Unit\Support\Pwa;

use App\Support\Pwa\PwaAppName;
use Tests\TestCase;

/**
 * TASK-1805 — `resolve()` est une fonction PURE (environnement en parametre,
 * jamais `app()->environment()` a l'interieur) : les trois cas se testent
 * donc sans aucun jonglage d'environnement reel du processus de test.
 */
class PwaAppNameTest extends TestCase
{
    public function test_local_environment_resolves_to_boucledev(): void
    {
        $this->assertSame('BoucleDev', PwaAppName::resolve(null, 'local'));
    }

    public function test_production_environment_resolves_to_bouclepro(): void
    {
        $this->assertSame('BouclePro', PwaAppName::resolve(null, 'production'));
    }

    /** Un environnement INCONNU retombe sur le nom de PRODUCTION, jamais sur dev. */
    public function test_any_other_environment_resolves_to_bouclepro(): void
    {
        $this->assertSame('BouclePro', PwaAppName::resolve(null, 'testing'));
        $this->assertSame('BouclePro', PwaAppName::resolve(null, 'staging'));
        $this->assertSame('BouclePro', PwaAppName::resolve(null, ''));
    }

    /**
     * L'override gagne dans N'IMPORTE QUEL environnement, y compris `local` —
     * c'est la porte de sortie pour une preprod qui voudrait un troisieme nom
     * (`PWA_APP_NAME`) sans toucher au code.
     */
    public function test_a_non_empty_override_always_wins(): void
    {
        $this->assertSame('BouclePro STAGING', PwaAppName::resolve('BouclePro STAGING', 'production'));
        $this->assertSame('BouclePro STAGING', PwaAppName::resolve('BouclePro STAGING', 'local'));
    }

    /** Une override vide (chaine vide) n'est pas prise pour une valeur : repli normal. */
    public function test_an_empty_override_is_ignored(): void
    {
        $this->assertSame('BoucleDev', PwaAppName::resolve('', 'local'));
        $this->assertSame('BouclePro', PwaAppName::resolve(null, 'production'));
    }

    public function test_current_reads_real_config_and_environment(): void
    {
        config(['pwa.app_name_override' => null]);

        // L'environnement reel du processus de test est `testing`, jamais `local`.
        $this->assertSame('BouclePro', PwaAppName::current());

        config(['pwa.app_name_override' => 'BouclePro STAGING']);
        $this->assertSame('BouclePro STAGING', PwaAppName::current());
    }

    /**
     * `isLocal()` gouverne l'icone MARRON (correctif demande par Cyril) : elle
     * suit l'environnement REEL, jamais un override de nom — une preprod
     * nommee differemment n'a pas demande une icone differente.
     */
    public function test_is_local_reflects_the_real_environment_not_the_name_override(): void
    {
        $this->assertFalse(PwaAppName::isLocal(), 'l\'environnement reel des tests est `testing`, jamais `local`.');

        app()->detectEnvironment(fn () => 'local');
        $this->assertTrue(PwaAppName::isLocal());

        config(['pwa.app_name_override' => 'BouclePro STAGING']);
        $this->assertTrue(
            PwaAppName::isLocal(),
            'un override de NOM ne doit jamais faire croire que l\'environnement a change.'
        );
    }
}
