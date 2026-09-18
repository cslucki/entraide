<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Homepage\RootDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1506 — le superadmin choisit ce que sert la RACINE : accueil
 * traditionnel, Shell Welcome, blog, annuaire ou boucles.
 *
 * Trois choses se mesurent, et elles sont distinctes :
 *  - la RACINE honore le choix (c'est le produit) ;
 *  - le choix par defaut ne change RIEN (une plateforme qui n'ouvre jamais
 *    l'ecran doit se comporter comme avant) ;
 *  - l'annuaire reste ferme aux anonymes. TASK-1479 (P0 privacy) l'a ferme ;
 *    faire de la racine un raccourci vers lui ne doit pas le rouvrir. C'est la
 *    garde la plus importante de cette TASK.
 */
class TASK1506RootDestinationTest extends TestCase
{
    use RefreshDatabase;

    private function defaultOrganization(?string $destination = null, bool $public = true): Organization
    {
        return Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
            // L'Organization par defaut reelle (`main`) est publique. Ce drapeau
            // decide si un ANONYME peut lire le blog : voir le test dedie.
            'is_public' => $public,
            'homepage_template' => 'default',
            'root_destination' => $destination,
        ]);
    }

    private function superAdmin(): User
    {
        $platform = Organization::factory()->create(['slug' => 'plateforme-1506']);

        return User::factory()->create(['is_admin' => true, 'organization_id' => $platform->id]);
    }

    public function test_without_a_choice_the_root_keeps_its_historical_behaviour(): void
    {
        $this->defaultOrganization(null);

        $this->get('/')->assertOk();
    }

    public function test_an_unknown_stored_value_falls_back_to_the_historical_behaviour(): void
    {
        // Une valeur inconnue laissee en base (migration, import, retrait d'une
        // modalite) ne doit pas rediriger vers nulle part.
        $organization = $this->defaultOrganization(null);
        $organization->forceFill(['root_destination' => 'annuaire-des-licornes'])->saveQuietly();

        $this->get('/')->assertOk();
    }

    public function test_each_public_destination_is_served_from_the_root(): void
    {
        foreach ([RootDestination::BLOG => 'blog.index', RootDestination::LOOPS => 'boucles.index'] as $mode => $route) {
            $organization = $this->defaultOrganization(null);
            $organization->update(['root_destination' => $mode]);

            $this->get('/')->assertRedirect(route($route));

            // Et la destination est bien PUBLIQUE : un anonyme la voit.
            $this->get(route($route))->assertOk();

            $organization->forceDelete();
        }
    }

    public function test_shell_welcome_serves_the_default_organization_landing(): void
    {
        $organization = $this->defaultOrganization(RootDestination::SHELL_WELCOME);

        $this->get('/')->assertRedirect(route('organization.home', $organization));
    }

    /**
     * La garde de privacy. TASK-1479 a ferme l'annuaire aux anonymes ; si la
     * racine y menait en 200 sans compte, cette TASK aurait rouvert le trou.
     */
    public function test_the_directory_destination_never_opens_the_directory_to_anonymous_visitors(): void
    {
        $organization = $this->defaultOrganization(RootDestination::DIRECTORY);

        $this->get('/')->assertRedirect(route('members.index'));

        // Suivre la redirection depuis la racine, sans compte, ne donne PAS
        // l'annuaire : la connexion s'interpose.
        $response = $this->followingRedirects()->get('/');
        $response->assertOk();
        $this->assertStringNotContainsString('members', url()->current(), 'un anonyme ne doit pas atterrir sur l annuaire');

        $this->assertTrue(RootDestination::requiresAuthentication(RootDestination::DIRECTORY));
        $this->get(route('members.index'))->assertRedirect(route('login'));
    }

    /**
     * Le second mur, moins evident que l'annuaire : le blog d'une Organization
     * PRIVEE repond 404 a un anonyme (`BlogController::assertOrganizationBlogIsReadable`).
     * Choisir « blog » alors que l'Organization par defaut est privee sert donc
     * un 404 a chaque visiteur — l'ecran d'administration doit le dire AVANT.
     */
    public function test_a_private_default_organization_hides_the_blog_from_anonymous_visitors(): void
    {
        $this->defaultOrganization(RootDestination::BLOG, public: false);

        $this->get('/')->assertRedirect(route('blog.index'));
        $this->get(route('blog.index'))->assertNotFound();
    }

    public function test_the_admin_page_warns_when_the_default_organization_is_private(): void
    {
        $this->defaultOrganization(RootDestination::BLOG, public: false);
        $admin = $this->superAdmin();

        $html = $this->actingAs($admin)->get(route('admin.homepage'))->assertOk()->getContent();
        $this->assertStringContainsString('data-root-destination-private-org', $html, 'l avertissement « organisation privee » manque');

        // Publique : pas d'avertissement.
        Organization::where('slug', 'main')->first()->update(['is_public' => true]);
        $html = $this->actingAs($admin)->get(route('admin.homepage'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-root-destination-private-org', $html);
    }

    public function test_the_admin_page_lists_every_mode_and_flags_the_ones_that_need_an_account(): void
    {
        $this->defaultOrganization(RootDestination::BLOG);
        $admin = $this->superAdmin();

        $html = $this->actingAs($admin)->get(route('admin.homepage'))->assertOk()->getContent();

        foreach (RootDestination::MODES as $mode) {
            $this->assertStringContainsString('data-root-destination-option="'.$mode.'"', $html, "modalite {$mode} absente");
        }

        // L'annuaire est annonce comme demandant une connexion AVANT le choix.
        $this->assertSame(1, preg_match('/data-root-destination-option="directory".*?data-root-destination-auth="(\w+)"/s', $html, $m));
        $this->assertSame('required', $m[1], 'l annuaire doit etre annonce comme demandant une connexion');

        $this->assertStringContainsString('data-root-destination-current', $html, 'la modalite active doit etre marquee');
    }

    /**
     * `layouts/admin` n'emet AUCUN jeton de theme : une couleur ecrite
     * `var(--bp-primary)` y resout a RIEN — mesure au navigateur, le bouton
     * d'enregistrement etait blanc sur transparent et la bordure de la carte
     * active, noire. Un no-op silencieux, le pire des defauts.
     */
    public function test_the_page_never_relies_on_theme_tokens_the_admin_layout_does_not_emit(): void
    {
        $this->defaultOrganization(RootDestination::BLOG);
        $admin = $this->superAdmin();

        $html = $this->actingAs($admin)->get(route('admin.homepage'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<main\b.*?<\/main>/s', $html, $m), 'contenu principal introuvable');
        $this->assertStringNotContainsString('var(--bp-', $m[0], 'le layout superadmin n emet pas ces jetons : la couleur serait un no-op');

        // Et la couleur d'action est bien posee.
        $this->assertMatchesRegularExpression('/data-root-destination-submit[^>]*>|bg-indigo-600[^"]*"[^>]*data-root-destination-submit/', $m[0]);
        $this->assertStringContainsString('bg-indigo-600', $m[0]);
    }

    public function test_the_superadmin_can_change_what_the_root_serves(): void
    {
        $organization = $this->defaultOrganization(null);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->put(route('admin.homepage.update'), ['root_destination' => RootDestination::LOOPS])
            ->assertRedirect(route('admin.homepage'));

        $this->assertSame(RootDestination::LOOPS, $organization->fresh()->root_destination);
        $this->get('/')->assertRedirect(route('boucles.index'));
    }

    public function test_an_invalid_choice_is_refused(): void
    {
        $organization = $this->defaultOrganization(RootDestination::BLOG);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->put(route('admin.homepage.update'), ['root_destination' => 'le-module-qui-n-existe-pas'])
            ->assertSessionHasErrors('root_destination');

        $this->assertSame(RootDestination::BLOG, $organization->fresh()->root_destination);
    }

    public function test_the_page_is_reserved_to_superadmins(): void
    {
        $organization = $this->defaultOrganization(null);
        $member = User::factory()->create(['organization_id' => $organization->id, 'is_admin' => false]);

        $this->actingAs($member)->get(route('admin.homepage'))->assertForbidden();
        $this->actingAs($member)->put(route('admin.homepage.update'), ['root_destination' => RootDestination::BLOG])->assertForbidden();
        $this->assertNull($organization->fresh()->root_destination);
    }

    /**
     * La suite tourne en anglais : un `lang/fr/admin.php` casse par une
     * apostrophe non echappee restait INVISIBLE, et la page rendait un 500 en
     * francais pendant que 12 tests etaient verts. La garde charge les deux
     * fichiers et exige les memes cles.
     */
    public function test_both_locales_carry_every_label_of_this_screen(): void
    {
        $fr = require lang_path('fr/admin.php');
        $en = require lang_path('en/admin.php');

        $keys = array_values(array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'root_destination')));
        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $fr, "cle {$key} absente du francais");
            $this->assertNotSame('', trim((string) $fr[$key]), "cle {$key} vide en francais");
        }

        $this->assertSame($keys, array_values(array_filter(array_keys($fr), fn ($k) => str_starts_with($k, 'root_destination'))));

        // Et la page rend REELLEMENT en francais.
        $this->defaultOrganization(RootDestination::BLOG, public: false);
        $admin = $this->superAdmin();
        $this->actingAs($admin)->withHeaders(['Accept-Language' => 'fr'])->get(route('admin.homepage'))->assertOk();

        app()->setLocale('fr');
        $this->actingAs($admin)->get(route('admin.homepage'))->assertOk()
            ->assertSee(__('admin.root_destination_mode_directory'));
    }

    public function test_the_menu_carries_the_page_in_the_organisations_section(): void
    {
        $this->defaultOrganization(null);
        $admin = $this->superAdmin();

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('admin.homepage').'"', $html);
    }
}
