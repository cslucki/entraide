<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * TASK-1785 — creer des articles BouclePro depuis des entrees de flux.
 *
 * ## L'anti-doublon ne repose PAS sur une lecture prealable
 *
 * Lire « existe-t-il deja ? » puis ecrire laisse une fenetre entre les deux :
 * deux clics simultanes la traversent tous les deux et creent deux articles.
 * La garantie vient donc de l'index unique
 * `blog_posts_org_source_guid_unique (organization_id, source_guid)` : la
 * seconde insertion est REFUSEE PAR LA BASE, et ce refus est rattrape ici et
 * compte comme « deja importe ».
 *
 * La lecture prealable existe quand meme, mais pour une autre raison : rendre
 * le bon compte a l'ecran sans provoquer d'erreur dans le cas courant. C'est
 * l'index qui protege, pas elle.
 *
 * ## Un reimport n'ecrase JAMAIS l'editorial
 *
 * Quand l'identite source est deja connue pour cette Organization, l'article
 * existant est rendu TEL QUEL. Aucune mise a jour, pas meme du titre : un
 * administrateur a pu le retravailler, le renommer, le republier. Le CDC
 * l'exige, et c'est aussi la seule conduite defendable — un import n'a aucun
 * moyen de savoir ce qui a ete voulu depuis.
 *
 * ## Le slug doit etre rendu unique, et ce n'est pas un detail
 *
 * `blog_posts_slug_unique` est GLOBAL, pas par Organization. Or le CDC veut
 * qu'un meme article source puisse entrer dans deux Organizations : sans
 * suffixage, le second import heurterait cet index et echouerait. Mesure du
 * 08/11 : `BlogController::store()` ne gere aucune unicite de slug — ce
 * service ne peut donc pas s'appuyer sur le chemin existant pour cela.
 *
 * ## Les droits de republication
 *
 * Le statut est `draft`, toujours, sauf demande EXPLICITE accompagnee de la
 * confirmation de droits. Rien ne se publie a la consultation ni au
 * rafraichissement. Et l'attribution est posee dans le contenu lui-meme —
 * lien visible vers la source — parce qu'un champ de provenance en base ne se
 * voit pas du lecteur.
 *
 * ## L'angle mort soft-delete (TASK-1789)
 *
 * `dejaImporte()` interroge desormais AVEC `withTrashed()`. Sans cela, un
 * article importe puis supprime devenait invisible a cette lecture — alors
 * que l'index unique `(organization_id, source_guid)`, lui, N'EST PAS partiel
 * et refuse quand meme la reinsertion. Le code tombait alors dans le
 * `catch (QueryException)` de `importer()`, dont la seconde verification
 * EXCLUAIT elle aussi les lignes supprimees : le resultat rendu etait
 * `erreurs: echec_enregistrement`, un message faux qui ne dit rien de ce qui
 * s'est reellement passe.
 *
 * Decision MASTER (precheck TASK-1789), explicite : corriger la LECTURE, pas
 * l'index. Un article supprime reste donc bloquant pour une reimportation —
 * ce comportement ne change pas — mais il est desormais rapporte comme ce
 * qu'il est : un doublon dont l'original a ete supprime (`supprime: true`
 * dans le resultat), jamais comme un echec d'enregistrement. Aucune
 * recreation automatique, aucune restauration : hors perimetre de cette TASK.
 *
 * ## `statutsParOrganization()` est une lecture A PART (TASK-1790)
 *
 * La fiche individuelle doit savoir, pour TOUTES les Organizations, si un
 * article source est deja importe et sous quel statut — pas seulement pour
 * l'Organization en cours de choix. `dejaImporte()` reste PRIVEE : c'est une
 * primitive d'ECRITURE (elle sert `importer()` a decider s'il cree ou non),
 * et la rendre publique l'exposerait sans necessite, decision MASTER
 * explicite. `statutsParOrganization()` est une requete INDEPENDANTE, pensee
 * pour la lecture seule, qui ne passe par aucune methode interne d'import.
 */
