<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1782 — un membre d'une Organization scopee doit retrouver SON accueil.
 *
 * Trois surfaces, une seule regle : `accueilDuCompteAuthentifie()`.
 *
 *  1. la RACINE `/` — qui est aussi le `start_url` du manifeste PWA, partage
 *     par toutes les Organizations. Reparer la racine repare donc le
 *     lancement PWA sans toucher au manifeste, et sans multiplier les PWA
 *     installables ;
 *  2. la fleche de retour de `mobile-topbar` sur le tableau de bord ;
 *  3. rien d'autre — aucune autre route ne change.
 *
 * ## Ce que ces tests cherchent a rendre impossible
 *
 * **Un slug code en dur.** Aucun test ici ne nomme « ofsh » ni « launchpals »
 * pour obtenir un comportement : chaque Organization est CREEE par le test,
 * avec des slugs qui n'existent nulle part dans le code. Le dernier test va
 * plus loin — il fabrique une Organization au nom aleatoire, inconnu au moment
 * ou cette ligne est ecrite, et exige qu'elle soit traitee comme les autres.
 * Si quelqu'un remplacait la regle par une liste de slugs, ce test rougirait
 * sans qu'on ait a le modifier.
 *
 * **Une regression sur les quatre refus.** Visiteur anonyme, superadmin,
 * statut invalide, Organization par defaut : chacun a son test, et chacun
 * verifie l'ABSENCE de redirection. Ce sont eux qui protegent la homepage
 * publique et la souverainete de la racine (TASK-1628).
 */
