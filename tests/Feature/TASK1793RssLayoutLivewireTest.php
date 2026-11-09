<?php

namespace Tests\Feature;

use App\Livewire\Admin\RssArticleFiche;
use App\Livewire\Admin\RssEditorialHistory;
use App\Livewire\Admin\RssSourceArticles;
use App\Livewire\Admin\RssSources;
use App\Models\Organization;
use App\Models\RssSource;
use App\Models\RssSourceArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * TASK-1793 — le defaut P0 trouve en RECETTE NAVIGATEUR, invisible aux tests
 * T1790/T1792 pourtant verts.
 *
 * ## Ce qui s'est reellement passe
 *
 * Les quatre composants Livewire PLEINE PAGE du cockpit RSS mettaient
 * `<x-admin-layout>` comme element RACINE de leur vue. Au premier rendu, tout
 * semble correct : la page complete s'affiche. Mais `<x-admin-layout>` produit
 * un document ENTIER (`<!DOCTYPE html><html>…`), donc la racine du composant
 * EST le document.
 *
 * Or Livewire, apres chaque action, remplace (morph) le sous-arbre DOM de la
 * racine du composant. Remplacer le document entier ne fonctionne pas : le
 * navigateur rend une page BLANCHE et la console leve `Uncaught (in promise)`.
 *
 * Consequence mesuree en navigateur : TOUTE l'interactivite du cockpit etait
 * morte — renommer, actualiser, desactiver, archiver, les six filtres de
 * l'historique, la recherche, la pagination, et le parcours
 * `creerBrouillon()` de T1790. Seuls les rendus INITIAUX marchaient.
 *
 * ## Pourquoi aucun test ne l'a vu
 *
 * `Livewire::test()` rend cote serveur et n'execute JAMAIS le morphing DOM
 * d'un vrai navigateur : une action y « reussit » meme quand elle casse
 * l'ecran. Un test PHP ne peut donc pas constater la page blanche — mais il
 * PEUT constater sa cause, qui est structurelle et deterministe : le rendu
 * propre du composant doit etre un FRAGMENT, jamais un document complet.
 *
 * C'est exactement ce que verifient les tests ci-dessous, des deux cotes :
 *  - le composant rend un fragment (donc morphable) ;
 *  - la route pleine page rend quand meme un document complet avec le layout
 *    admin (donc la correction n'a pas casse l'affichage).
 *
 * Motif de reference deja eprouve dans ce depot : `MyFeedPosts`
 * (`routes/web.php`, `/flux/mes-annonces`) — racine `<div>` + `<x-slot:title>`,
 * layout declare hors de la vue.
 */
