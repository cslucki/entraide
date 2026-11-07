<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * TASK-1774 — LA CONNEXION PERSISTANTE, ENFIN ARMEE.
 *
 * Mesure du 06/11, en PROD comme en local : la session ordinaire dure 120
 * minutes glissantes, et le mecanisme de reconnexion de Laravel — qui dure
 * 400 jours et existe depuis toujours dans ce depot — n'etait JAMAIS arme,
 * parce que la case du formulaire etait decochee par defaut.
 *
 * Ces gardes defendent trois promesses, et la deuxieme est celle qui compte :
 *   1. la case est cochee par defaut, donc la persistance s'arme toute seule ;
 *   2. **un refus explicite est RESPECTE** — sur un poste partage, decocher
 *      doit vouloir dire quelque chose, sans quoi la case est un decor ;
 *   3. basculer vers son compte frere ne RETROGRADE pas la persistance.
 */
class TASK1774ConnexionPersistanteTest extends TestCase
{
    private Organization $organization;

    private function membre(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'approved_at' => now(),
            'email_verified_at' => now(),
            'password' => bcrypt('mot-de-passe-de-test'),
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'is_default' => true,
            'is_public' => true,
        ]);

        app()->instance('current_organization', $this->organization);
    }

    /** Le nom du cookie de reconnexion, tel que le guard le calcule. */
    private function nomDuCookie(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    /** La case arrive cochee : c'est tout l'objet de cette TASK. */
    public function test_la_case_rester_connecte_arrive_cochee(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="remember"[^>]*checked/',
            $html,
            'decochee, la case laissait le mecanisme de 400 jours inutilise',
        );
        $this->assertStringContainsString(__('auth.remember_me_shared_device'), $html,
            'une case cochee par defaut doit dire ou ne PAS la laisser cochee');
    }

    /** Se connecter avec la case cochee pose le cookie de 400 jours. */
    public function test_une_connexion_avec_la_case_pose_le_cookie_de_reconnexion(): void
    {
        $membre = $this->membre();

        // `organization` est OBLIGATOIRE sur `/login` depuis T1749 : une
        // adresse ne designe plus une personne (unicite par Organization).
        $this->post(route('login'), [
            'email' => $membre->email,
            'password' => 'mot-de-passe-de-test',
            'organization' => $this->organization->slug,
            'remember' => '1',
            '_remember_pose' => '1',
        ])->assertCookie($this->nomDuCookie());

        $this->assertAuthenticatedAs($membre);
        $this->assertNotNull($membre->fresh()->getRememberToken(),
            'le jeton doit etre pose en base, sinon le cookie ne vaut rien');
    }

    /**
     * LA GARDE QUI COMPTE : un refus explicite est respecte.
     *
     * La case est cochee par defaut — donc la decocher est un geste
     * DELIBERE, typiquement sur un poste partage. Si le serveur posait quand
     * meme le cookie, la case serait un decor, et la promesse faite a l'ecran
     * serait fausse.
     */
    public function test_une_case_decochee_ne_pose_aucun_cookie_de_reconnexion(): void
    {
        $membre = $this->membre();

        $reponse = $this->post(route('login'), [
            'email' => $membre->email,
            'password' => 'mot-de-passe-de-test',
            'organization' => $this->organization->slug,
            // `remember` ABSENT : c'est ce que poste un navigateur quand la
            // case est decochee.
            '_remember_pose' => '1',
        ]);

        $this->assertAuthenticatedAs($membre);
        $this->assertNull($reponse->getCookie($this->nomDuCookie(), false),
            'decocher doit vouloir dire quelque chose');
    }

    /** Se deconnaitre volontairement revoque le cookie. */
    public function test_la_deconnexion_volontaire_revoque_la_reconnexion(): void
    {
        $membre = $this->membre();

        $this->post(route('login'), [
            'email' => $membre->email,
            'password' => 'mot-de-passe-de-test',
            'organization' => $this->organization->slug,
            'remember' => '1',
            '_remember_pose' => '1',
        ]);

        $jetonAvant = $membre->fresh()->getRememberToken();
        $this->assertNotNull($jetonAvant);

        $this->actingAs($membre->fresh())->post(route('logout'));

        $this->assertGuest();
        $this->assertNotSame($jetonAvant, $membre->fresh()->getRememberToken(),
            'le jeton doit tourner, sinon un cookie deja pose rouvrirait la porte');
    }

    /**
     * Basculer vers son compte frere ne RETROGRADE pas la persistance.
     *
     * `Auth::logout()` detruit le cookie, `Auth::login()` sans second argument
     * n'en repose aucun : sans le report explicite, une bascule ramenait
     * silencieusement a la session de deux heures.
     */
    public function test_la_bascule_de_compte_conserve_la_persistance(): void
    {
        $autreOrganization = Organization::factory()->create(['is_active' => true]);

        $depart = $this->membre(['email' => 'homonyme@bouclepro.test']);
        $arrivee = User::factory()->create([
            'organization_id' => $autreOrganization->id,
            'email' => 'homonyme@bouclepro.test',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($depart);

        // Le cookie de reconnexion est POSE sur la requete courante : c'est lui
        // que le service lit AVANT de deconnecter. On le fabrique a la main,
        // son contenu n'a aucune importance — seule sa PRESENCE est la
        // question posee.
        request()->cookies->set($this->nomDuCookie(), 'contenu-sans-importance');

        app(\App\Services\Users\AccountSwitch::class)->versLeCompte($arrivee);

        $this->assertAuthenticatedAs($arrivee);
        // On mesure le COOKIE mis en file, pas le jeton en base : un jeton
        // peut exister sans qu'aucun cookie ne parte, et c'est le cookie qui
        // fait la persistance.
        $this->assertNotNull(app('cookie')->queued($this->nomDuCookie()),
            'sans report de la persistance, la bascule ramene a une session de deux heures');
    }

    /** Et sans cookie au depart, la bascule n'en invente pas un. */
    public function test_une_bascule_sans_persistance_n_en_cree_pas(): void
    {
        $autreOrganization = Organization::factory()->create(['is_active' => true]);

        $depart = $this->membre(['email' => 'autre@bouclepro.test']);
        $arrivee = User::factory()->create([
            'organization_id' => $autreOrganization->id,
            'email' => 'autre@bouclepro.test',
            'approved_at' => now(),
            'email_verified_at' => now(),
            'remember_token' => null,
        ]);

        $this->actingAs($depart);
        request()->cookies->remove($this->nomDuCookie());

        app(\App\Services\Users\AccountSwitch::class)->versLeCompte($arrivee);

        $this->assertAuthenticatedAs($arrivee);
        $this->assertNull(app('cookie')->queued($this->nomDuCookie()),
            'la bascule ne doit pas armer une persistance que personne n\'a demandee');
    }
}