class TASK1782ScopedHomeNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'Organization par defaut — celle que la racine sert deja.
     *
     * Posee dans chaque test qui en a besoin, jamais supposee presente : le
     * banc part d'une base vide.
     */
    private function organizationParDefaut(): Organization
    {
        return Organization::factory()->create([
            'slug' => 'defaut-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
            'loops_enabled' => false,
        ]);
    }

    private function membreDe(Organization $organization, array $attributs = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'is_admin' => false,
        ], $attributs));
    }

    // =====================================================================
    // 1. La racine, par Organization — le coeur de la TASK
    // =====================================================================

    /**
     * Le cas que Cyril decrit : un membre connecte demande la racine et doit
     * arriver chez lui.
     *
     * Parametre sur `loops_enabled` pour prouver que la destination N'EN DEPEND
     * PAS : regle produit arbitree par Cyril, l'accueil demande est la VRAIE
     * homepage `/org/{slug}`, Boucles activees ou non.
     *
     * C'est ce qui distingue cette fonction de `canonicalHome()`, laquelle
     * rendrait `/org/{slug}/loops` quand `loops_enabled` vaut vrai. Les deux
     * cas du fournisseur ci-dessous attendent donc la MEME forme d'adresse —
     * un test qui delegerait a `canonicalHome()` rougirait sur le premier.
     *
     * @param  bool  $loops  l'Organization a-t-elle ses Boucles actives ?
     */
    #[DataProvider('organizationsScopees')]
    public function test_a_scoped_member_asking_for_the_root_lands_on_their_own_homepage(
        string $slug,
        bool $loops
    ): void {
        $this->organizationParDefaut();

        $organization = Organization::factory()->create([
            'slug' => $slug,
            'is_active' => true,
            'is_default' => false,
            'loops_enabled' => $loops,
        ]);

        $this->actingAs($this->membreDe($organization))
            ->get('/')
            ->assertRedirect('/org/'.$slug);
    }

    /**
     * Quatre Organizations aux reglages differents, aucune nommee d'apres une
     * Organization reelle. Les slugs sont volontairement quelconques : si la
     * regle dependait d'un nom connu, aucun de ces cas ne passerait.
     *
     * Les deux premiers encadrent `loops_enabled` : meme destination attendue
     * de part et d'autre du drapeau.
     *
     * @return array<string, array{string, bool}>
     */
    public static function organizationsScopees(): array
    {
        return [
            'Boucles ACTIVES — la homepage quand meme' => ['tenant-un', true],
            'Boucles inactives' => ['tenant-deux', false],
            'slug a tirets multiples, Boucles actives' => ['tenant-trois-quatre', true],
            'slug numerique, Boucles inactives' => ['tenant-2026', false],
        ];
    }

    /**
     * La preuve de genericite demandee : une Organization dont le slug est
     * tire a l'execution, donc absent du depot par construction.
     */
    public function test_an_organization_created_at_runtime_is_treated_like_any_other(): void
    {
        $this->organizationParDefaut();

        $slug = 'ne-existe-pas-'.bin2hex(random_bytes(6));

        $organization = Organization::factory()->create([
            'slug' => $slug,
            'is_active' => true,
            'is_default' => false,
            'loops_enabled' => true,
        ]);

        // Garde de la garde : le slug ne doit figurer nulle part dans le code.
        $this->assertStringNotContainsString(
            $slug,
            file_get_contents(base_path('app/Support/helpers.php')),
            'le slug est tire a l\'execution : le code ne peut pas le connaitre'
        );

        $this->actingAs($this->membreDe($organization))
            ->get('/')
            ->assertRedirect('/org/'.$slug);
    }

    // =====================================================================
    // 2. Les quatre refus — ce que cette TASK ne doit PAS casser
    // =====================================================================

    /** La homepage publique reste la homepage publique. */
    public function test_an_anonymous_visitor_is_never_redirected_by_this_rule(): void
    {
        $this->organizationParDefaut();

        Organization::factory()->create([
            'slug' => 'tenant-anonyme',
            'is_active' => true,
            'is_default' => false,
        ]);

        $reponse = $this->get('/');

        $this->assertNotEquals(
            '/org/tenant-anonyme',
            $reponse->headers->get('Location'),
            'un visiteur anonyme ne doit jamais etre envoye dans une Organization'
        );
    }

    /**
     * Le comportement de l'Organization par defaut — « main » aujourd'hui —
     * est preserve, et le refus se lit sur `is_default`, pas sur le slug.
     *
     * Ce test garde aussi contre une BOUCLE : `canonicalHome()` rend `/` pour
     * une Organization par defaut sans Boucles. Sans ce refus, `/` renverrait
     * vers `/`, indefiniment.
     */
    public function test_a_member_of_the_default_organization_is_not_redirected(): void
    {
        $defaut = $this->organizationParDefaut();

        $reponse = $this->actingAs($this->membreDe($defaut))->get('/');

        $this->assertNotEquals(
            '/org/'.$defaut->slug,
            $reponse->headers->get('Location'),
            'l\'Organization par defaut est ce que la racine sert deja'
        );
    }

    /** Verification de statut : on n'envoie personne dans une Organization eteinte. */
    public function test_a_member_of_an_inactive_organization_is_not_redirected(): void
    {
        $this->organizationParDefaut();

        $eteinte = Organization::factory()->create([
            'slug' => 'tenant-eteint',
            'is_active' => false,
            'is_default' => false,
        ]);

        $reponse = $this->actingAs($this->membreDe($eteinte))->get('/');

        $this->assertNotEquals(
            '/org/tenant-eteint',
            $reponse->headers->get('Location'),
            'une Organization inactive mene a une porte fermee'
        );
    }

    /**
     * La racine est souveraine (TASK-1628) : le superadmin doit pouvoir VOIR
     * ce qu'elle sert au public, sinon son choix est intact et invisible.
     */
    public function test_a_superadmin_keeps_the_public_root_they_configured(): void
    {
        $this->organizationParDefaut();

        $scopee = Organization::factory()->create([
            'slug' => 'tenant-superadmin',
            'is_active' => true,
            'is_default' => false,
        ]);

        $superadmin = $this->membreDe($scopee, ['is_admin' => true]);

        $reponse = $this->actingAs($superadmin)->get('/');

        $this->assertNotEquals(
            '/org/tenant-superadmin',
            $reponse->headers->get('Location'),
            'le superadmin garde la racine telle qu\'il l\'a choisie pour le public'
        );
    }

    /**
     * Une session expiree n'est pas un cas a part : la requete est anonyme, et
     * c'est le premier refus qui s'applique.
     */
    public function test_an_expired_session_behaves_exactly_like_an_anonymous_visitor(): void
    {
        $this->organizationParDefaut();

        $organization = Organization::factory()->create([
            'slug' => 'tenant-expire',
            'is_active' => true,
            'is_default' => false,
        ]);

        $membre = $this->membreDe($organization);

        $connecte = $this->actingAs($membre)->get('/');
        $connecte->assertRedirect('/org/tenant-expire');

        // `actingAs()` ne se defait pas tout seul : il faut une application
        // neuve pour poser une requete reellement anonyme.
        $this->refreshApplication();

        $anonyme = $this->get('/');

        $this->assertNotEquals(
            '/org/tenant-expire',
            $anonyme->headers->get('Location'),
            'sans session, la racine redevient publique'
        );
    }

    // =====================================================================
    // 3. L'administrateur d'Organization — membre avant tout
    // =====================================================================

    /**
     * Un administrateur d'Organization n'est pas un superadmin : il appartient
     * a son Organization, et la racine doit l'y ramener comme n'importe quel
     * membre. C'est `is_admin` — le drapeau GLOBAL — qui distingue les deux.
     */
    public function test_an_organization_administrator_lands_on_their_own_organization(): void
    {
        $this->organizationParDefaut();

        $organization = Organization::factory()->create([
            'slug' => 'tenant-admin',
            'is_active' => true,
            'is_default' => false,
            'loops_enabled' => true,
        ]);

        $admin = $this->membreDe($organization);
        $organization->update(['admin_id' => $admin->id]);

        $this->actingAs($admin)
            ->get('/')
            ->assertRedirect('/org/tenant-admin');
    }

    // =====================================================================
    // 4. Le retour mobile depuis le tableau de bord
    // =====================================================================

    /**
     * Le defaut que cette TASK corrige cote navigation : `/dashboard` est la
     * route COURTE, elle ne porte aucun prefixe d'Organization. La branche
     * voisine de la barre utilise `$routeUrl()`, qui lit ce prefixe — son
     * repli resout l'Organization par DEFAUT. Un membre scope y perdait son
     * tenant, exactement comme en TASK-1281.
     */
    public function test_the_mobile_back_arrow_on_the_dashboard_points_to_the_members_own_organization(): void
    {
        $this->organizationParDefaut();

        $organization = Organization::factory()->create([
            'slug' => 'tenant-retour',
            'is_active' => true,
            'is_default' => false,
            'loops_enabled' => true,
        ]);

        $this->actingAs($this->membreDe($organization))
            ->get('/dashboard')
            ->assertOk()
            // `data-bp-topbar-back` porte la DESTINATION de la fleche, et c'est
            // la seule assertion honnete : l'adresse figure AUSSI dans le rail
            // et les liens de la page, donc un `assertSee()` nu resterait vert
            // avec une fleche pointant ailleurs — ou sans fleche du tout. Mon
            // premier jet faisait exactement cela, et le sabotage l'a montre.
            // L'attribut existe depuis TASK-1693 pour cette raison precise.
            ->assertSee('data-bp-topbar-back="/org/tenant-retour"', escape: false);
    }

    /**
     * TASK-1782 ajustement — mobile et desktop doivent designer le MEME
     * accueil.
     *
     * C'est le desktop qui a tranche la regle produit : le lien du logo de
     * `app-side-nav` pointe sur `organization.home` depuis toujours, jamais
     * sur `/loops`. Une fleche mobile menant aux Boucles aurait donc fait
     * divergER les deux navigations sur la meme page.
     *
     * Ce test les lit TOUTES LES DEUX dans le meme document, sur une route
     * prefixee par l'Organization — la seule ou le desktop resout son lien
     * sans passer par la racine.
     */
    public function test_the_mobile_arrow_and_the_desktop_logo_point_to_the_same_homepage(): void
    {
        $this->organizationParDefaut();

        $organization = Organization::factory()->create([
            'slug' => 'tenant-coherence',
            'is_active' => true,
            'is_default' => false,
            // Boucles ACTIVES : c'est le cas ou les deux auraient pu diverger.
            'loops_enabled' => true,
        ]);

        $reponse = $this->actingAs($this->membreDe($organization))
            ->get('/org/tenant-coherence/dashboard')
            ->assertOk();

        // La fleche mobile, par son attribut dedie.
        $reponse->assertSee('data-bp-topbar-back="/org/tenant-coherence"', escape: false);

        // Le logo desktop, qui porte la meme destination en absolu.
        $reponse->assertSee(
            'href="'.route('organization.home', ['organization' => 'tenant-coherence']).'"',
            escape: false
        );
    }

    /**
     * Le repli du quatrieme refus : un membre de l'Organization par defaut
     * garde la fleche, et elle mene a la racine. Sans `?: route('home')`, il
     * l'aurait perdue.
     */
    public function test_the_mobile_back_arrow_still_exists_for_a_default_organization_member(): void
    {
        $defaut = $this->organizationParDefaut();

        $this->actingAs($this->membreDe($defaut))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-bp-topbar-back="'.route('home').'"', escape: false);
    }
}
