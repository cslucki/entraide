<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class SitemapController extends Controller
{
    /**
     * Les SEULES surfaces publiees — TASK-1673.
     *
     * (Ce travail a d'abord ete prepare sous TASK-1671, qui n'a JAMAIS ete
     * mergee : elle portait VERSION 1.671 et TASK-1672 est passee devant, si
     * bien que la merger aurait fait RECULER la VERSION de `develop`. Le
     * contenu est repris a l'identique ici. Chercher « TASK-1671 » dans
     * l'historique de `develop` ne rendrait rien.)
     *
     * CE QUI A CHANGE, ET POURQUOI.
     *
     * Ce generateur datait de TASK-007 et interrogeait `Service::active()` et
     * `User::discoverable()` pour emettre `services.show`, `profile.show` et
     * `explorer`. Depuis, deux decisions P0 privacy ont FERME ces trois
     * surfaces au Web anonyme :
     *
     * - TASK-1479 : « "profil public" veut dire visible des autres membres,
     *   pas ouvert au Web anonyme » ;
     * - TASK-1488 : la fiche d'un Service cesse d'etre servie a un visiteur
     *   anonyme ou a un membre d'une autre Organization.
     *
     * Le sitemap n'avait jamais suivi. Mesure du 08/10/2026 : il emettait
     * **54 URL dont 53 non indexables** — toutes derriere `auth` +
     * `organization.member`, donc 302 vers `/login` pour un crawler —, et
     * exposait **52 UUID utilisateurs** de trois Organizations dans un
     * document public annonce par `robots.txt`.
     *
     * CE QUE CETTE LISTE N'EST PAS. Ce n'est pas une abstraction SEO : c'est
     * une liste nommee, fermee, et verifiee par un test qui exige que chacune
     * de ces routes existe et reponde 200 a un visiteur ANONYME. Toute entree
     * ajoutee ici doit satisfaire les quatre regles d'inclusion de TASK-1673 :
     * destinee au Web public, repond anonymement sans authentification, ne
     * revele aucune donnee utilisateur privee, et reste coherente avec
     * T1479/T1488.
     *
     * NE PAS y remettre une famille d'URL issue d'une requete sur un modele
     * porteur de `BelongsToOrganizationScope`. Le sitemap tourne sans contexte
     * tenant (`/sitemap.xml` est dans `$platformGlobalExact` de
     * `ResolveUrlOrganization` : aucune Organization n'y est liee), donc une
     * telle requete repartirait en `whereRaw('0 = 1')` — et le refus serait
     * legitime, pas a contourner.
     *
     * EXCLUSIONS MESUREES, et non supposees :
     * - `explorer`, `profile.show`, `services.show` : 302 vers `/login` ;
     * - `subscriptions` (`/abonnements`) : la route existe et parait publique
     *   dans `route:list`, mais rend **404** — `SubscriptionController::index`
     *   fait `abort(404)` quand l'Organization resolue n'a pas
     *   `subscriptions_enabled`. C'est une surface conditionnee a la
     *   configuration d'un tenant : l'annoncer dans un sitemap
     *   plateforme-global publierait une URL morte.
     *
     * @var list<array{name: string, changefreq: string, priority: string}>
     */
    private const PUBLIC_SURFACES = [
        ['name' => 'organization.home', 'changefreq' => 'daily', 'priority' => '1.0'],
        ['name' => 'organization.mentions-legales', 'changefreq' => 'yearly', 'priority' => '0.3'],
    ];

    /**
     * Les surfaces a publier.
     *
     * Une methode, et non la constante lue directement, pour une seule raison :
     * la garde de route manquante ci-dessous doit pouvoir etre EXERCEE par un
     * test sur le vrai `index()`. Sans cette couture, le test ne pourrait que
     * recopier la logique — et prouverait sa propre copie, pas la production.
     *
     * @return list<array{name: string, changefreq: string, priority: string}>
     */
    protected function publicSurfaces(): array
    {
        return self::PUBLIC_SURFACES;
    }

    /** Les seuls tenants dont la homepage est volontairement publique. */
    protected function publicOrganizations()
    {
        return Organization::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('created_at')
            ->get(['id', 'slug']);
    }

    public function index(): Response
    {
        $urls = [];

        foreach ($this->publicOrganizations() as $organization) {
            foreach ($this->publicSurfaces() as $surface) {
                // Une route renommee ne doit pas rendre `/sitemap.xml`
                // indisponible aux moteurs. Elle est omise et journalisee ;
                // la CI exige par ailleurs que chaque nom existe.
                if (! Route::has($surface['name'])) {
                    Log::warning('SitemapController: declared public route is missing; URL omitted.', [
                        'route' => $surface['name'],
                    ]);

                    continue;
                }

                $urls[] = [
                    'loc' => route($surface['name'], ['organization' => $organization->slug]),
                    'changefreq' => $surface['changefreq'],
                    'priority' => $surface['priority'],
                ];
            }
        }

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}
