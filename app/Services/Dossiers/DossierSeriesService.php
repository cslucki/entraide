<?php

namespace App\Services\Dossiers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les regles d'une Serie, en un seul endroit.
 *
 * Elles etaient jusqu'ici reparties dans DossierSeriesController, ou chaque
 * action les redisait a sa maniere. Un Dossier peut desormais porter plusieurs
 * Series, et une Serie melanger Articles et fichiers : trois lieux differents
 * auraient fini par diverger sur ce que « appartenir a une Serie » veut dire.
 *
 * Le controleur garde ce qui lui revient — autorisation, validation HTTP,
 * reponse. Le service tient les invariants, les transactions et les verrous.
 *
 * Aucune couche generique : ce service ne connait que les Series.
 */
class DossierSeriesService
{
    /**
     * Creer une Serie dans un Dossier.
     *
     * La racine est facultative : une Serie de fichiers n'en a pas. Sans racine,
     * un nom est **obligatoire** — sinon la Serie n'aurait rien a afficher.
     */
    public function create(Dossier $dossier, User $actor, ?BlogPost $root = null, ?string $name = null): ArticleSeries
    {
        $name = $name !== null ? trim($name) : null;

        if ($root === null && $name === '') {
            $name = null;
        }

        if ($root === null && $name === null) {
            throw ValidationException::withMessages([
                'name' => __('dossiers.series_name_required_without_root'),
            ]);
        }

        if ($root !== null) {
            $this->assertBelongsToDossier($root, $dossier);
        }

        return DB::transaction(function () use ($dossier, $actor, $root, $name) {
            if ($root !== null) {
                // Relu sous verrou : entre la verification et l'insertion,
                // quelqu'un a pu prendre le meme Article.
                $this->assertFreeOfAnySeries($root, lock: true);
            }

            return ArticleSeries::create([
                'organization_id' => $dossier->organization_id,
                'dossier_id' => $dossier->id,
                'root_blog_post_id' => $root?->id,
                'name' => $name,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * Ajouter un Article ou un fichier a une Serie.
     *
     * **Exactement un des deux**, jamais les deux, jamais aucun : c'est la
     * signature qui l'impose, un seul contenu entre ici.
     *
     * Et dans le MVP, **un contenu n'appartient qu'a une seule Serie**. Cette
     * regle n'est pas un effet de bord des index uniques : elle est verifiee
     * explicitement, sous verrou, avec un message qui l'explique.
     */
    public function addItem(ArticleSeries $series, BlogPost|DossierFile $content, User $actor): ArticleSeriesItem
    {
        $dossier = $series->dossier;

        if (! $dossier) {
            throw ValidationException::withMessages([
                'content' => __('dossiers.series_dossier_missing'),
            ]);
        }

        $this->assertBelongsToDossier($content, $dossier);

        if ($content instanceof BlogPost && $series->root_blog_post_id === $content->id) {
            throw ValidationException::withMessages([
                'blog_post_id' => __('dossiers.article_is_series_root'),
            ]);
        }

        return DB::transaction(function () use ($series, $content, $actor) {
            // Le verrou serialise les ajouts a CETTE Serie : sans lui, deux
            // ajouts simultanes lisaient le meme maximum de position.
            $locked = ArticleSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();

            $this->assertFreeOfAnySeries($content, lock: true);

            try {
                return ArticleSeriesItem::create([
                    'organization_id' => $locked->organization_id,
                    'article_series_id' => $locked->id,
                    'blog_post_id' => $content instanceof BlogPost ? $content->id : null,
                    'dossier_file_id' => $content instanceof DossierFile ? $content->id : null,
                    'position' => ((int) $locked->items()->max('position')) + 1,
                    'added_by' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Le dernier mot revient a l'index unique, et c'est voulu :
                // `assertFreeOfAnySeries()` interroge une requete qui ne ramene
                // **aucune ligne** dans le cas normal, et `FOR UPDATE` ne pose
                // alors aucun verrou. Deux ajouts simultanes du meme contenu
                // vers deux Series differentes passent donc tous les deux la
                // garde ; c'est la base qui tranche.
                //
                // Sans ce rattrapage, le perdant recevait un 500. Il recoit
                // maintenant le meme message que s'il etait arrive second.
                throw ValidationException::withMessages([
                    $content instanceof BlogPost ? 'blog_post_id' : 'dossier_file_id' => __('dossiers.content_already_in_a_series'),
                ]);
            }
        });
    }

    /**
     * Retirer un item d'une Serie.
     *
     * **Le contenu n'est jamais supprime** : seule son appartenance a la Serie
     * disparait. L'Article reste dans le Dossier, le fichier aussi.
     */
    public function removeItem(ArticleSeries $series, ArticleSeriesItem $item): void
    {
        DB::transaction(function () use ($series, $item) {
            $locked = ArticleSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();

            $row = ArticleSeriesItem::where('article_series_id', $locked->id)
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            $row->delete();

            $this->renumber($locked);
        });
    }

    /**
     * Reordonner les items d'une Serie.
     *
     * @param  array<int, string>  $itemIds  les identifiants d'items, dans l'ordre voulu
     */
    public function reorder(ArticleSeries $series, array $itemIds): void
    {
        DB::transaction(function () use ($series, $itemIds) {
            $locked = ArticleSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();

            $rows = ArticleSeriesItem::where('article_series_id', $locked->id)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Un classement bati sur une liste devenue obsolete est refuse en
            // entier : appliquer la moitie d'un ordre est pire que le refuser.
            // `count($itemIds)` et non `count(array_unique(...))` : une liste
            // qui repete un identifiant est **fausse**, pas seulement
            // redondante. L'ancienne comparaison la laissait passer, et la
            // boucle ecrivait alors deux fois la meme ligne — la seconde
            // ecriture gagnait, et les positions finissaient trouees (0, 2)
            // alors que le service promet des rangs contigus.
            if ($rows->count() !== count($itemIds)
                || $rows->count() !== ArticleSeriesItem::where('article_series_id', $locked->id)->count()) {
                throw ValidationException::withMessages([
                    'items' => __('dossiers.reorder_invalid'),
                ]);
            }

            foreach (array_values($itemIds) as $rang => $id) {
                $rows[$id]->forceFill(['position' => $rang])->save();
            }
        });
    }

    /**
     * Designer une nouvelle racine.
     *
     * L'ancienne racine **redevient la premiere annexe** : rien de ce qu'une
     * personne a range dans la Serie n'en sort tout seul.
     */
    public function setRoot(ArticleSeries $series, BlogPost $post, User $actor): ArticleSeries
    {
        $dossier = $series->dossier;
        $this->assertBelongsToDossier($post, $dossier);

        if ($series->root_blog_post_id === $post->id) {
            return $series;
        }

        return DB::transaction(function () use ($series, $post, $actor) {
            $locked = ArticleSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();
            $ancienneRacine = $locked->root_blog_post_id;

            // Racine ou annexe d'une AUTRE Serie : refuse. Annexe de celle-ci :
            // c'est exactement ce que promouvoir veut dire.
            $this->assertFreeOfAnySeries($post, lock: true, exceptSeriesId: $locked->id);

            ArticleSeriesItem::where('article_series_id', $locked->id)
                ->where('blog_post_id', $post->id)
                ->delete();

            $locked->update(['root_blog_post_id' => $post->id]);

            if ($ancienneRacine && $ancienneRacine !== $post->id) {
                ArticleSeriesItem::where('article_series_id', $locked->id)->increment('position');

                ArticleSeriesItem::create([
                    'organization_id' => $locked->organization_id,
                    'article_series_id' => $locked->id,
                    'blog_post_id' => $ancienneRacine,
                    'position' => 0,
                    'added_by' => $actor->id,
                ]);
            }

            $this->renumber($locked);

            return $locked->fresh();
        });
    }

    /**
     * Dissoudre une Serie.
     *
     * **Aucun contenu n'est supprime** : les Articles et les fichiers restent
     * dans leur Dossier, ils cessent seulement d'etre ordonnes ensemble.
     */
    public function delete(ArticleSeries $series): void
    {
        DB::transaction(function () use ($series) {
            $locked = ArticleSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();

            ArticleSeriesItem::where('article_series_id', $locked->id)->delete();
            $locked->delete();
        });
    }

    // ── Invariants ──────────────────────────────────────────────────────────

    /**
     * Le contenu appartient-il bien a ce Dossier, et a la meme Organization ?
     *
     * Les deux verifications vont ensemble : un identifiant forge peut designer
     * un contenu d'un autre Dossier de la meme Organization comme d'une autre
     * Organization entierement.
     */
    private function assertBelongsToDossier(Model $content, ?Dossier $dossier): void
    {
        if (! $dossier) {
            throw ValidationException::withMessages([
                'content' => __('dossiers.series_dossier_missing'),
            ]);
        }

        if ($content->organization_id !== $dossier->organization_id) {
            throw ValidationException::withMessages([
                'content' => __('dossiers.content_not_in_dossier'),
            ]);
        }

        $dedans = $content instanceof BlogPost
            ? DB::table('dossier_blog_posts')
                ->where('dossier_id', $dossier->id)
                ->where('blog_post_id', $content->id)
                ->exists()
            : DossierFile::whereKey($content->id)
                ->where('dossier_id', $dossier->id)
                ->exists();

        if (! $dedans) {
            throw ValidationException::withMessages([
                'content' => __('dossiers.content_not_in_dossier'),
            ]);
        }
    }

    /**
     * Ce contenu appartient-il deja a une Serie ?
     *
     * **La regle du MVP** : un Article ou un fichier n'appartient qu'a une seule
     * Serie. Elle est verifiee ici explicitement, et non laissee aux index
     * uniques : une violation d'index remonte un message de base de donnees que
     * personne ne comprend, la ou celle-ci dit ce qui se passe.
     */
    private function assertFreeOfAnySeries(Model $content, bool $lock = false, ?string $exceptSeriesId = null): void
    {
        $colonne = $content instanceof BlogPost ? 'blog_post_id' : 'dossier_file_id';

        $items = ArticleSeriesItem::where($colonne, $content->id);

        if ($exceptSeriesId !== null) {
            $items->where('article_series_id', '!=', $exceptSeriesId);
        }

        if ($lock) {
            $items->lockForUpdate();
        }

        if ($items->exists()) {
            throw ValidationException::withMessages([
                $colonne => __('dossiers.content_already_in_a_series'),
            ]);
        }

        if ($content instanceof BlogPost) {
            $racines = ArticleSeries::where('root_blog_post_id', $content->id);

            if ($exceptSeriesId !== null) {
                $racines->where('id', '!=', $exceptSeriesId);
            }

            if ($racines->exists()) {
                throw ValidationException::withMessages([
                    'blog_post_id' => __('dossiers.article_is_series_root'),
                ]);
            }
        }
    }

    /** Des rangs contigus, de 0 a n-1. */
    private function renumber(ArticleSeries $series): void
    {
        ArticleSeriesItem::where('article_series_id', $series->id)
            ->orderBy('position')
            ->get()
            ->each(fn (ArticleSeriesItem $row, int $rang) => $row->forceFill(['position' => $rang])->save());
    }
}
