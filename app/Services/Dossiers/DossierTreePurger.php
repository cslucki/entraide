<?php

namespace App\Services\Dossiers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\DossierMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * TASK-1630 — LE chemin de suppression PHYSIQUE d'une branche de Dossiers.
 *
 * Deux appelants, une seule logique : l'outil SuperAdmin « Nettoyage des
 * Dossiers » et la suppression d'une Boucle qui traine encore une ancienne
 * arborescence. Les laisser diverger, c'est reparer le crash d'un cote et le
 * laisser vivre de l'autre.
 *
 * ## Pourquoi une purge physique, et pas un `delete()` doux
 *
 * Un soft delete laisse la LIGNE en base. Les contraintes PostgreSQL ne
 * connaissent pas `deleted_at` : pour elles, la ligne est toujours la, et
 * `dossiers_holder_xor` continue de la juger. Une racine « supprimee »
 * doucement reste donc un parent parfaitement valide, et le probleme qu'on
 * croyait regle attend simplement le prochain `DELETE` reel.
 *
 * ## Pourquoi feuilles -> racine, et jamais l'inverse
 *
 * `dossiers.parent_id` est en **ON DELETE SET NULL**. Detruire un parent
 * avant ses enfants ne les detruit pas : il les orpheline, `parent_id` passe
 * a NULL, et l'enfant se retrouve avec `parent_id`, `owner_id` et `loop_id`
 * tous les trois vides — la seule combinaison que `dossiers_holder_xor`
 * refuse. C'est exactement le `SQLSTATE[23514]` que rencontrait la
 * suppression d'une Boucle. On descend donc jusqu'aux feuilles, puis on
 * remonte, et un noeud ne part que lorsqu'il n'a plus aucun enfant physique.
 *
 * ## Pourquoi `withTrashed()` partout
 *
 * `Dossier` et `DossierFile` portent SoftDeletes. Un enfant deja soft-delete
 * est invisible a une requete ordinaire — mais sa ligne existe, et le
 * `forceDelete()` de son parent lui mettrait `parent_id` a NULL. L'outil
 * cense reparer le 23514 le rejouerait lui-meme, sur les seules donnees
 * legacy qui l'interessent. Toute lecture de branche ici est donc
 * `withTrashed()`.
 *
 * ## Pourquoi on ne fait pas confiance a la cascade
 *
 * PostgreSQL emporterait bien `dossier_members`, `dossier_chunks` et les
 * autres tout seul. Mais une cascade ne rend aucun compte : la
 * previsualisation annoncerait « 0 fichier » avant d'en detruire quarante.
 * On nettoie donc explicitement, on COMPTE ce qu'on nettoie, et la cascade
 * ne sert plus que de filet.
 *
 * Ce service ne verifie AUCUNE autorisation et ne decide de rien : il
 * execute. Le droit, la protection des racines actives et la confirmation
 * sont la charge de l'appelant.
 */
class DossierTreePurger
{
    /**
     * La branche complete sous un Dossier, feuilles d'abord, racine en
     * dernier — l'ordre dans lequel elle peut etre detruite.
     *
     * Reprend le parcours de `DossierController::brancheDe()` en y ajoutant
     * les lignes soft-deleted, et borne la descente par `Dossier::MAX_DEPTH`
     * pour qu'un cycle en base (impossible par `assertValidParent()`, pas
     * impossible par un UPDATE manuel) ne boucle pas indefiniment.
     *
     * @return Collection<int, Dossier>
     */
    public function branch(Dossier $racine, bool $lock = true): Collection
    {
        $noeuds = collect([$racine]);
        $frontiere = collect([$racine]);
        $profondeur = 0;

        while ($frontiere->isNotEmpty() && $profondeur < Dossier::MAX_DEPTH) {
            $query = Dossier::withTrashed()->whereIn('parent_id', $frontiere->pluck('id'));

            if ($lock) {
                $query->lockForUpdate();
            }

            $frontiere = $query->get();
            $noeuds = $noeuds->concat($frontiere);
            $profondeur++;
        }

        return $noeuds->reverse()->values();
    }

    /**
     * Ce qu'une purge emporterait, sans rien ecrire.
     *
     * Les racines passees sont DEDUPLIQUEES par branche : selectionner un
     * parent et son enfant ne compte la branche qu'une fois, sinon la preview
     * annoncerait deux fois les memes fichiers et l'operateur croirait perdre
     * le double.
     *
     * @param  Collection<int, Dossier>|array<int, Dossier>  $racines
     * @return array<string, mixed>
     */
    public function preview(Collection|array $racines): array
    {
        $noeuds = $this->uniqueNodes($racines);
        $ids = $noeuds->pluck('id')->all();

        if ($ids === []) {
            return [
                'dossiers' => 0, 'descendants' => 0, 'files' => 0, 'articles' => 0,
                'series' => 0, 'members' => 0, 'chunks' => 0, 'organizations' => [],
                'nodes' => collect(),
            ];
        }

        $seriesIds = ArticleSeries::whereIn('dossier_id', $ids)->pluck('id');
        $selectionnes = $this->normalize($racines)->pluck('id')->unique();

        return [
            // Les Dossiers explicitement cochés et encore presents apres
            // deduplication...
            'dossiers' => $noeuds->whereIn('id', $selectionnes->all())->count(),
            // ...et tout ce que la branche emporte en plus.
            'descendants' => $noeuds->count() - $noeuds->whereIn('id', $selectionnes->all())->count(),
            'files' => DossierFile::withTrashed()->whereIn('dossier_id', $ids)->count(),
            // Des LIAISONS, pas des Articles : les Articles survivent.
            'articles' => DossierBlogPost::whereIn('dossier_id', $ids)->count(),
            'series' => $seriesIds->count(),
            'members' => DossierMember::whereIn('dossier_id', $ids)->count(),
            'chunks' => DossierChunk::whereIn('dossier_id', $ids)->count(),
            'organizations' => $noeuds->pluck('organization_id')->unique()->values()->all(),
            'nodes' => $noeuds,
        ];
    }

