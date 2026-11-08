<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\RssSource;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use App\Services\Blog\RssArticleImporter;
use App\Services\Blog\RssFeedReader;
use App\Services\Blog\RssFeedUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1785 — l'importateur RSS, des douze angles demandes par le CDC.
 *
 * ## Aucun appel reseau, et c'est verifie plutot que promis
 *
 * Le CDC l'interdit. Deux chemins pouvaient en faire un, et les deux sont
 * fermes :
 *
 *  - le HTTP passe par `Http::fake()` ;
 *  - la RESOLUTION DNS est un appel reseau aussi, souvent oublie. La garde
 *    expose `resoudre()` en `protected` exprès, et les tests la surchargent
 *    avec une table. `Http::preventStrayRequests()` ferme la porte restante.
 *
 * ## Ce que ces tests cherchent a rendre impossible
 *
 * Trois choses qui ne se voient pas a l'ecran :
 *
 *  1. qu'un nom de domaine anodin resolvant vers les metadonnees Cloud passe —
 *     c'est tout l'objet de la precaution « apres resolution DNS » ;
 *  2. qu'un reimport ECRASE un article retravaille a la main ;
 *  3. qu'une Boucle d'un autre tenant se lie a l'article.
 */
class TASK1785RssImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Si un appel HTTP reel fuyait, le test echouerait au lieu de sortir
        // sur le reseau.
        Http::preventStrayRequests();
    }

    private function flux(): string
    {
        return file_get_contents(base_path('tests/Fixtures/Rss/wordpress.xml'));
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

    /** Une garde dont le resolveur est une TABLE, donc sans DNS. */
    private function garde(array $table): RssFeedUrlGuard
    {
        return new class($table) extends RssFeedUrlGuard
        {
            public function __construct(private array $table) {}

            protected function resoudre(string $nom): array
            {
                return $this->table[$nom] ?? [];
            }
        };
    }

    // =====================================================================
    // 1. Lecture d'un flux WordPress depuis une fixture
    // =====================================================================

    public function test_the_reader_extracts_the_wordpress_fields_without_inventing_any(): void
    {
        $articles = app(RssFeedReader::class)->lire($this->flux());

        $this->assertCount(3, $articles);

        $premier = $articles[0];
        $this->assertSame('Vigilance face aux demarchages commerciaux', $premier['titre']);
        $this->assertSame('https://cpformation.com/vigilance-demarchages/', $premier['url']);
        $this->assertSame('Camille', $premier['auteur']);
        $this->assertStringContainsString('Premier paragraphe', (string) $premier['contenu_html']);

        // « Ne pas inventer de contenu manquant » : les champs absents du flux
        // restent null, ils ne sont pas devines depuis les voisins.
        $this->assertNull($articles[1]['auteur']);
        $this->assertNull($articles[2]['url']);
        $this->assertNull($articles[2]['contenu_html']);
    }

    /**
     * Precaution MASTER n°1 — l'identite doit tenir SANS GUID fiable, et rester
     * stable d'une lecture a l'autre.
     */
    public function test_the_source_identity_falls_back_and_stays_stable_across_reads(): void
    {
        $reader = app(RssFeedReader::class);

        $a = $reader->lire($this->flux());
        $b = $reader->lire($this->flux());

        // Determinisme : c'est lui qui fait tenir l'anti-doublon au
        // rafraichissement. Sans lui, chaque relecture creerait des doublons.
        $this->assertSame(array_column($a, 'identite'), array_column($b, 'identite'));

        // Les trois etages sont exerces par la fixture, du plus fiable au
        // moins mauvais.
        $this->assertStringStartsWith('guid:', $a[0]['identite']);
        $this->assertStringStartsWith('url:', $a[1]['identite']);
        $this->assertStringStartsWith('titre:', $a[2]['identite']);
    }

    // =====================================================================
    // 2 et 3. Selection partielle, totale, et rafraichissement
    // =====================================================================

    public function test_a_partial_selection_imports_only_what_was_chosen(): void
    {
        $org = $this->organization('sel-partielle');
        $admin = $this->superadmin($org);
        $articles = app(RssFeedReader::class)->lire($this->flux());

        $resultat = app(RssArticleImporter::class)->importer([$articles[0]], $org, $admin);

        $this->assertCount(1, $resultat['crees']);
        $this->assertSame(1, BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->count());
    }

    public function test_refreshing_the_feed_creates_nothing_the_second_time(): void
    {
        $org = $this->organization('refresh');
        $admin = $this->superadmin($org);
        $articles = app(RssFeedReader::class)->lire($this->flux());
        $importer = app(RssArticleImporter::class);

        $premier = $importer->importer($articles, $org, $admin);
        $this->assertCount(3, $premier['crees']);

        // Second passage, meme flux — c'est le rafraichissement.
        $second = $importer->importer($articles, $org, $admin);

        $this->assertCount(0, $second['crees']);
        $this->assertCount(3, $second['doublons']);
        $this->assertSame(3, BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->count());
    }

    // =====================================================================
    // 4. Le meme article dans PLUSIEURS Organizations
    // =====================================================================

    /**
     * L'unicite est PAR ORGANIZATION, jamais globale. Ce test echouerait si
     * l'index unique avait ete pose sur `source_guid` seul.
     *
     * Il garde aussi le slug : `blog_posts_slug_unique` est GLOBAL, donc le
     * second import doit obtenir un slug different, sinon l'insertion heurte
     * l'index et l'import echoue.
     */
    public function test_the_same_source_article_can_be_imported_into_two_organizations(): void
    {
        $a = $this->organization('tenant-a');
        $b = $this->organization('tenant-b');
        $articles = app(RssFeedReader::class)->lire($this->flux());
        $importer = app(RssArticleImporter::class);

        $resA = $importer->importer([$articles[0]], $a, $this->superadmin($a));
        $resB = $importer->importer([$articles[0]], $b, $this->superadmin($b));

        $this->assertCount(1, $resA['crees']);
        $this->assertCount(1, $resB['crees'], 'le meme article source doit pouvoir entrer dans une AUTRE Organization');

        $slugs = BlogPost::withoutGlobalScopes()->pluck('slug');
        $this->assertCount(2, $slugs->unique(), 'les deux slugs doivent differer : l\'index de slug est GLOBAL');
    }

    // =====================================================================
    // 5 et 6. La Boucle facultative, et le refus cross-tenant
    // =====================================================================

    public function test_an_optional_loop_of_the_same_organization_is_linked(): void
    {
        $org = $this->organization('avec-boucle');
        $admin = $this->superadmin($org);
        $boucle = Loop::factory()->create(['organization_id' => $org->id]);
        $articles = app(RssFeedReader::class)->lire($this->flux());

        app(RssArticleImporter::class)->importer([$articles[0]], $org, $admin, $boucle);

        $article = BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $this->assertTrue($article->loops()->whereKey($boucle->id)->exists());
    }

    /**
     * Le test adversarial : une Boucle d'une AUTRE Organization ne doit jamais
     * se lier. Le serveur ne fait pas confiance a ce que le navigateur renvoie.
     */
    public function test_a_loop_from_another_organization_is_never_linked(): void
    {
        $org = $this->organization('cible');
        $autre = $this->organization('etrangere');
        $admin = $this->superadmin($org);
        $boucleEtrangere = Loop::factory()->create(['organization_id' => $autre->id]);
        $articles = app(RssFeedReader::class)->lire($this->flux());

        app(RssArticleImporter::class)->importer([$articles[0]], $org, $admin, $boucleEtrangere);

        $article = BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();

        $this->assertSame(0, $article->loops()->count(), 'aucune liaison cross-tenant');
    }

    // =====================================================================
    // 7. Anti-doublons : l'editorial n'est JAMAIS ecrase
    // =====================================================================

    public function test_a_reimport_never_overwrites_editorial_changes(): void
    {
        $org = $this->organization('editorial');
        $admin = $this->superadmin($org);
        $articles = app(RssFeedReader::class)->lire($this->flux());
        $importer = app(RssArticleImporter::class);

        $importer->importer([$articles[0]], $org, $admin);

        // Un administrateur retravaille l'article : titre, corps, statut.
        $article = BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $article->update([
            'title' => 'Titre entierement reecrit par la redaction',
            'content' => '<p>Un texte qui nous appartient.</p>',
            'status' => 'published',
        ]);

        $importer->importer([$articles[0]], $org, $admin);

        $article->refresh();
        $this->assertSame('Titre entierement reecrit par la redaction', $article->title);
        $this->assertSame('<p>Un texte qui nous appartient.</p>', $article->content);
        $this->assertSame('published', $article->status);
    }

    // =====================================================================
    // 8. Droits SuperAdmin
    // =====================================================================

    public function test_the_tool_is_reachable_by_a_superadmin(): void
    {
        $org = $this->organization('droits-ok');

        $this->actingAs($this->superadmin($org))
            ->get(route('admin.outils.import-rss'))
            ->assertOk();
    }

    /**
     * En LECTURE comme en ECRITURE, dit le CDC : les trois surfaces sont
     * verifiees, pas seulement la page.
     */
    #[DataProvider('surfacesProtegees')]
    public function test_a_non_superadmin_is_refused_on_every_surface(string $methode, string $route): void
    {
        $org = $this->organization('droits-ko-'.strtolower($methode));
        $membre = User::factory()->create(['organization_id' => $org->id, 'is_admin' => false]);

        $this->actingAs($membre)
            ->call($methode, route($route))
            ->assertForbidden();
    }

    /** @return array<string, array{string, string}> */
    public static function surfacesProtegees(): array
    {
        return [
            'la page' => ['GET', 'admin.outils.import-rss'],
            'le chargement' => ['POST', 'admin.outils.import-rss.charger'],
            'l\'import' => ['POST', 'admin.outils.import-rss.importer'],
        ];
    }

    // =====================================================================
    // 9. HTML malveillant, SSRF, flux invalide
    // =====================================================================

    public function test_malicious_html_from_the_feed_is_sanitized_before_storage(): void
    {
        $org = $this->organization('xss');
        $admin = $this->superadmin($org);
        $articles = app(RssFeedReader::class)->lire($this->flux());

        app(RssArticleImporter::class)->importer([$articles[0]], $org, $admin);

        $article = BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();

        // La fixture contient `<script>alert('xss')</script>` dans
        // `content:encoded`.
        $this->assertStringNotContainsString('<script', $article->content);
        $this->assertStringNotContainsString('alert(', $article->content);

        // Ce qui doit survivre y est : paragraphes, titres, listes, liens.
        foreach (['<p>', '<h2>', '<ul>', '<li>', '<a href'] as $balise) {
            $this->assertStringContainsString($balise, $article->content, "{$balise} doit survivre a la sanitation");
        }

        // L'attribution VISIBLE, exigee par le CDC.
        $this->assertStringContainsString('https://cpformation.com/vigilance-demarchages/', $article->content);
    }

    /**
     * Precaution MASTER n°2 — la validation DNS seule ne suffit pas.
     *
     * Le cas qui compte est le DERNIER : un nom parfaitement anodin, dont
     * l'enregistrement pointe vers les metadonnees Cloud. Une garde qui ne lit
     * que la CHAINE le laisse passer.
     */
    #[DataProvider('urlsRefusees')]
    public function test_the_guard_refuses_dangerous_targets(string $url, string $raisonAttendue): void
    {
        $garde = $this->garde([
            'flux-legitime.test' => ['93.184.216.34'],
            'metadonnees.test' => ['169.254.169.254'],
            'mixte.test' => ['93.184.216.34', '127.0.0.1'],
            'mappee.test' => ['::ffff:10.0.0.5'],
        ]);

        $examen = $garde->examiner($url);

        $this->assertFalse($examen['autorisee'], "{$url} doit etre refusee");
        $this->assertSame($raisonAttendue, $examen['raison']);
    }

    /** @return array<string, array{string, string}> */
    public static function urlsRefusees(): array
    {
        return [
            'schema de fichier' => ['file:///etc/passwd', 'schema_interdit'],
            'boucle locale litterale' => ['http://127.0.0.1/feed', 'adresse_interdite'],
            'nom local' => ['http://localhost/feed', 'hote_local'],
            'ipv6 de boucle' => ['http://[::1]/feed', 'adresse_interdite'],
            'identifiants dans l\'url' => ['https://user:pw@flux-legitime.test/feed', 'identifiants_dans_l_url'],
            'nom non resolu' => ['https://inconnu.test/feed', 'resolution_impossible'],
            'une adresse sur deux interdite' => ['https://mixte.test/feed', 'adresse_interdite'],
            'ipv4 habillee en ipv6' => ['https://mappee.test/feed', 'adresse_interdite'],
            'METADONNEES CLOUD derriere un nom anodin' => ['https://metadonnees.test/feed', 'adresse_interdite'],
        ];
    }

    public function test_a_legitimate_public_feed_url_is_allowed(): void
    {
        // Le temoin : sans lui, une garde qui refuse TOUT passerait les neuf
        // cas ci-dessus sans rien prouver.
        $garde = $this->garde(['flux-legitime.test' => ['93.184.216.34']]);

        $this->assertTrue($garde->estAutorisee('https://flux-legitime.test/feed/'));
    }

    /**
     * Precaution MASTER n°3 — la protection XXE ne peut PAS etre un drapeau.
     *
     * `FeedIo\Reader\Document::loadDomDocument()` appelle `loadXML()` sans
     * aucune option : une option passee a la bibliotheque n'atteindrait jamais
     * l'appel qui compte. D'ou un REFUS en amont, sur la chaine.
     */
    public function test_a_feed_carrying_an_entity_declaration_is_refused_before_parsing(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/hostname">]>'
            .'<rss><channel><item><title>&x;</title></item></channel></rss>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('xml_avec_declaration_interdite');

        app(RssFeedReader::class)->lire($xxe);
    }

    /** Un DOCTYPE en COMMENTAIRE est inoffensif : le refuser serait un faux positif. */
    public function test_a_doctype_inside_a_comment_is_not_a_false_positive(): void
    {
        $xml = str_replace('<channel>', "<!-- <!DOCTYPE exemple> -->\n<channel>", $this->flux());

        $this->assertCount(3, app(RssFeedReader::class)->lire($xml));
    }

    #[DataProvider('fluxInvalides')]
    public function test_an_invalid_feed_is_refused_with_a_stable_code(string $contenu, string $code): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($code);

        app(RssFeedReader::class)->lire($contenu);
    }

    /** @return array<string, array{string, string}> */
    public static function fluxInvalides(): array
    {
        return [
            'pas du xml' => ['ceci n\'est pas du xml', 'xml_illisible'],
            'xml sans article' => ['<rss><channel></channel></rss>', 'flux_sans_article'],
        ];
    }

    // =====================================================================
    // 10, 11 et 12. Statut, provenance, URLs et navigation reciproque
    // =====================================================================

    public function test_imported_articles_are_drafts_and_carry_their_provenance(): void
    {
        $org = $this->organization('brouillon');
        $admin = $this->superadmin($org);
        $articles = app(RssFeedReader::class)->lire($this->flux());

        app(RssArticleImporter::class)->importer([$articles[0]], $org, $admin);

        $article = BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();

        $this->assertSame('draft', $article->status);
        $this->assertNull($article->published_at);

        // La provenance est conservee, et la date SOURCE est distincte de
        // `published_at` : un article de 2026 importe aujourd'hui ne doit pas
        // se faire passer pour publie chez nous a sa date d'origine.
        $this->assertSame($articles[0]['identite'], $article->source_guid);
        $this->assertSame('https://cpformation.com/vigilance-demarchages/', $article->source_url);
        $this->assertNotNull($article->source_published_at);
        $this->assertSame('2026-11-03', $article->source_published_at->toDateString());
    }

    /**
     * La publication exige les DEUX cases : l'intention ET les droits. Une
     * seule ne suffit pas, et c'est ce que le CDC appelle « publication
     * explicite, soumise a confirmation des droits de republication ».
     */
    public function test_publishing_requires_both_the_intent_and_the_rights_confirmation(): void
    {
        $org = $this->organization('publication');
        $admin = $this->superadmin($org);
        $articles = app(RssFeedReader::class)->lire($this->flux());
        $importer = app(RssArticleImporter::class);

        // Intention seule -> brouillon.
        $importer->importer([$articles[0]], $org, $admin, null, publier: false);
        $brouillon = BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame('draft', $brouillon->status);

        // Les deux -> publie.
        $importer->importer([$articles[1]], $org, $admin, null, publier: true);
        $publie = BlogPost::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('source_guid', $articles[1]['identite'])
            ->firstOrFail();
        $this->assertSame('published', $publie->status);
        $this->assertNotNull($publie->published_at);
    }

    /**
     * Le selecteur de Boucle, et le piege qui l'avait rendu MUET.
     *
     * Defaut trouve en recette reelle le 08/11 : le selecteur restait vide
     * alors que l'Organization avait bien des Boucles, et sans la moindre
     * erreur a l'ecran.
     *
     * La cause n'etait ni le controleur — qui rendait les bonnes donnees quand
     * on l'appelait directement — ni le routeur, qui MATCHAIT l'URI (verifie).
     * C'etait le NOM DU PARAMETRE : `organization` est reserve par
     * `ResolveUrlOrganization`, qui le lit comme un slug de tenant et rend 404
     * quand il n'en est pas un. Renomme en `organizationId`.
     *
     * Ce test garde la route de bout en bout, par HTTP : seul ce chemin-la
     * traverse le middleware, et c'est le seul qui aurait rougi.
     */
    public function test_the_loops_endpoint_answers_over_http_for_the_right_organization(): void
    {
        $org = $this->organization('endpoint-boucles');
        $autre = $this->organization('endpoint-autre');
        $admin = $this->superadmin($org);

        $sienne = Loop::factory()->create(['organization_id' => $org->id, 'name' => 'La sienne']);
        Loop::factory()->create(['organization_id' => $autre->id, 'name' => 'Celle d\'un autre']);

        $reponse = $this->actingAs($admin)
            ->getJson(route('admin.outils.import-rss.boucles', ['organizationId' => $org->id]))
            ->assertOk();

        $corps = $reponse->json();

        $this->assertCount(1, $corps, 'seules les Boucles de l\'Organization demandee');
        $this->assertSame((string) $sienne->id, $corps[0]['id']);
        $this->assertSame('La sienne', $corps[0]['name']);
    }

    public function test_both_screens_link_to_each_other(): void
    {
        $org = $this->organization('navigation');
        $admin = $this->superadmin($org);

        $this->actingAs($admin)
            ->get(route('admin.outils.import-rss'))
            ->assertOk()
            ->assertSee(route('admin.blog'), escape: false);

        $this->actingAs($admin)
            ->get(route('admin.blog'))
            ->assertOk()
            ->assertSee(route('admin.outils.import-rss'), escape: false);
    }

    /**
     * Le parcours complet par HTTP, de l'URL a la confirmation — c'est lui qui
     * prouve que les pieces s'assemblent, et non seulement qu'elles marchent
     * separement.
     */
    public function test_the_full_journey_from_url_to_confirmation(): void
    {
        $org = $this->organization('parcours');
        $admin = $this->superadmin($org);

        Http::fake(['*' => Http::response($this->flux(), 200, ['Content-Type' => 'application/rss+xml'])]);

        // La garde est remplacee par une version a resolveur de table : aucune
        // resolution DNS, donc aucun appel reseau.
        $this->app->instance(RssFeedUrlGuard::class, $this->garde(['cpformation.test' => ['93.184.216.34']]));

        $charge = $this->actingAs($admin)->post(route('admin.outils.import-rss.charger'), [
            'url' => 'https://cpformation.test/feed/',
        ]);
        $charge->assertSessionHas('rss_charge', 3);

        // TASK-1789 — la selection se fait desormais par id persistant de
        // `RssSourceArticle`, jamais par index de tableau. On retrouve les
        // deux premiers articles du flux par leur IDENTITE (source_identity),
        // jamais par `orderBy('created_at')` : deux lignes inserees dans la
        // meme requete ont le meme horodatage a la seconde, et cet ordre ne
        // serait pas deterministe (piege deja releve dans ce depot).
        $source = RssSource::where('url', 'https://cpformation.test/feed/')->firstOrFail();
        $articlesDuFlux = app(RssFeedReader::class)->lire($this->flux());
        $selection = $source->articles()
            ->whereIn('source_identity', [$articlesDuFlux[0]['identite'], $articlesDuFlux[1]['identite']])
            ->pluck('id');

        $import = $this->actingAs($admin)->post(route('admin.outils.import-rss.importer'), [
            'rss_source_id' => $source->id,
            'organization_id' => $org->id,
            'selection' => $selection->all(),
        ]);

        $import->assertSessionHas('rss_resultat');
        $resultat = session('rss_resultat');

        $this->assertCount(2, $resultat['crees']);
        $this->assertCount(0, $resultat['erreurs']);
        $this->assertSame(2, BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->count());
    }

    /**
     * Le double clic : deux imports de la meme selection ne creent qu'un
     * article. C'est l'index unique qui le garantit, pas une lecture prealable.
     */
    public function test_a_double_submission_creates_a_single_article(): void
    {
        $org = $this->organization('double-clic');
        $admin = $this->superadmin($org);
        $articles = app(RssFeedReader::class)->lire($this->flux());
        $importer = app(RssArticleImporter::class);

        $importer->importer([$articles[0]], $org, $admin);
        $importer->importer([$articles[0]], $org, $admin);

        $this->assertSame(1, BlogPost::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('source_guid', $articles[0]['identite'])
            ->count());
    }

    /**
     * La base refuse-t-elle VRAIMENT un doublon, ou est-ce seulement le code ?
     *
     * La question merite d'etre posee directement : si l'index unique n'etait
     * pas la, tous les tests ci-dessus passeraient quand meme — la lecture
     * prealable suffirait a les satisfaire — et la garantie tomberait au
     * premier import concurrent.
     */
    public function test_the_database_itself_refuses_a_duplicate_source_in_the_same_organization(): void
    {
        $org = $this->organization('index-base');
        $admin = $this->superadmin($org);

        BlogPost::create([
            'user_id' => $admin->id,
            'organization_id' => $org->id,
            'title' => 'Premier',
            'slug' => 'premier-index-base',
            'content' => '<p>x</p>',
            'status' => 'draft',
            'source_guid' => 'guid:identite-partagee',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        BlogPost::create([
            'user_id' => $admin->id,
            'organization_id' => $org->id,
            'title' => 'Second',
            'slug' => 'second-index-base',
            'content' => '<p>y</p>',
            'status' => 'draft',
            'source_guid' => 'guid:identite-partagee',
        ]);
    }
}
