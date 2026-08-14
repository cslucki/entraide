<?php

namespace App\Services\Loops;

use App\Models\ArticleSeries;
use App\Models\BlogPost;
use App\Models\BlogPostInvitation;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * L'atelier d'ecriture d'une Boucle de Redaction.
 *
 * **Aucun second systeme.** Les Articles sont des `BlogPost`, ranges dans le
 * Dossier de la Boucle par `dossier_blog_posts` ; les co-auteurs sont des
 * `blog_post_invitations` ; les Series sont des `article_series`. Tout cela
 * existe depuis longtemps, avec son editeur TipTap, ses audiences, ses
 * snapshots, ses routes et ses policies.
 *
 * Ce service ne fait que **lire** ces objets sous un autre angle.
 *
 * ## Pourquoi une Card distincte de Dossiers
 *
 * La Card Dossiers montre **ce que la Boucle range** : articles, fichiers et
 * Series confondus, du plus recemment modifie. C'est un classeur.
 *
 * L'atelier repond a une autre question : **qu'est-ce que j'ecris, et qu'est-ce
 * qui attend ?** Un brouillon commence il y a trois semaines n'apparait plus
 * dans un classeur trie par date ; c'est pourtant exactement ce qu'on cherche
 * en revenant ecrire.
 *
 * Les deux Cards vivent ensemble dans le preset Redaction, et la matrice
 * produit les nomme separement.
 */
class LoopWritingService
{
    /** Ce qu'une Card affiche sans devenir une bibliotheque. */
    private const PAGE = 10;

    /** Le Dossier de cette Boucle, s'il y en a un. */
    public function dossierFor(Loop $loop): ?Dossier
    {
        return Dossier::where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->first();
    }

    /**
     * Les brouillons de cette personne, du plus recemment touche.
     *
     * **Les siens**, pas ceux de tout le monde : un brouillon n'est pas encore
     * une parole publique, et l'atelier sert d'abord a reprendre le sien.
     *
     * @return Collection<int, BlogPost>
     */
    public function myDrafts(Loop $loop, User $author, int $limit = self::PAGE): Collection
    {
        $dossier = $this->dossierFor($loop);

        if (! $dossier) {
            return collect();
        }

        // `BlogPost` ne porte **aucun** scope global d'Organization, a la
        // difference de `Service` ou `Transaction` : le cloisonnement se lit
        // ici, il ne se deduit pas du Dossier.
        return $dossier->articles()
            ->where('blog_posts.organization_id', $loop->organization_id)
            ->where('blog_posts.user_id', $author->id)
            ->where('blog_posts.status', 'draft')
            ->reorder()
            ->orderByDesc('blog_posts.updated_at')
            // Departage : `updated_at` est a la seconde sous PostgreSQL, et
            // **quels** brouillons apparaissent serait sinon indetermine.
            ->orderByDesc('blog_posts.id')
            ->limit($limit)
            ->get();
    }

    /**
     * Ce que la Boucle a publie, du plus recent.
     *
     * @return Collection<int, BlogPost>
     */
    public function published(Loop $loop, int $limit = self::PAGE): Collection
    {
        $dossier = $this->dossierFor($loop);

        if (! $dossier) {
            return collect();
        }

        // **`scopePublished` et non un filtre a la main.** Son commentaire dit
        // exactement le defaut que j'avais ecrit : « le document racine d'une
        // Boucle est publie et parfaitement lisible, mais ce n'est pas un
        // article de blog et il ne doit jamais apparaitre dans une liste ».
        //
        // Sans lui, le Manifeste de **chaque** Boucle se lisait comme un
        // Article publie — et le preset Redaction portant aussi `core.manifesto`,
        // il s'affichait deux fois sur le meme ecran. Le scope ecarte aussi les
        // dates de publication futures.
        return $dossier->articles()
            ->published()
            ->where('blog_posts.organization_id', $loop->organization_id)
            ->with('user:id,name,first_name,email,organization_id,banned_at')
            ->reorder()
            // PostgreSQL place les NULL **en tete** d'un tri descendant, SQLite
            // en queue. `scopePublished` exige deja `published_at` non nul, mais
            // l'ordre explicite evite que la liste change de sens selon le
            // moteur si la condition venait a bouger.
            ->orderByRaw('blog_posts.published_at DESC NULLS LAST')
            ->orderByDesc('blog_posts.id')
            ->limit($limit)
            ->get();
    }

    /**
     * Les brouillons des autres, sans leur contenu.
     *
     * On dit **qu'ils existent**, pas ce qu'ils disent : un collectif qui ecrit
     * a besoin de savoir que quelque chose est en cours, sans lire par-dessus
     * l'epaule. Seuls le titre et l'auteur sont montres.
     *
     * @return Collection<int, BlogPost>
     */
    public function othersDrafts(Loop $loop, User $viewer, int $limit = self::PAGE): Collection
    {
        $dossier = $this->dossierFor($loop);

        if (! $dossier) {
            return collect();
        }

        return $dossier->articles()
            ->where('blog_posts.organization_id', $loop->organization_id)
            ->where('blog_posts.status', 'draft')
            ->where('blog_posts.user_id', '!=', $viewer->id)
            ->with('user:id,name,first_name,email,organization_id,banned_at')
            ->reorder()
            ->orderByDesc('blog_posts.updated_at')
            ->orderByDesc('blog_posts.id')
            ->limit($limit)
            ->get();
    }

    /**
     * Les invitations de co-ecriture encore en attente, pour les Articles de
     * cette Boucle.
     *
     * **Aucun second systeme de co-ecriture** : ce sont les
     * `blog_post_invitations` existantes, lues ici pour dire ce qui attend une
     * reponse.
     *
     * @return Collection<int, BlogPostInvitation>
     */
    public function pendingCoAuthors(Loop $loop, int $limit = self::PAGE): Collection
    {
        $dossier = $this->dossierFor($loop);

        if (! $dossier) {
            return collect();
        }

        // **Une sous-requete, pas un `pluck`.** Celui-ci chargeait tous les
        // identifiants du Dossier et les renvoyait dans un `IN (...)` : le
        // nombre de requetes restait plat, mais la charge du binding suivait le
        // nombre d'Articles — 6 valeurs pour 2, 82 pour 40. Un cout qui croit
        // sans qu'aucun compteur de requetes ne le voie.
        return BlogPostInvitation::whereIn(
            'blog_post_id',
            $dossier->articles()->reorder()->select('blog_posts.id'),
        )
            ->where('organization_id', $loop->organization_id)
            ->where('status', 'pending')
            ->with('blogPost:id,title,slug')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Les Series du Dossier, quand il y en a.
     *
     * @return Collection<int, ArticleSeries>
     */
    public function series(Loop $loop, int $limit = self::PAGE): Collection
    {
        $dossier = $this->dossierFor($loop);

        if (! $dossier) {
            return collect();
        }

        return ArticleSeries::where('dossier_id', $dossier->id)
            ->with('rootBlogPost:id,title,slug')
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Combien la Boucle porte d'Articles, tous statuts confondus. */
    public function countFor(Loop $loop): int
    {
        $dossier = $this->dossierFor($loop);

        return $dossier ? $dossier->articles()->reorder()->count() : 0;
    }
}