    /**
     * Detruit physiquement les branches passees, et rend ce qui est parti.
     *
     * @param  Collection<int, Dossier>|array<int, Dossier>  $racines
     * @return array<string, int>
     */
    public function purge(Collection|array $racines): array
    {
        return DB::transaction(function () use ($racines) {
            $noeuds = $this->uniqueNodes($racines, lock: true);

            $compte = ['dossiers' => 0, 'files' => 0, 'articles' => 0, 'series' => 0, 'members' => 0];

            foreach ($noeuds as $noeud) {
                $compte['series'] += $this->purgeSeries($noeud);
                $compte['articles'] += $this->purgeArticleLinks($noeud);
                $compte['members'] += $this->purgeMembers($noeud);
                $this->purgeKnowledge($noeud);
                $compte['files'] += $this->purgeFiles($noeud);

                // Le noeud ne part qu'une fois vide de tout enfant PHYSIQUE.
                // `branch()` garantit l'ordre ; cette verification garantit
                // qu'un enfant apparu entre-temps ne se retrouve pas
                // orpheline — elle coute une requete et evite un 23514.
                if (Dossier::withTrashed()->where('parent_id', $noeud->getKey())->exists()) {
                    continue;
                }

                $noeud->forceDelete();
                $compte['dossiers']++;
            }

            return $compte;
        });
    }

    /**
     * Les noeuds a detruire, dedupliques, feuilles d'abord.
     *
     * Selectionner une racine ET l'un de ses descendants est un geste
     * naturel dans une liste a cases a cocher. Sans deduplication, le
     * descendant serait visite deux fois : la seconde sur un modele dont la
     * ligne n'existe plus.
     *
     * @param  Collection<int, Dossier>|array<int, Dossier>  $racines
     * @return Collection<int, Dossier>
     */
    private function uniqueNodes(Collection|array $racines, bool $lock = false): Collection
    {
        $vus = [];
        $ordonnes = collect();

        foreach ($this->normalize($racines) as $racine) {
            foreach ($this->branch($racine, $lock) as $noeud) {
                if (isset($vus[$noeud->getKey()])) {
                    continue;
                }

                $vus[$noeud->getKey()] = true;
                $ordonnes->push($noeud);
            }
        }

        return $ordonnes;
    }

    /**
     * @param  Collection<int, Dossier>|array<int, Dossier>  $racines
     * @return Collection<int, Dossier>
     */
    private function normalize(Collection|array $racines): Collection
    {
        return $racines instanceof Collection ? $racines->values() : collect($racines)->values();
    }

    private function purgeSeries(Dossier $noeud): int
    {
        $seriesIds = ArticleSeries::where('dossier_id', $noeud->getKey())->pluck('id');

        if ($seriesIds->isEmpty()) {
            return 0;
        }

        ArticleSeriesItem::whereIn('article_series_id', $seriesIds)->delete();
        ArticleSeries::whereIn('id', $seriesIds)->delete();

        return $seriesIds->count();
    }

    /**
     * Les Articles sont DETACHES, jamais detruits — la liaison part, le
     * contenu editorial reste. Meme doctrine que
     * `DossierController::destroy()`.
     */
    private function purgeArticleLinks(Dossier $noeud): int
    {
        $liaisons = DossierBlogPost::where('dossier_id', $noeud->getKey());
        $compte = $liaisons->count();
        $liaisons->delete();

        return $compte;
    }

    private function purgeMembers(Dossier $noeud): int
    {
        $membres = DossierMember::where('dossier_id', $noeud->getKey());
        $compte = $membres->count();
        $membres->delete();

        return $compte;
    }

    private function purgeKnowledge(Dossier $noeud): void
    {
        DossierChunk::where('dossier_id', $noeud->getKey())->delete();
        DerivedKnowledgeNote::where('dossier_id', $noeud->getKey())->delete();
    }

    /**
     * Les fichiers : le BLOB d'abord, la ligne ensuite.
     *
     * `dossier_files.dossier_id` est en SET NULL. Sans ce passage, detruire
     * le Dossier laisserait des lignes de fichiers rattachees a rien, avec
     * leur BLOB sur le disque : invisibles a tout ecran, impossibles a
     * retrouver, et comptees nulle part.
     *
     * L'echec du stockage est tolere — meme raison que dans
     * `DossierFileRemover` : un octet orphelin sur un disque est moins
     * nuisible qu'une purge qui s'arrete a moitie.
     */
    private function purgeFiles(Dossier $noeud): int
    {
        $fichiers = DossierFile::withTrashed()->where('dossier_id', $noeud->getKey())->get();

        foreach ($fichiers as $fichier) {
            try {
                Storage::disk($fichier->disk)->delete($fichier->path);
            } catch (Throwable) {
                // Disque absent ou en erreur : la base se nettoie quand meme.
            }

            // `forceDelete()` ici emporte en cascade `article_series_items`,
            // `dossier_chunks` et `loop_manifesto_sources` de CE fichier. La
            // doctrine de `DossierFileRemover` l'interdit pour une
            // suppression ordinaire, parce qu'elle detruirait en silence des
            // elements qu'aucun ecran n'a montres. Ici c'est l'intention
            // meme : la branche entiere s'en va, et la preview a annonce ce
            // qu'elle emportait.
            $fichier->forceDelete();
        }

        return $fichiers->count();
    }
}
