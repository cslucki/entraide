<?php

namespace App\Services\Dossiers;

use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * TASK-1534 — l'UNIQUE autorite d'eligibilite des chunks.
 *
 * ## Pourquoi une classe, et pas deux clauses jumelles
 *
 * Deux chemins interrogent `dossier_chunks` : `searchAcrossDossiers()` et
 * `representativeChunksAcrossDossiers()`. Ils portaient jusqu'ici la MEME
 * clause d'eligibilite, ecrite DEUX fois. `DossierSemanticSearchService` le
 * dit lui-meme la ou il justifie de garder la seconde primitive chez lui :
 * eviter « une seconde regle d'eligibilite documentaire ».
 *
 * Tant que les deux familles etaient article et fichier, la duplication
 * coutait peu. Avec une troisieme famille **dont depend une regle de
 * securite**, elle devient le defaut le plus dangereux du fichier : une
 * garde ajoutee a un seul des deux chemins laisse l'autre grand ouvert, et
 * rien ne le signale. La clause vit donc ici, une seule fois, et les deux
 * chemins l'appellent.
 *
 * ## La regle de securite, et pourquoi le Dossier ne suffit pas
 *
 * Une note derivee d'une Boucle est rangee dans un Dossier. Si l'on s'en
 * remettait au seul perimetre documentaire, il suffirait qu'elle soit rangee
 * dans un Dossier plus ouvert que sa Boucle — par configuration, par
 * deplacement, par erreur — pour que ce qui s'est dit dans une Boucle privee
 * devienne lisible par toute l'Organization.
 *
 * La regle est donc une INTERSECTION, et les deux moities sont verifiees :
 *
 *     visibilite(note) ⊆ visibilite(Boucle source) ∩ visibilite(Dossier)
 *
 * Le perimetre documentaire est deja borne par l'appelant
 * (`DossierAccessScope` -> `DossierPolicy::view`). Ce qui manque, et que
 * cette classe ajoute, c'est la moitie Boucle — evaluee a la LECTURE, jamais
 * copiee dans une colonne : un membre qui quitte une Boucle perd l'acces au
 * tour suivant, sans aucune synchronisation a rater.
 *
 * ## Ferme par defaut
 *
 * `$authorizedLoopIds === null` signifie « l'appelant ne sait pas au nom de
 * qui il cherche ». Dans ce cas les chunks derives sont **exclus**, jamais
 * admis. Un appelant qui oublie de transmettre l'autorisation obtient donc
 * moins de resultats, pas une fuite. C'est le seul defaut acceptable pour
 * une garde de confidentialite.
 */
