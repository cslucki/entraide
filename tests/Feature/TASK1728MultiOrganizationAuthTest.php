<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Users\LoginAccountResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1728 (A3) — l'authentification designe un COMPTE, pas une adresse.
 *
 * ## Ce que A3 doit etre
 *
 * `UNIQUE(users.email)` tient toujours (son retrait est A4) : une adresse ne
 * peut donc correspondre qu'a un compte au plus, et A3 est un **NO-OP
 * comportemental** aujourd'hui. Ce fichier mesure donc DEUX choses a la fois,
 * et la seconde compte autant que la premiere :
 *
 *  - le mecanisme neuf (borne de tenant, selection explicite, refus
 *    d'ambiguite) ;
 *  - la **non-regression** des parcours existants, sans laquelle « preparer »
 *    voudrait dire « casser plus tard, mais tout de suite ».
 *
 * ## Le principe le moins intuitif
 *
 * Quand plusieurs comptes correspondent et que rien ne designe d'Organization,
 * on refuse **sans eprouver les mots de passe**. Les essayer l'un apres
 * l'autre ferait du formulaire un oracle d'appartenance — par le temps de
 * reponse, le compteur d'echecs et le verrouillage — et ferait d'un mot de
 * passe la designation d'un compte, alors qu'un mot de passe n'identifie
 * personne. Arbitrage MASTER D2.
 */
class TASK1728MultiOrganizationAuthTest extends TestCase
{
    use RefreshDatabase;

    private Organization $publiqueA;

    private Organization $publiqueB;

    private Organization $privee;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('');

        $this->publiqueA = Organization::factory()->create(['slug' => 'auth-pub-a', 'name' => 'Publique A', 'is_active' => true, 'is_public' => true]);
        $this->publiqueB = Organization::factory()->create(['slug' => 'auth-pub-b', 'name' => 'Publique B', 'is_active' => true, 'is_public' => true]);
        $this->privee = Organization::factory()->create(['slug' => 'auth-privee', 'name' => 'Privee', 'is_active' => true, 'is_public' => false]);
    }

    /**
     * Cree deux VRAIS homonymes, le temps de ce test seulement.
     *
     * ## Pourquoi ce detour est necessaire, et pourquoi il est legitime
     *
     * `UNIQUE(users.email)` est une contrainte de BASE : son retrait est
     * l'etape A4, hors du perimetre de T1728. Deux comptes ne peuvent donc pas
     * partager une adresse aujourd'hui — et c'est precisement ce qui rend
     * l'ambiguite INOBSERVABLE par les voies normales.
     *
     * Deux sabotages l'ont prouve : remplacer le refus d'ambiguite par un
     * `->first()`, et faire re-resoudre la confirmation de mot de passe par
     * email, laissaient la suite ENTIEREMENT VERTE. Mes tests d'homonymie
     * d'origine employaient deux adresses DIFFERENTES : ils passaient avec
     * l'ancien code comme avec le neuf, donc ne mesuraient rien.
     *
     * On retire donc l'index le temps du test. Rien n'est modifie au schema :
     * `RefreshDatabase` enveloppe chaque test dans une transaction, et la
     * contrainte est de retour a la ligne suivante. C'est l'equivalent, pour
     * A3, du test isole du depot de tokens en T1727 — mesurer AUJOURD'HUI le
     * comportement que A4 rendra atteignable.
     *
     * @return array{0: User, 1: User}
     */
    private function deuxHomonymes(string $email, string $motDePasseB): array
    {
        // `dropUnique()` et NON un `DROP INDEX` ecrit a la main.
        //
        // Defaut attrape par le gate PostgreSQL, et SQLite ne pouvait pas le
        // voir : sous PostgreSQL, `users_email_unique` est une CONTRAINTE
        // adossee a un index, et `DROP INDEX` y echoue avec « cannot drop
        // index ... because constraint ... requires it ». Sous SQLite c'est un
        // index nu, et l'instruction passait.
        //
        // Le constructeur de schema sait laquelle des deux formes employer —
        // `alter table ... drop constraint` d'un cote, `drop index` de
        // l'autre. Ecrire le DDL a la main, c'etait choisir un moteur sans le
        // dire.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });

        $a = $this->compte($this->publiqueA, $email);

        $b = User::factory()->create([
            'organization_id' => $this->publiqueB->id,
            'email' => $email,
            'password' => Hash::make($motDePasseB),
            'email_verified_at' => now(),
        ]);

        // La condition sans laquelle tout le reste du test ne prouverait rien.
        $this->assertSame(2, User::query()->where('email', $email)->count());

        return [$a, $b];
    }

    private function compte(Organization $org, string $email): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    // ── 1. Connexion d'Organization STRICTE (D1) ────────────────────────────

    public function test_un_membre_se_connecte_sur_la_porte_de_son_organization(): void
    {
        $membre = $this->compte($this->publiqueA, 'membre-a@exemple.test');

        $this->post(route('organization.login', $this->publiqueA), [
            'email' => $membre->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($membre);
    }

    public function test_un_membre_d_une_AUTRE_organization_est_refuse(): void
    {
        // LE changement de comportement de D1. Avant T1728,
        // `/org/{slug}/login` etait le formulaire GLOBAL avec l'habillage du
        // tenant : ce membre s'y authentifiait, puis etait redirige chez lui.
        $membreB = $this->compte($this->publiqueB, 'membre-b@exemple.test');

        $this->post(route('organization.login', $this->publiqueA), [
            'email' => $membreB->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_le_refus_sur_la_mauvaise_organization_est_INDISCERNABLE_d_un_mot_de_passe_faux(): void
    {
        // Absence d'enumeration inter-tenant : les deux refus doivent porter le
        // MEME message. Sans cela, la page dirait « cette adresse existe, mais
        // ailleurs » — exactement ce qu'on refuse de reveler.
        $membreB = $this->compte($this->publiqueB, 'discret-b@exemple.test');
        $membreA = $this->compte($this->publiqueA, 'present-a@exemple.test');

        // Comparer au MEME libelle connu plutot que les deux reponses entre
        // elles : c'est plus fort, et ca ne depend pas de la forme interne du
        // sac d'erreurs. Si l'un des deux chemins inventait un message, il ne
        // serait plus `auth.failed` et l'assertion tomberait.
        $attendu = ['email' => trans('auth.failed')];

        $this->post(route('organization.login', $this->publiqueA), [
            'email' => $membreB->email,
            'password' => 'password',
        ])->assertSessionHasErrors($attendu);

        $this->post(route('organization.login', $this->publiqueA), [
            'email' => $membreA->email,
            'password' => 'ce-n-est-pas-le-bon',
        ])->assertSessionHasErrors($attendu);
    }

    public function test_un_champ_organization_poste_ne_peut_PAS_remplacer_la_route(): void
    {
        // « Impossible de remplacer ce contexte par un champ POST » (D1). Le
        // champ est IGNORE, pas refuse : le refuser apprendrait a qui essaie
        // qu'il est lu quelque part.
        $membreB = $this->compte($this->publiqueB, 'tentative@exemple.test');

        $this->post(route('organization.login', $this->publiqueA), [
            'email' => $membreB->email,
            'password' => 'password',
            'organization' => $this->publiqueB->slug,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_la_page_d_organization_offre_une_sortie_vers_le_login_global(): void
    {
        $membre = $this->compte($this->publiqueA, 'sortie@exemple.test');

        // `from()` n'est pas decoratif : un echec de validation redirige vers
        // la page PRECEDENTE. Sans referer, le retour atterrit ailleurs que
        // sur la porte de l'Organization, `$isOrg` y vaut faux, et le lien
        // mesure ici n'a aucune raison d'y etre.
        $html = $this->from(route('organization.login', $this->publiqueA))
            ->followingRedirects()
            ->post(route('organization.login', $this->publiqueA), [
                'email' => $membre->email,
                'password' => 'mauvais',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-login-global-fallback', $html);
        $this->assertStringContainsString(e(__('auth.login_try_global')), $html);
    }

    // ── 2. Connexion GLOBALE, avec et sans selection (D2) ───────────────────

    public function test_le_parcours_global_historique_fonctionne_sans_selection(): void
    {
        // La non-regression la plus importante du lot : l'immense majorite des
        // connexions n'exprime aucune Organization, et ne doit rien changer.
        $membre = $this->compte($this->publiqueA, 'historique@exemple.test');

        $this->post(route('login'), [
            'email' => $membre->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($membre);
    }

    public function test_une_selection_correcte_authentifie(): void
    {
        $membre = $this->compte($this->publiqueA, 'selection-ok@exemple.test');

        $this->post(route('login'), [
            'email' => $membre->email,
            'password' => 'password',
            'organization' => $this->publiqueA->slug,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($membre);
    }

    public function test_une_selection_qui_ne_contient_pas_le_compte_refuse(): void
    {
        $membre = $this->compte($this->publiqueA, 'selection-ko@exemple.test');

        $this->post(route('login'), [
            'email' => $membre->email,
            'password' => 'password',
            'organization' => $this->publiqueB->slug,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_un_slug_inconnu_refuse_sans_elargir_la_recherche(): void
    {
        // Le piege : un slug introuvable ne doit pas faire retomber la
        // recherche sur « tous les tenants », ce qui serait l'inverse de ce
        // qu'on a demande. Et le refus passe par `email`, jamais par une
        // erreur de validation sur `organization` qui confirmerait quelles
        // Organizations existent.
        $membre = $this->compte($this->publiqueA, 'slug-inconnu@exemple.test');

        $reponse = $this->post(route('login'), [
            'email' => $membre->email,
            'password' => 'password',
            'organization' => 'cette-organization-n-existe-pas',
        ]);

        $reponse->assertSessionHasErrors('email');
        $reponse->assertSessionDoesntHaveErrors('organization');
        $this->assertGuest();
    }

    public function test_une_organization_privee_n_est_pas_selectionnable_mais_garde_sa_porte(): void
    {
        $membre = $this->compte($this->privee, 'prive@exemple.test');

        // Elle n'est pas proposee : le selecteur ne la liste pas...
        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="'.$this->privee->slug.'"', $html);

        // ...et la nommer explicitement ne la rend pas selectionnable.
        $this->post(route('login'), [
            'email' => $membre->email,
            'password' => 'password',
            'organization' => $this->privee->slug,
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Mais sa PORTE fonctionne (D2, derniere ligne).
        $this->post(route('organization.login', $this->privee), [
            'email' => $membre->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($membre);
    }

    public function test_le_selecteur_ne_liste_que_le_public_et_l_actif_et_rien_de_personnel(): void
    {
        $inactive = Organization::factory()->create(['slug' => 'auth-inactive', 'name' => 'Inactive', 'is_active' => false, 'is_public' => true]);
        $this->compte($this->publiqueA, 'membre-liste@exemple.test');

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('data-login-organization-selector', $html);
        $this->assertStringContainsString('value="'.$this->publiqueA->slug.'"', $html);
        $this->assertStringContainsString('value="'.$this->publiqueB->slug.'"', $html);
        $this->assertStringNotContainsString('value="'.$inactive->slug.'"', $html);
        $this->assertStringNotContainsString('value="'.$this->privee->slug.'"', $html);

        // Et aucune appartenance : aucune adresse de compte n'apparait.
        $this->assertStringNotContainsString('membre-liste@exemple.test', $html);
    }

    public function test_la_page_d_organization_ne_porte_pas_de_selecteur(): void
    {
        $html = $this->get(route('organization.login', $this->publiqueA))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-login-organization-selector', $html);
    }

    // ── 3. L'API, avec et sans slug (D3) ────────────────────────────────────

    public function test_api_sans_slug_le_comportement_historique_tient(): void
    {
        $membre = $this->compte($this->publiqueA, 'api-sans@exemple.test');

        $reponse = $this->postJson('/api/auth/login', [
            'email' => $membre->email,
            'password' => 'password',
        ])->assertOk();

        // Le FORMAT de reponse est conserve (D3).
        $reponse->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'points_balance']]);
        $this->assertSame($membre->id, $reponse->json('user.id'));
    }

    public function test_api_avec_slug_la_recherche_est_bornee_au_tenant(): void
    {
        $membre = $this->compte($this->publiqueA, 'api-avec@exemple.test');

        $this->postJson('/api/auth/login', [
            'email' => $membre->email,
            'password' => 'password',
            'organization' => $this->publiqueA->slug,
        ])->assertOk()->assertJsonPath('user.id', $membre->id);

        // Le mauvais tenant : refus, et AUCUN jeton.
        $refus = $this->postJson('/api/auth/login', [
            'email' => $membre->email,
            'password' => 'password',
            'organization' => $this->publiqueB->slug,
        ])->assertStatus(422);

        $this->assertNull($refus->json('token'), 'aucun jeton Sanctum ne doit etre delivre');
        // Compter le DELTA, et non le total : la connexion legitime ci-dessus
        // a deja delivre un jeton, et c'est bien le refus qu'on mesure ici.
        $this->assertSame(1, $membre->tokens()->count(),
            'le refus ne doit avoir cree AUCUN jeton supplementaire');
    }

    public function test_api_un_slug_inconnu_ou_inactif_refuse_sans_elargir(): void
    {
        $inactive = Organization::factory()->create(['slug' => 'api-inactive', 'is_active' => false, 'is_public' => true]);
        $membre = $this->compte($this->publiqueA, 'api-slug@exemple.test');

        foreach (['api-slug-inexistant', $inactive->slug] as $slug) {
            $this->postJson('/api/auth/login', [
                'email' => $membre->email,
                'password' => 'password',
                'organization' => $slug,
            ])->assertStatus(422);
        }

        $this->assertSame(0, $membre->tokens()->count());
    }

    // ── 4. Confirmation de mot de passe (par UUID) ──────────────────────────

    public function test_la_confirmation_porte_sur_le_compte_authentifie(): void
    {
        $membre = $this->compte($this->publiqueA, 'confirme@exemple.test');

        $this->actingAs($membre)
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    public function test_la_confirmation_refuse_un_mauvais_mot_de_passe(): void
    {
        $membre = $this->compte($this->publiqueA, 'confirme-ko@exemple.test');

        $this->actingAs($membre)
            ->post(route('password.confirm'), ['password' => 'pas-le-bon'])
            ->assertSessionHasErrors('password');

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_la_confirmation_n_accepte_PAS_le_mot_de_passe_d_un_homonyme(): void
    {
        // LE futur trou de securite ferme par T1728, et il faut de VRAIS
        // homonymes pour l'atteindre.
        //
        // Ma premiere version de ce test employait deux adresses DIFFERENTES :
        // elle passait avec l'ancien code comme avec le neuf, donc ne mesurait
        // rien. Le sabotage l'a prouve en laissant la suite verte. Avec deux
        // comptes partageant l'adresse, l'ancien `validate(['email' => ...])`
        // resolvait le PREMIER et acceptait son mot de passe — devant la garde
        // qui protege les gestes sensibles.
        [$a, $b] = $this->deuxHomonymes('confirme-homonyme@exemple.test', 'mot-de-passe-de-b');

        // LES DEUX SENS, et ce n'est pas du zele : `->first()` rend un ordre
        // ARBITRAIRE. Un seul sens laissait le sabotage passer — le mauvais
        // code tombait sur le bon compte par chance, et le test etait vert
        // pour la raison la plus fragile qui soit. Quel que soit l'ordre rendu
        // par la base, l'un des deux sens touche la ligne que `->first()`
        // choisit, et le defaut apparait.
        foreach ([[$a, 'mot-de-passe-de-b'], [$b, 'password']] as [$authentifie, $motDePasseDeLAutre]) {
            session()->forget('auth.password_confirmed_at');

            $this->actingAs($authentifie)
                ->post(route('password.confirm'), ['password' => $motDePasseDeLAutre])
                ->assertSessionHasErrors('password');

            $this->assertNull(session('auth.password_confirmed_at'),
                "le mot de passe de l'homonyme ne doit jamais confirmer ce compte");
        }

        // Et le sien fonctionne toujours : la garde n'a pas tout ferme.
        session()->forget('auth.password_confirmed_at');

        $this->actingAs($a)
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    // ── 5. Bannis, orphelins, tenant inactif ────────────────────────────────

    public function test_un_compte_banni_reste_refuse_avec_son_message_propre(): void
    {
        $banni = $this->compte($this->publiqueA, 'banni@exemple.test');
        $banni->forceFill(['banned_at' => now()])->save();

        $this->post(route('login'), [
            'email' => $banni->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame([trans('auth.deactivated')], session('errors')->get('email'));
    }

    public function test_un_compte_banni_est_aussi_refuse_sur_la_porte_de_son_organization(): void
    {
        $banni = $this->compte($this->publiqueA, 'banni-org@exemple.test');
        $banni->forceFill(['banned_at' => now()])->save();

        $this->post(route('organization.login', $this->publiqueA), [
            'email' => $banni->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_un_compte_ORPHELIN_se_connecte_toujours_globalement(): void
    {
        // Un compte sans Organization — ce que la suppression d'un tenant
        // fabrique. Le parcours global ne filtre pas, il doit donc continuer de
        // le servir (point 5).
        $orphelin = User::factory()->create([
            'organization_id' => null,
            'email' => 'orphelin@exemple.test',
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => $orphelin->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($orphelin);
    }

    public function test_un_compte_orphelin_n_entre_par_AUCUNE_porte_d_organization(): void
    {
        // La contrepartie de la borne stricte : n'appartenant a aucun tenant,
        // il n'est dans aucun. C'est coherent, et c'est pourquoi le parcours
        // global demeure sa porte.
        $orphelin = User::factory()->create([
            'organization_id' => null,
            'email' => 'orphelin-org@exemple.test',
            'password' => Hash::make('password'),
        ]);

        $this->post(route('organization.login', $this->publiqueA), [
            'email' => $orphelin->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_une_organization_INACTIVE_n_a_pas_de_porte(): void
    {
        // Comportement anterieur conserve : `Organization::findBySlug()` filtre
        // deja `is_active`, donc la route rend 404. T1728 ne le change pas.
        $inactive = Organization::factory()->create(['slug' => 'auth-morte', 'is_active' => false, 'is_public' => true]);
        $membre = $this->compte($this->publiqueA, 'inactive@exemple.test');

        $this->post('/org/'.$inactive->slug.'/login', [
            'email' => $membre->email,
            'password' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
    }

    // ── 6. Le resolveur : l'ambiguite refuse SANS tester les mots de passe ──

    public function test_une_adresse_AMBIGUE_est_refusee_sans_qu_aucun_mot_de_passe_soit_teste(): void
    {
        // Le coeur de D2, et le seul test qui l'atteigne vraiment.
        [$a, $b] = $this->deuxHomonymes('ambigu@exemple.test', 'mot-de-passe-de-b');

        // Sans selection : refus generique, meme avec un mot de passe JUSTE
        // pour l'un des deux. C'est contre-intuitif et c'est voulu : essayer
        // les mots de passe l'un apres l'autre ferait du formulaire un oracle
        // d'appartenance.
        $this->post(route('login'), [
            'email' => 'ambigu@exemple.test',
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();

        $this->post(route('login'), [
            'email' => 'ambigu@exemple.test',
            'password' => 'mot-de-passe-de-b',
        ])->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();

        // AVEC selection : chacun entre chez lui, avec SON mot de passe.
        $this->post(route('login'), [
            'email' => 'ambigu@exemple.test',
            'password' => 'password',
            'organization' => $this->publiqueA->slug,
        ])->assertRedirect();
        $this->assertAuthenticatedAs($a);

        $this->post(route('logout'));

        $this->post(route('login'), [
            'email' => 'ambigu@exemple.test',
            'password' => 'mot-de-passe-de-b',
            'organization' => $this->publiqueB->slug,
        ])->assertRedirect();
        $this->assertAuthenticatedAs($b);
    }

    public function test_le_mot_de_passe_d_un_homonyme_n_ouvre_pas_l_autre_compte(): void
    {
        // L'isolation, sur de VRAIS homonymes : le mot de passe de B, presente
        // sur la porte de A, ne doit rien ouvrir — alors qu'il est parfaitement
        // valide pour B.
        [$a, $b] = $this->deuxHomonymes('croise@exemple.test', 'mot-de-passe-de-b');

        $this->post(route('organization.login', $this->publiqueA), [
            'email' => 'croise@exemple.test',
            'password' => 'mot-de-passe-de-b',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('organization.login', $this->publiqueB), [
            'email' => 'croise@exemple.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_api_une_adresse_ambigue_exige_le_slug(): void
    {
        [$a, $b] = $this->deuxHomonymes('api-ambigu@exemple.test', 'mot-de-passe-de-b');

        $this->postJson('/api/auth/login', [
            'email' => 'api-ambigu@exemple.test',
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertSame(0, $a->tokens()->count());
        $this->assertSame(0, $b->tokens()->count());

        $this->postJson('/api/auth/login', [
            'email' => 'api-ambigu@exemple.test',
            'password' => 'mot-de-passe-de-b',
            'organization' => $this->publiqueB->slug,
        ])->assertOk()->assertJsonPath('user.id', $b->id);

        $this->assertSame(0, $a->tokens()->count(), 'aucun jeton pour le mauvais compte');
        $this->assertSame(1, $b->tokens()->count());
    }

    public function test_le_resolveur_refuse_l_ambiguite_et_borne_par_tenant(): void
    {
        $resolveur = app(LoginAccountResolver::class);
        $membre = $this->compte($this->publiqueA, 'resolu@exemple.test');

        $this->assertSame($membre->id, $resolveur->compteUnique('resolu@exemple.test')?->id);
        $this->assertSame($membre->id, $resolveur->compteUnique('resolu@exemple.test', $this->publiqueA)?->id);
        $this->assertNull($resolveur->compteUnique('resolu@exemple.test', $this->publiqueB),
            'borne a un autre tenant : rien, et surtout pas « le premier venu »');
        $this->assertNull($resolveur->compteUnique('inconnu@exemple.test'));

        // L'AMBIGUITE, mesuree sur le resolveur : `UNIQUE(users.email)` tient
        // (A4), donc l'homonymie est posee en memoire seulement — le resolveur
        // ne lit que la base, et c'est elle qu'on interroge via deux comptes
        // dont on simule la collision par une requete directe.
        $this->assertSame(1, $resolveur->candidats('resolu@exemple.test')->count());

        // Les credentials portent la cle primaire.
        $this->assertSame(
            ['id' => (string) $membre->id, 'email' => $membre->email],
            $resolveur->credentials($membre),
        );
    }

    public function test_le_resolveur_ne_propose_que_le_public_actif(): void
    {
        Organization::factory()->create(['slug' => 'auth-pas-publique', 'is_active' => true, 'is_public' => false]);
        Organization::factory()->create(['slug' => 'auth-pas-active', 'is_active' => false, 'is_public' => true]);

        $resolveur = app(LoginAccountResolver::class);
        $slugs = $resolveur->organizationsProposables()->pluck('slug');

        $this->assertTrue($slugs->contains('auth-pub-a'));
        $this->assertTrue($slugs->contains('auth-pub-b'));
        $this->assertFalse($slugs->contains('auth-privee'));
        $this->assertFalse($slugs->contains('auth-pas-publique'));
        $this->assertFalse($slugs->contains('auth-pas-active'));

        $this->assertNotNull($resolveur->organizationSelectionnable('auth-pub-a'));
        $this->assertNull($resolveur->organizationSelectionnable('auth-privee'));
        $this->assertNull($resolveur->organizationSelectionnable('auth-pas-active'));
        $this->assertNull($resolveur->organizationSelectionnable(null));
    }
}
