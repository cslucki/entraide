<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
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
 */
class RssArticleImporter
{
    public function __construct(
        private readonly BlogHtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  list<array{identite: string, guid: string|null, url: string|null, titre: string, auteur: string|null, resume: string|null, contenu_html: string|null, publie_le: string|null}>  $articles
     * @return array{crees: list<array{titre: string, id: string}>, doublons: list<array{titre: string, id: string}>, erreurs: list<array{titre: string, raison: string}>}
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
                $resultat['doublons'][] = ['titre' => $existant->title, 'id' => (string) $existant->id];

                continue;
            }

            try {
                $article = $this->creer($entree, $organization, $auteur, $publier);
            } catch (QueryException $e) {
                // L'index unique a parle : une autre requete vient de creer le
                // meme article. Ce n'est pas une erreur, c'est l'anti-doublon
                // qui fonctionne — y compris sur un double clic.
                $concurrent = $this->dejaImporte($entree['identite'], $organization);

                if ($concurrent !== null) {
                    $resultat['doublons'][] = ['titre' => $concurrent->title, 'id' => (string) $concurrent->id];

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

            $resultat['crees'][] = ['titre' => $article->title, 'id' => (string) $article->id];
        }

        return $resultat;
    }

    /**
     * @param  array{identite: string, guid: string|null, url: string|null, titre: string, auteur: string|null, resume: string|null, contenu_html: string|null, publie_le: string|null}  $entree
     */
    private function creer(array $entree, Organization $organization, User $auteur, bool $publier): BlogPost
    {
        $publieLe = $this->date($entree['publie_le']);

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
        $brut = $entree['contenu_html'] ?? $entree['resume'] ?? '';

        // `<script>` et `<style>` sont retires AVEC leur contenu, AVANT la
        // sanitation generale.
        //
        // La sanitation du depot repose sur `strip_tags()`, qui retire la
        // balise mais CONSERVE son texte : mesure en test, un
        // `<script>alert('xss')</script>` venu du flux laissait `alert('xss')`
        // visible en plein milieu de l'article. Ce n'est pas une faille — le
        // texte est echappe a l'affichage — mais c'est du code source expose
        // au lecteur, et sur du contenu tiers non relu c'est inacceptable.
        $brut = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $brut) ?? $brut;

        $corps = trim($this->sanitizer->sanitizeHtml($brut));

        if ($corps === '') {
            // Rien d'exploitable : on n'invente pas de texte. L'attribution
            // ci-dessous suffit a faire un article de renvoi honnete, ce que le
            // CDC appelle « une synthese courte avec lien vers l'original ».
            $corps = '<p></p>';
        }

        $attribution = $this->attribution($entree);

        return $attribution === null ? $corps : $corps.$attribution;
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

    private function dejaImporte(string $identite, Organization $organization): ?BlogPost
    {
        return BlogPost::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->where('source_guid', $identite)
            ->first();
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

    private function date(?string $brut): ?string
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