final class DerivedChunkEligibility
{
    /**
     * Les Boucles de cette Organization dont CET utilisateur peut lire
     * l'espace de travail.
     *
     * Meme definition que `LoopPolicy::viewWorkspace` — adhesion ACTIVE,
     * meme tenant, compte actif — exprimee en une requete plutot qu'en N
     * evaluations de policy, parce qu'elle borne une clause SQL.
     *
     * @return list<string>
     */
    public function authorizedLoopIds(string $organizationId, ?User $user): array
    {
        if ($user === null || $user->isDeactivated() || (string) $user->organization_id !== $organizationId) {
            return [];
        }

        return LoopMember::query()
            ->join('loops', 'loops.id', '=', 'loop_members.loop_id')
            ->where('loop_members.user_id', $user->id)
            ->where('loop_members.status', 'active')
            ->where('loops.organization_id', $organizationId)
            ->pluck('loop_members.loop_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * La clause d'eligibilite, pour les TROIS familles de chunk.
     *
     * @param  list<string>|null  $authorizedLoopIds  null = l'appelant ne sait pas
     *                                                au nom de qui il cherche :
     *                                                aucune note derivee n'est
     *                                                eligible.
     */
    public function applyTo(Builder $query, string $organizationId, ?array $authorizedLoopIds): void
    {
        $query->where(function (Builder $outer) use ($organizationId, $authorizedLoopIds): void {
            $outer->where(function (Builder $article) use ($organizationId): void {
                $article->whereNotNull('dossier_chunks.blog_post_id')
                    ->whereNotNull('dossier_blog_posts.id')
                    ->where('blog_posts.organization_id', $organizationId)
                    ->where('blog_posts.status', 'published')
                    ->whereNotNull('blog_posts.published_at')
                    ->where('blog_posts.published_at', '<=', now())
                    ->whereNull('blog_posts.deleted_at');
            })->orWhere(function (Builder $file) use ($organizationId): void {
                $file->whereNotNull('dossier_chunks.dossier_file_id')
                    ->where('dossier_files.organization_id', $organizationId)
                    ->whereNull('dossier_files.deleted_at');
            });

            // Ferme par defaut : sans autorisation explicite, la troisieme
            // famille n'est tout simplement pas proposee.
            if ($authorizedLoopIds === null || $authorizedLoopIds === []) {
                return;
            }

            $outer->orWhere(function (Builder $derived) use ($organizationId, $authorizedLoopIds): void {
                $derived->whereNotNull('dossier_chunks.derived_knowledge_note_id')
                    ->where('derived_knowledge_notes.organization_id', $organizationId)
                    ->where('derived_knowledge_notes.status', DerivedKnowledgeNote::STATUS_ACTIVE)
                    // LA moitie Boucle de l'intersection. Sans elle, une note
                    // rangee dans un Dossier org-visible sortirait d'une
                    // Boucle privee.
                    ->whereNotNull('derived_knowledge_notes.source_loop_id')
                    ->whereIn('derived_knowledge_notes.source_loop_id', $authorizedLoopIds);
            });
        });
    }

    /**
     * La jointure que la clause exige. Separee de `applyTo()` uniquement
     * parce qu'un `join` ne peut pas vivre dans un groupe `where`.
     */
    public function joinTo(Builder $query): void
    {
        $query->leftJoin('derived_knowledge_notes', function ($join): void {
            $join->on('derived_knowledge_notes.id', '=', 'dossier_chunks.derived_knowledge_note_id')
                // Meme garde de staleness que les deux autres familles : une
                // note deplacee ne se lit pas depuis son ancien Dossier.
                ->on('derived_knowledge_notes.dossier_id', '=', 'dossier_chunks.dossier_id');
        })
            // Le nom de la Boucle source, parce qu'une source doit savoir se
            // NOMMER. Un Article a son titre, un fichier son nom ; une note
            // derivee n'a que la conversation dont elle vient. Sans cette
            // jointure, la reponse citerait « [S1] — Dossier X » sans dire
            // d'ou vient l'extrait.
            ->leftJoin('loops', 'loops.id', '=', 'derived_knowledge_notes.source_loop_id');
    }

    /**
     * L'identite « un document » pour la vue d'ensemble representative.
     *
     * `representativeChunksAcrossDossiers()` ne garde qu'un chunk par
     * document, celui d'index minimal. Le fragment ci-dessous etend cette
     * notion de document a la troisieme famille — et il vit ici pour la meme
     * raison que le reste : deux definitions de « un document » qui divergent
     * rendraient une note visible dans un chemin et pas dans l'autre.
     */
    public function representativeIdentitySql(): string
    {
        return ' or (inner_chunks.derived_knowledge_note_id is not null'
            .' and inner_chunks.derived_knowledge_note_id = dossier_chunks.derived_knowledge_note_id)';
    }

    /**
     * Les colonnes que les deux chemins doivent selectionner pour qu'une
     * ligne derivee sache se nommer.
     *
     * @return list<string>
     */
    public function selectColumns(): array
    {
        return [
            'dossier_chunks.derived_knowledge_note_id',
            'derived_knowledge_notes.subject_key as derived_subject_key',
            'derived_knowledge_notes.source_loop_id as derived_source_loop_id',
            'derived_knowledge_notes.observed_at as derived_observed_at',
            'loops.name as derived_loop_name',
        ];
    }

    /**
     * Le Dossier ou ranger une note derivee d'une Boucle : le Dossier racine
     * de cette Boucle.
     *
     * C'est le seul rangement qui ne DEMANDE rien de neuf au produit — un
     * Dossier racine n'a deja pas d'audience propre, il porte celle de sa
     * Boucle (`DossierPolicy::view`). La garde Boucle de cette classe reste
     * neanmoins appliquee : elle protege des cas ou ce rangement changerait.
     */
    public function rootDossierIdFor(Loop $loop): ?string
    {
        // Une Boucle n'a qu'un Dossier racine : `LoopRootDocumentService` le
        // cree sous verrou et la base porte la contrainte `dossiers_holder_xor`.
        $dossier = Dossier::query()
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first();

        return $dossier === null ? null : (string) $dossier->id;
    }
}