class TASK1793RssLayoutLivewireTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<class-string> */
    public static function composantsPleinePage(): array
    {
        return [
            'sources' => [RssSources::class],
            'articles d une source' => [RssSourceArticles::class],
            'fiche article' => [RssArticleFiche::class],
            'historique editorial' => [RssEditorialHistory::class],
        ];
    }

    private function organization(string $slug): Organization
    {
        return Organization::factory()->create([
            'slug' => $slug,
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    private function superadmin(Organization $org): User
    {
        return User::factory()->create(['organization_id' => $org->id, 'is_admin' => true]);
    }

    private function source(): RssSource
    {
        return RssSource::create(['url' => 'https://cpformation.test/feed/']);
    }

    private function article(RssSource $source): RssSourceArticle
    {
        return RssSourceArticle::create([
            'rss_source_id' => $source->id,
            'source_identity' => 'guid:t1793',
            'guid' => 'guid-t1793',
            'url' => 'https://cpformation.test/article/',
            'titre' => 'Un article de recette',
            'auteur' => 'Arnaud',
            'resume' => 'Un resume.',
            'contenu_html' => '<p>Contenu.</p>',
            'source_published_at' => now()->subDay(),
            'fetched_at' => now(),
        ]);
    }

    /**
     * LE test du defaut : le rendu propre d'un composant pleine page doit etre
     * un FRAGMENT. S'il contient `<html>` ou `<!DOCTYPE>`, Livewire ne peut pas
     * le morpher et l'ecran devient blanc a la premiere action.
     *
     */
    #[DataProvider('composantsPleinePage')]
    public function test_un_composant_pleine_page_rend_un_fragment_morphable(string $composant): void
    {
        $org = $this->organization('fragment-'.strtolower(class_basename($composant)));
        $admin = $this->superadmin($org);
        $this->actingAs($admin);

        $html = $this->rendre($composant);

        $this->assertStringNotContainsStringIgnoringCase('<!DOCTYPE', $html,
            class_basename($composant).' rend un document complet : Livewire ne pourra pas le morpher (page blanche en navigateur).');
        $this->assertStringNotContainsStringIgnoringCase('<html', $html,
            class_basename($composant).' rend une balise <html> : sa racine est le document, pas un fragment.');
    }

    /**
     * L'autre moitie du contrat : la correction ne doit PAS supprimer le
     * layout. La route pleine page doit continuer a rendre un document complet
     * avec la navigation admin.
     *
     */
    #[DataProvider('composantsPleinePage')]
    public function test_un_composant_pleine_page_declare_son_layout_hors_de_la_vue(string $composant): void
    {
        $attributs = (new ReflectionClass($composant))->getAttributes(\Livewire\Attributes\Layout::class);

        $this->assertNotEmpty($attributs,
            class_basename($composant).' ne declare aucun #[Layout] : si la vue ne porte plus le layout, la page perdrait sa navigation admin.');
    }

    /** La preuve par la route reelle : la page complete est bien servie. */
    public function test_la_route_pleine_page_rend_un_document_complet_avec_la_navigation_admin(): void
    {
        $org = $this->organization('route-pleine-page');
        $admin = $this->superadmin($org);
        $source = $this->source();

        $reponse = $this->actingAs($admin)->get(route('admin.outils.import-rss.sources'));

        $reponse->assertOk();
        $reponse->assertSee('<!DOCTYPE html', escape: false);
        // La navigation admin (groupe « Blog », T1792) doit toujours etre la.
        $reponse->assertSee(route('admin.outils.import-rss.sources'), escape: false);
        $reponse->assertSee(__('admin.import_rss.sources.title'));
    }

    private function rendre(string $composant): string
    {
        $source = $this->source();

        return match ($composant) {
            RssSourceArticles::class => Livewire::test($composant, ['rssSource' => $source])->html(),
            RssArticleFiche::class => Livewire::test($composant, ['rssSourceArticle' => $this->article($source)])->html(),
            default => Livewire::test($composant)->html(),
        };
    }

    // =====================================================================
    // Defaut n°2, lui aussi trouve en recette navigateur : un flux
    // INJOIGNABLE sortait en HTTP 500 brut.
    // =====================================================================

    /**
     * `Illuminate\Http\Client\ConnectionException` etend `Exception`, pas
     * `RuntimeException` : elle traversait donc le `catch (RuntimeException)`
     * de TOUS les appelants (ecran en lot ET `actualiser()` de T1792) et
     * ressortait en 500. Un flux injoignable doit rendre un code STABLE,
     * traduisible a l'ecran — c'est le contrat annonce par `recuperer()`.
     */
    public function test_un_flux_injoignable_rend_un_code_stable_et_non_une_erreur_500(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Couldn\'t connect to server'),
        ]);
        $this->app->instance(\App\Services\Blog\RssFeedUrlGuard::class, $this->garde(['injoignable.test' => ['93.184.216.34']]));

        try {
            app(\App\Services\Blog\RssFeedFetcher::class)->recuperer('https://injoignable.test/feed/');
            $this->fail('Un flux injoignable aurait du lever une RuntimeException a code stable.');
        } catch (\RuntimeException $e) {
            $this->assertSame('flux_injoignable', $e->getMessage(),
                'Le code doit rester stable et traduisible, jamais un message de transport brut.');
        }
    }

    /** L'ecran en lot rend la page avec le message, au lieu d'un HTTP 500. */
    public function test_l_ecran_en_lot_affiche_le_message_au_lieu_de_tomber_en_500(): void
    {
        $org = $this->organization('flux-injoignable-lot');
        $admin = $this->superadmin($org);

        \Illuminate\Support\Facades\Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7'),
        ]);
        $this->app->instance(\App\Services\Blog\RssFeedUrlGuard::class, $this->garde(['injoignable.test' => ['93.184.216.34']]));

        $reponse = $this->actingAs($admin)->post(route('admin.outils.import-rss.charger'), [
            'url' => 'https://injoignable.test/feed/',
        ]);

        // L'ecran en lot remonte un CODE STABLE flashe (`rss_erreur`), relu et
        // traduit par la vue — pas une 500, et pas un message de transport.
        $reponse->assertRedirect();
        $reponse->assertSessionHas('rss_erreur', 'flux_injoignable');
    }

    /** Une garde dont le resolveur est une TABLE, donc sans DNS. */
    private function garde(array $table): \App\Services\Blog\RssFeedUrlGuard
    {
        return new class($table) extends \App\Services\Blog\RssFeedUrlGuard
        {
            public function __construct(private array $table) {}

            protected function resoudre(string $nom): array
            {
                return $this->table[$nom] ?? [];
            }
        };
    }


    // =====================================================================
    // Defaut n°3, trouve lui aussi en navigateur : TOUS les liens du cockpit
    // vers l'editeur Blog tombaient en 404.
    // =====================================================================

    /**
     * `BlogPost::getRouteKeyName()` rend `'slug'`. Les quatre liens du cockpit
     * (2 redirections de `creerBrouillon()`, le lien « deja importe » de la
     * fiche, le lien de statut de l'historique) etaient batis sur l'`id` :
     * chacun rendait un 404.
     *
     * ## Pourquoi le test T1792 ne l'a pas vu
     *
     * Il asserait la presence de `route('admin.blog.edit', ['post' => $post->id])`
     * — la MEME construction fautive des deux cotes. Comparer une erreur a
     * elle-meme passe toujours. Ce test-ci ne re-derive rien : il DEMANDE
     * l'URL au serveur et exige un 200.
     */
    public function test_le_lien_du_cockpit_vers_l_editeur_blog_repond_et_ne_tombe_pas_en_404(): void
    {
        $org = $this->organization('lien-editeur-blog');
        $admin = $this->superadmin($org);
        $post = \App\Models\BlogPost::create([
            'organization_id' => $org->id,
            'user_id' => $admin->id,
            'title' => 'Un article importe depuis le flux',
            'content' => '<p>Contenu.</p>',
            'status' => 'draft',
            'source_guid' => 'guid:t1793-lien',
        ]);

        $lignes = app(\App\Services\Blog\RssArticleImporter::class)
            ->statutsParOrganization('guid:t1793-lien');
        $ligne = $lignes->get((string) $org->id);

        $this->assertNotNull($ligne, 'Le statut par Organization doit exposer la ligne de cet article.');

        // L'URL est batie EXACTEMENT comme les vues la batissent...
        $url = route('admin.blog.edit', ['post' => $ligne['blog_post_slug']]);

        // ...et le serveur doit la servir. Un 404 ici signifie que le cockpit
        // pointe vers une page inexistante, comme avant TASK-1793.
        $this->actingAs($admin)->get($url)->assertOk();
    }

    /** La redirection apres creation d'un brouillon doit, elle aussi, aboutir. */
    public function test_la_redirection_apres_creation_de_brouillon_aboutit_sur_l_editeur(): void
    {
        $org = $this->organization('redirection-brouillon');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source);

        $this->actingAs($admin);

        $composant = Livewire::test(\App\Livewire\Admin\RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) $org->id)
            ->call('creerBrouillon');

        $cible = $composant->effects['redirect'] ?? null;
        $this->assertNotNull($cible, 'creerBrouillon() doit rediriger vers l editeur Blog.');

        // La cible est demandee au serveur : si elle etait batie sur l id,
        // elle rendrait 404 (defaut d origine).
        $this->actingAs($admin)->get($cible)->assertOk();
    }


    /**
     * Le retour depuis l'editeur doit rendre le blog SCOPE sur l'Organization
     * de l'article — sinon on atterrit sur l'Organization par defaut, ou
     * l'article qu'on vient d'editer n'apparait pas (precision MASTER).
     */
    public function test_le_retour_depuis_l_editeur_va_au_blog_scope_sur_l_organization_de_l_article(): void
    {
        $org = $this->organization('retour-blog-scope');
        $admin = $this->superadmin($org);
        $post = \App\Models\BlogPost::create([
            'organization_id' => $org->id,
            'user_id' => $admin->id,
            'title' => 'Article a editer puis quitter',
            'content' => '<p>Contenu.</p>',
            'status' => 'draft',
        ]);

        $reponse = $this->actingAs($admin)->get(route('admin.blog.edit', ['post' => $post->slug]));

        $reponse->assertOk();
        $reponse->assertSee(route('admin.blog', ['organization_id' => $org->id]), escape: false);
    }

}