class RssArticleImporter
{
    public function __construct(
        private readonly BlogHtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  list<array{identite: string, guid: string|null, url: string|null, titre: string, auteur: string|null, resume: string|null, contenu_html: string|null, publie_le: string|null}>  $articles
     * @return array{crees: list<array{titre: string, id: string}>, doublons: list<array{titre: string, id: string, supprime: bool}>, erreurs: list<array{titre: string, raison: string}>}
     */
    public function importer(
        array $articles,
        Organization $organization,
        User $auteur,
        ?Loop $boucle = null,
        bool $publier = false,
    ): array {
        $boucle = $this->boucleValide($boucle, $organization);

        $resultat = ['crees' => [], 'doublons' => [], 'erreurs' => []];

        foreach ($articles as $entree) {
            $existant = $this->dejaImporte($entree['identite'], $organization);

            if ($existant !== null) {
                // Rendu TEL QUEL : aucune mise a jour de l'editorial.
                $resultat['doublons'][] = $this->ligneDoublon($existant);

                continue;
            }

            try {
                $article = $this->creer($entree, $organization, $auteur, $publier);
            } catch (QueryException $e) {
                // L'index unique a parle : une autre requete vient de creer le
                // meme article (ou en porte deja un, supprime depuis — voir
                // `dejaImporte()`). Ce n'est pas une erreur, c'est l'anti-doublon
                // qui fonctionne — y compris sur un double clic.
                $concurrent = $this->dejaImporte($entree['identite'], $organization);

                if ($concurrent !== null) {
                    $resultat['doublons'][] = $this->ligneDoublon($concurrent);

                    continue;
                }

                // Une autre contrainte a cede : on le dit, sans dupliquer ce
                // qui a deja ete cree pour les entrees precedentes.
                $resultat['erreurs'][] = ['titre' => $entree['titre'], 'raison' => 'echec_enregistrement'];

                continue;
            }

            if ($boucle !== null) {
                $article->loops()->syncWithoutDetaching([$boucle->id]);
            }

            // TASK-1793 — le SLUG accompagne l'id : `BlogPost::getRouteKeyName()`
            // rend 'slug', donc une URL batie sur l'id tombe en 404.
            $resultat['crees'][] = ['titre' => $article->title, 'id' => (string) $article->id, 'slug' => $article->slug];
        }

        return $resultat;
    }

    /**
     * @return array{titre: string, id: string, slug: string, supprime: bool}
     */
    private function ligneDoublon(BlogPost $post): array
    {
        return [
            'titre' => $post->title,
            'id' => (string) $post->id,
            // TASK-1793 — cle de route reelle (voir `getRouteKeyName()`).
            'slug' => $post->slug,
            // `trashed()` fonctionne ici parce que `dejaImporte()` a lu AVEC
            // `withTrashed()` : un article ordinaire rend toujours `false`.
            'supprime' => $post->trashed(),
        ];
    }

    /**
     * @param  array{identite: string, guid: string|null, url: string|null, titre: string, auteur: string|null, resume: string|null, contenu_html: string|null, publie_le: string|null}  $entree
     */
    private function creer(array $entree, Organization $organization, User $auteur, bool $publier): BlogPost
    {
        $publieLe = self::dateSource($entree['publie_le']);

        return BlogPost::create([
            'user_id' => $auteur->id,
            'organization_id' => $organization->id,
            'title' => Str::limit($entree['titre'], 250, ''),
            'slug' => $this->slugUnique($entree['titre']),
            'summary' => $entree['resume'] !== null
                ? Str::limit(strip_tags($entree['resume']), 480, '')
                : null,
            'content' => $this->contenu($entree),
            'status' => $publier ? 'published' : 'draft',
            // `published_at` ne reprend JAMAIS la date source : elle dit quand
            // BouclePro a publie. La date d'origine vit dans
            // `source_published_at`, comme provenance.
            'published_at' => $publier ? now() : null,
            'source_guid' => $entree['identite'],
            'source_url' => $entree['url'],
            'source_published_at' => $publieLe,
        ]);
    }

    /**
     * Le corps de l'article : contenu assaini, et l'attribution VISIBLE.
     *
     * @param  array{url: string|null, auteur: string|null, contenu_html: string|null, resume: string|null, titre: string}  $entree
     */
    private function contenu(array $entree): string
    {
        $corps = $this->contenuAssaini($entree['contenu_html'] ?? null, $entree['resume'] ?? null);
        $attribution = $this->attribution($entree);

        return $attribution === null ? $corps : $corps.$attribution;
    }

    /**
     * Le HTML d'un article source, assaini — SEULE fabrique pour l'ecriture
     * (ici, avant `BlogPost::create()`) et pour l'apercu de la fiche
     * individuelle (TASK-1790, `RssArticleFiche`). Deux appelants, une seule
     * regle : si l'un se durcit sans l'autre, la fiche montrerait un contenu
     * plus permissif que celui realement enregistre, ou l'inverse.
     *
     * Public exprès : c'est une lecture, pas un point d'entree d'ECRITURE —
     * a la difference de `dejaImporte()`, restee privee.
     */
    public function contenuAssaini(?string $brut, ?string $repli): string
    {
        $html = $brut ?? $repli ?? '';

        // `<script>` et `<style>` sont retires AVEC leur contenu, AVANT la
        // sanitation generale.
        //
        // La sanitation du depot repose sur `strip_tags()`, qui retire la
        // balise mais CONSERVE son texte : mesure en test, un
        // `<script>alert('xss')</script>` venu du flux laissait `alert('xss')`
        // visible en plein milieu de l'article. Ce n'est pas une faille — le
        // texte est echappe a l'affichage — mais c'est du code source expose
        // au lecteur, et sur du contenu tiers non relu c'est inacceptable.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;

        $corps = trim($this->sanitizer->sanitizeHtml($html));

        if ($corps === '') {
            // Rien d'exploitable : on n'invente pas de texte. L'attribution
            // ci-dessous suffit a faire un article de renvoi honnete, ce que le
            // CDC appelle « une synthese courte avec lien vers l'original ».
            $corps = '<p></p>';
        }

        return $corps;
    }

    /**
     * @param  array{url: string|null, auteur: string|null}  $entree
     */
    private function attribution(array $entree): ?string
    {
        if ($entree['url'] === null || trim($entree['url']) === '') {
            return null;
        }

        // Le lien est echappe a la main : ce bloc est assemble APRES la
        // sanitation, donc il ne passe pas par elle.
        $url = e($entree['url']);
        $auteur = $entree['auteur'] !== null ? e($entree['auteur']) : null;

        $source = $auteur !== null
            ? __('blog.rss_attribution_with_author', ['auteur' => $auteur])
            : __('blog.rss_attribution');

        return '<hr><p><em>'.$source.' <a href="'.$url.'" rel="nofollow noopener" target="_blank">'.$url.'</a></em></p>';
    }

    /**
     * `withTrashed()` est INDISPENSABLE (TASK-1789) : l'index unique
     * `(organization_id, source_guid)` n'est pas partiel et bloque une
     * reinsertion meme quand la ligne existante est supprimee. Lire sans
     * `withTrashed()` rendait cette lecture aveugle a exactement le cas que
     * l'index refuse — d'ou le faux `echec_enregistrement` corrige ici.
     */
    private function dejaImporte(string $identite, Organization $organization): ?BlogPost
    {
        return BlogPost::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->withTrashed()
            ->where('organization_id', $organization->id)
            ->where('source_guid', $identite)
            ->first();
    }

    /**
     * Le statut d'un article source, PAR ORGANIZATION — lecture dediee pour
     * la fiche individuelle (TASK-1790), independante de `dejaImporte()`.
     *
     * Trois statuts possibles par ligne rendue ; l'ABSENCE d'une Organization
     * dans la collection rendue EST le quatrieme statut — « non importe » —
     * et ne necessite donc aucune valeur a part : une Organization absente
     * n'a jamais ete touchee par cette identite.
     *
     * `withTrashed()` pour la MEME raison qu'en TASK-1789 : un article
     * supprime reste « deja importe » au sens de l'index unique, et doit
     * donc apparaitre ici — sinon la fiche proposerait « Creer le brouillon »
     * sur une Organization ou l'identite est deja prise, ce que l'ecriture
     * refuserait aussitot.
     *
     * @return Collection<string, array{blog_post_id: string, blog_post_slug: string, trashed: bool, statut: string}>
     *         Cle = `organization_id`.
     */
    public function statutsParOrganization(string $identite): Collection
    {
        return $this->statutsParOrganizationPourPlusieurs([$identite])->get($identite, collect());
    }

    /**
     * Version GROUPEE de `statutsParOrganization()` (TASK-1792) : une seule
     * requete `BlogPost` pour TOUTES les identites demandees, au lieu d'une
     * par article. L'historique editorial liste des dizaines d'articles par
     * page — les interroger un par un aurait ete du N+1 pur, exactement ce
     * que le CDC (§10) demande d'eviter. Meme fabrique de statut que la
     * version singuliere : extraite une seule fois (`ligneStatut()`), jamais
     * dupliquee entre les deux methodes.
     *
     * @param  list<string>  $identites
     * @return Collection<string, Collection<string, array{blog_post_id: string, blog_post_slug: string, trashed: bool, statut: string}>>
     *         Cle externe = `source_identity`, cle interne = `organization_id`.
     */
    public function statutsParOrganizationPourPlusieurs(array $identites): Collection
    {
        if ($identites === []) {
            return collect();
        }

        return BlogPost::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->withTrashed()
            ->whereIn('source_guid', $identites)
            ->get(['id', 'organization_id', 'slug', 'status', 'deleted_at', 'source_guid'])
            ->groupBy('source_guid')
            ->map(fn ($posts) => $posts
                ->keyBy(fn (BlogPost $post) => (string) $post->organization_id)
                ->map(fn (BlogPost $post) => $this->ligneStatut($post)));
    }

    /**
     * @return array{blog_post_id: string, blog_post_slug: string, trashed: bool, statut: string}
     */
    private function ligneStatut(BlogPost $post): array
    {
        return [
            'blog_post_id' => (string) $post->id,
            'blog_post_slug' => $post->slug,
            'trashed' => $post->trashed(),
            'statut' => match (true) {
                $post->trashed() => 'supprime',
                $post->status === 'published' => 'publie',
                default => 'brouillon',
            },
        ];
    }

    /**
     * `blog_posts_slug_unique` est GLOBAL : le suffixage est donc indispensable
     * des qu'un titre se repete, y compris entre deux Organizations.
     */
    private function slugUnique(string $titre): string
    {
        $base = Str::slug(Str::limit($titre, 180, '')) ?: 'article';
        $slug = $base;
        $rang = 1;

        while ($this->slugPris($slug)) {
            $rang++;
            $slug = $base.'-'.$rang;
        }

        return $slug;
    }

    private function slugPris(string $slug): bool
    {
        return BlogPost::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->withTrashed()
            ->where('slug', $slug)
            ->exists();
    }

    /**
     * La validation de la Boucle est faite ICI, cote serveur, et non sur la
     * foi de ce que le navigateur a renvoye.
     *
     * Une Boucle d'une autre Organization n'est pas une erreur a signaler :
     * elle est simplement IGNOREE. Le CDC demande que le changement
     * d'Organization reinitialise une Boucle devenue incompatible — c'est
     * exactement ce cas, vu du serveur.
     */
    private function boucleValide(?Loop $boucle, Organization $organization): ?Loop
    {
        if ($boucle === null) {
            return null;
        }

        return (string) $boucle->organization_id === (string) $organization->id
            ? $boucle
            : null;
    }

    /**
     * Public et statique (TASK-1789) : `AdminRssImportController::charger()`
     * en a desormais besoin pour mettre en cache `RssSourceArticle.source_published_at`
     * au MEME format — une seule fabrique, jamais deux implementations qui
     * pourraient diverger.
     */
    public static function dateSource(?string $brut): ?string
    {
        if ($brut === null || trim($brut) === '') {
            return null;
        }

        try {
            // `Carbon::parse()` en STATIQUE : l'intention est plus claire
            // qu'un `now()->parse(...)`, qui laisse croire a une base de
            // calcul relative.
            //
            // Un piege RELEVE ICI, et qui n'est PAS un defaut de ce code : une
            // date RFC-822 dont le nom de jour CONTREDIT la date — « Mon, 03
            // Nov 2026 », alors que ce 3 novembre est un mardi — fait avancer
            // PHP au lundi SUIVANT, soit six jours plus loin. Ma premiere
            // fixture portait cette incoherence, et j'ai d'abord accuse
            // `now()->parse()` a tort.
            //
            // Rien n'est « corrige » a la lecture : un flux qui se contredit
            // est cru tel qu'il se lit. Deviner laquelle des deux valeurs est
            // la bonne reviendrait a inventer, ce que le CDC interdit.
            return Carbon::parse($brut)->toDateTimeString();
        } catch (\Throwable) {
            // Une date illisible n'empeche pas l'import : elle se perd, et le
            // reste de l'article vaut mieux que rien.
            return null;
        }
    }
}
