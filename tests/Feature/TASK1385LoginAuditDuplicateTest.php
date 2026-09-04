<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * TASK-1385 — une connexion, une ligne de journal.
 *
 * ## Le constat, MESURE
 *
 * `App\Listeners\LoginListener` etait abonne DEUX fois : explicitement par
 * `Event::listen(Login::class, LoginListener::class)` dans `AppServiceProvider`,
 * et automatiquement par la decouverte que Laravel 11 applique a `app/Listeners`
 * en lisant le type de l'argument de `handle()`.
 *
 * Consequence en base de developpement : `login_logs` portait **6490 lignes pour
 * 3220 evenements distincts** (`user_id`, `created_at`). Le journal d'audit des
 * connexions etait double depuis toujours.
 *
 * ## Trouve en verifiant mon propre defaut
 *
 * Le meme piege venait de mordre en T1384 : une inscription explicite ajoutee a
 * un ecouteur deja decouvert produisait deux lignes `email_logs` pour un seul
 * echec. En cherchant si d'autres ecouteurs portaient la meme faute, celui-ci
 * est apparu.
 *
 * ## Ce que ces tests mesurent, et ce qu'ils ne mesurent pas
 *
 * Ils mesurent le NOMBRE d'abonnements et le NOMBRE de lignes ecrites. Ils ne
 * touchent pas au comportement d'authentification, ne nettoient aucune ligne
 * historique, et n'exigent aucune migration.
 */
class TASK1385LoginAuditDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $organisation = Organization::factory()->create();
        $this->membre = User::factory()->create(['organization_id' => $organisation->id]);
    }

    /**
     * L'ecouteur n'est abonne QU'UNE fois.
     *
     * C'est la mesure la plus directe du defaut : elle porte sur le registre
     * d'evenements, pas sur ses effets. Un futur `Event::listen()` ajoute par
     * megarde la ferait rougir immediatement, avant meme qu'une ligne soit
     * ecrite.
     */
    public function test_the_login_listener_is_subscribed_exactly_once(): void
    {
        $abonnes = array_filter(
            Event::getListeners(Login::class),
            fn ($ecouteur) => str_contains($this->nommer($ecouteur), 'LoginListener'),
        );

        $this->assertCount(1, $abonnes, 'LoginListener doit etre abonne une seule fois.');
    }

    /**
     * Un evenement de connexion reel produit EXACTEMENT une ligne.
     *
     * L'effet, pas seulement le registre. Les deux comptent : un abonnement
     * unique qui ecrirait deux fois, ou deux abonnements dont l'un serait sans
     * effet, se liraient pareil sur une seule des deux mesures.
     */
    public function test_one_login_writes_exactly_one_row(): void
    {
        Auth::login($this->membre);

        $this->assertSame(1, LoginLog::where('user_id', $this->membre->id)->count());
    }

    /**
     * Deux connexions font deux lignes — le contre-exemple.
     *
     * Sans lui, un ecouteur qui n'ecrirait PLUS RIEN passerait le test
     * precedent... non : il rendrait 0. Mais un ecouteur devenu idempotent par
     * erreur — qui refuserait d'ecrire une seconde ligne pour le meme membre —
     * passerait, lui, et casserait l'audit dans l'autre sens.
     */
    public function test_two_logins_write_two_rows(): void
    {
        Auth::login($this->membre);
        Auth::logout();
        Auth::login($this->membre);

        $this->assertSame(2, LoginLog::where('user_id', $this->membre->id)->count());
    }

    /**
     * Le comportement d'authentification n'est pas touche.
     *
     * La tranche retire un abonnement redondant. Si elle avait effleure
     * l'authentification elle-meme, ce test le dirait.
     */
    public function test_authentication_still_works(): void
    {
        Auth::login($this->membre);

        $this->assertTrue(Auth::check());
        $this->assertSame($this->membre->id, Auth::id());
    }

    /**
     * Rend un ecouteur lisible, quelle que soit sa forme.
     *
     * Le registre melange des closures (l'inscription explicite passe par une
     * fabrique) et des chaines. Les comparer sans les normaliser laisserait
     * passer l'une des deux formes — donc exactement le defaut a mesurer.
     */
    private function nommer(mixed $ecouteur): string
    {
        if (is_string($ecouteur)) {
            return $ecouteur;
        }

        if (is_array($ecouteur)) {
            return is_object($ecouteur[0]) ? $ecouteur[0]::class : (string) $ecouteur[0];
        }

        if ($ecouteur instanceof \Closure) {
            $reflexion = new \ReflectionFunction($ecouteur);
            $variables = $reflexion->getStaticVariables();

            foreach (['listener', 'class'] as $cle) {
                if (isset($variables[$cle]) && is_string($variables[$cle])) {
                    return $variables[$cle];
                }
            }

            return (string) $reflexion->getFileName();
        }

        return is_object($ecouteur) ? $ecouteur::class : '';
    }
}
