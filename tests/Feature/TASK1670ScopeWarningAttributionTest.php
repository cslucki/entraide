<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ai\AiCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1670 — le refus tenant devient ATTRIBUABLE, sans cesser d'etre un refus.
 *
 * Deux promesses, et la premiere commande la seconde :
 *
 * 1. le fail-closed est INCHANGE — sans contexte tenant, aucune donnee ne
 *    sort, et `whereRaw('0 = 1')` reste dans le SQL ;
 * 2. le warning emis nomme desormais le modele, la table, le contexte
 *    d'execution et, en HTTP, la route et l'URI.
 *
 * Et une correction de verite : le message n'accuse plus la « Default
 * Organization », que ce scope ne consulte jamais.
 */
class TASK1670ScopeWarningAttributionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le message canonique, ecrit UNE fois.
     */
    private const MESSAGE = 'BelongsToOrganizationScope: no current Organization context; query denied fail-closed.';

    protected function setUp(): void
    {
        parent::setUp();

        // Une correlation laissee par un test voisin ferait apparaitre
        // `correlation_id` ici et rendrait l'assertion d'absence trompeuse.
        AiCorrelation::forget();
    }

    // -------------------------------------------------------------------------
    // 1. Le fail-closed est INCHANGE
    // -------------------------------------------------------------------------

    public function test_sans_contexte_tenant_le_resultat_reste_vide_et_le_warning_est_emis(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $org->id]);

        Log::spy();

        $this->assertCount(0, Service::all(), 'Le fail-closed doit rendre un ensemble VIDE.');

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message) => $message === self::MESSAGE
        )->atLeast()->once();
    }

    public function test_le_whereraw_zero_egal_un_reste_dans_le_sql(): void
    {
        // La preuve STRUCTURELLE, et non le seul comptage : un ensemble vide
        // peut venir d'une base vide. Ici on lit le SQL reellement produit.
        $sql = Service::query()->toSql();

        $this->assertStringContainsString('0 = 1', $sql);
        $this->assertStringNotContainsString('organization_id', $sql);
    }

    public function test_avec_contexte_tenant_le_sql_filtre_sur_organization_id_et_pas_de_whereraw(): void
    {
        $org = Organization::factory()->create();
        app()->instance('current_organization', $org);

        $sql = Service::query()->toSql();

        $this->assertStringContainsString('organization_id', $sql);
        $this->assertStringNotContainsString('0 = 1', $sql);
    }

    public function test_les_cinq_modeles_scopes_refusent_et_signalent_chacun_leur_table(): void
    {
        $attendus = [
            Service::class => 'services',
            ServiceRequest::class => 'service_requests',
            Transaction::class => 'transactions',
            Referral::class => 'referrals',
            ReferralReward::class => 'referral_rewards',
        ];

        foreach ($attendus as $classe => $table) {
            Log::spy();

            $this->assertCount(0, $classe::all(), $classe.' doit rester fail-closed.');

            Log::shouldHaveReceived('warning')->withArgs(
                fn ($message, $contexte = null) => $message === self::MESSAGE
                    && is_array($contexte)
                    && $contexte['model'] === $classe
                    && $contexte['table'] === $table
            )->atLeast()->once();
        }
    }

    // -------------------------------------------------------------------------
    // 2. Le message ne ment plus
    // -------------------------------------------------------------------------

    public function test_le_message_n_accuse_plus_la_default_organization(): void
    {
        Log::spy();

        Service::all();

        // La formulation exacte du defaut corrige. Ce scope ne consulte QUE
        // `current_organization` : accuser une Organization par defaut envoyait
        // l'investigation sur une fausse piste.
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message) => is_string($message)
                && ! str_contains($message, 'Default Organization')
                && ! str_contains($message, 'no Organization resolved')
                && str_contains($message, 'no current Organization context')
                && str_contains($message, 'fail-closed')
        )->atLeast()->once();
    }

    // -------------------------------------------------------------------------
    // 3. Le contexte CONSOLE
    // -------------------------------------------------------------------------

    public function test_hors_requete_http_le_contexte_est_console(): void
    {
        Log::spy();

        Service::all();

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $contexte = null) => $message === self::MESSAGE
                && is_array($contexte)
                && $contexte['execution_context'] === 'console'
                && array_key_exists('command', $contexte)
        )->atLeast()->once();
    }

    public function test_le_nom_de_commande_artisan_est_reporte(): void
    {
        $origine = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'sitemap:generate', '--force'];

        try {
            Log::spy();

            Service::all();

            Log::shouldHaveReceived('warning')->withArgs(
                fn ($message, $contexte = null) => $message === self::MESSAGE
                    && is_array($contexte)
                    && $contexte['execution_context'] === 'console'
                    && $contexte['command'] === 'sitemap:generate'
            )->atLeast()->once();
        } finally {
            $_SERVER['argv'] = $origine;
        }
    }

    public function test_une_valeur_d_option_n_est_jamais_prise_pour_un_nom_de_commande(): void
    {
        // LE DEFAUT MESURE EN RECETTE LE 08/10/2026. Un premier jet parcourait
        // argv jusqu'au premier jeton non-option et journalisait
        // `"command":"phpunit.pgsql.xml"` — la valeur de l'option `-c`.
        $origine = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['vendor/bin/phpunit', '-c', 'phpunit.pgsql.xml', '--filter', 'Foo'];

        try {
            Log::spy();

            Service::all();

            Log::shouldHaveReceived('warning')->withArgs(
                fn ($message, $contexte = null) => $message === self::MESSAGE
                    && is_array($contexte)
                    && $contexte['command'] === null
            )->atLeast()->once();
        } finally {
            $_SERVER['argv'] = $origine;
        }
    }

    public function test_une_option_a_la_place_de_la_commande_ne_laisse_rien_passer(): void
    {
        $origine = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', '--token=secret-a-ne-pas-journaliser'];

        try {
            Log::spy();

            Service::all();

            Log::shouldHaveReceived('warning')->withArgs(
                function ($message, $contexte = null) {
                    if ($message !== self::MESSAGE || ! is_array($contexte)) {
                        return false;
                    }

                    return $contexte['command'] === null
                        && ! str_contains(json_encode($contexte), 'secret-a-ne-pas-journaliser');
                }
            )->atLeast()->once();
        } finally {
            $_SERVER['argv'] = $origine;
        }
    }

    // -------------------------------------------------------------------------
    // 4. Le contexte HTTP — route et URI identifiables
    // -------------------------------------------------------------------------

    public function test_sur_une_requete_http_la_route_et_l_uri_sont_identifiables(): void
    {
        // Une route de TEST, hors groupe `web` : aucun middleware tenant ne
        // tourne, donc `current_organization` n'est pas liee — c'est exactement
        // la situation a attribuer.
        Route::get('/task1670/sonde', fn () => response()->json(['n' => Service::all()->count()]))
            ->name('task1670.sonde');

        Log::spy();

        $reponse = $this->getJson('/task1670/sonde');

        $reponse->assertOk()->assertJson(['n' => 0]);

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $contexte = null) => $message === self::MESSAGE
                && is_array($contexte)
                && $contexte['execution_context'] === 'http'
                && $contexte['method'] === 'GET'
                && $contexte['uri'] === '/task1670/sonde'
                && $contexte['route'] === 'task1670.sonde'
                && $contexte['model'] === Service::class
        )->atLeast()->once();
    }

    public function test_la_chaine_de_requete_n_est_jamais_journalisee(): void
    {
        Route::get('/task1670/sonde', fn () => response()->json(['n' => Service::all()->count()]))
            ->name('task1670.sonde');

        Log::spy();

        // Un filtre d'ecran peut porter une adresse e-mail ou un nom. L'URI
        // journalisee doit etre le CHEMIN seul.
        $this->getJson('/task1670/sonde?email=victime@example.test&q=nom-prive')->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(
            function ($message, $contexte = null) {
                if ($message !== self::MESSAGE || ! is_array($contexte)) {
                    return false;
                }

                $aplat = json_encode($contexte);

                return $contexte['uri'] === '/task1670/sonde'
                    && ! str_contains($aplat, 'victime@example.test')
                    && ! str_contains($aplat, 'nom-prive')
                    && ! str_contains($aplat, 'email=');
            }
        )->atLeast()->once();
    }

    public function test_un_utilisateur_authentifie_est_identifie_par_son_id(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Route::middleware('auth')
            ->get('/task1670/sonde-auth', fn () => response()->json(['n' => Service::all()->count()]))
            ->name('task1670.sonde-auth');

        Log::spy();

        $this->actingAs($user)->getJson('/task1670/sonde-auth')->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(
            function ($message, $contexte = null) use ($user) {
                if ($message !== self::MESSAGE || ! is_array($contexte)) {
                    return false;
                }

                // L'identifiant OPAQUE, et rien de l'identite : ni e-mail, ni nom.
                $aplat = json_encode($contexte);

                return ($contexte['user_id'] ?? null) === $user->id
                    && ! str_contains($aplat, (string) $user->email);
            }
        )->atLeast()->once();
    }

    // -------------------------------------------------------------------------
    // 5. Avec contexte tenant : filtrage normal, AUCUN warning
    // -------------------------------------------------------------------------

    public function test_avec_current_organization_le_filtrage_est_normal_et_aucun_warning_n_est_emis(): void
    {
        $org = Organization::factory()->create();
        $autre = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $autre->id]);

        app()->instance('current_organization', $org);

        Log::spy();

        $this->assertCount(1, Service::all());

        Log::shouldNotHaveReceived('warning', [self::MESSAGE]);
        Log::shouldNotHaveReceived('warning', [self::MESSAGE, \Mockery::any()]);
    }

    // -------------------------------------------------------------------------
    // 6. La correlation EXISTANTE est reutilisee, jamais fabriquee
    // -------------------------------------------------------------------------

    public function test_la_correlation_est_absente_du_contexte_quand_aucune_operation_n_est_en_cours(): void
    {
        Log::spy();

        Service::all();

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $contexte = null) => $message === self::MESSAGE
                && is_array($contexte)
                && ! array_key_exists('correlation_id', $contexte)
        )->atLeast()->once();

        // La preuve d'innocuite : journaliser n'a DEMARRE aucune correlation.
        $this->assertNull(AiCorrelation::peek());
    }

    public function test_la_correlation_en_cours_est_reportee_dans_le_contexte(): void
    {
        $correlation = AiCorrelation::start();

        Log::spy();

        Service::all();

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $contexte = null) => $message === self::MESSAGE
                && is_array($contexte)
                && ($contexte['correlation_id'] ?? null) === $correlation
        )->atLeast()->once();
    }

    // -------------------------------------------------------------------------
    // 7. Preuve negative cross-tenant — l'observabilite n'a rien ouvert
    // -------------------------------------------------------------------------

    public function test_absence_de_contexte_tenant_ne_laisse_voir_aucune_donnee_d_aucun_tenant(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        Service::factory()->forUser($userA)->create(['organization_id' => $orgA->id]);
        Service::factory()->forUser($userB)->create(['organization_id' => $orgB->id]);
        ServiceRequest::factory()->forUser($userA)->create(['organization_id' => $orgA->id]);
        Transaction::factory()->forBuyer($userA)->forSeller($userB)->create(['organization_id' => $orgB->id]);

        // Aucune Organization liee : pas « celle d'a cote », RIEN.
        $this->assertCount(0, Service::all());
        $this->assertCount(0, ServiceRequest::all());
        $this->assertCount(0, Transaction::all());

        // Et les lignes existent bel et bien : le vide vient de la garde, pas
        // d'une base vide.
        $this->assertCount(2, Service::withoutGlobalScope(BelongsToOrganizationScope::class)->get());
    }
}
