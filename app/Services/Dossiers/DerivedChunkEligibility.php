<?php

namespace App\Services\Dossiers;

use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
                $this->applyDerivedFamily($derived, $organizationId, $authorizedLoopIds);
            });
        });
    }

    /**
     * LA clause de la troisieme famille — extraite (TASK-1549) pour qu'elle
     * serve AUSSI la resolution unitaire {@see self::derivedClaimForViewer()}.
     *
     * Elle n'est pas recopiee : c'est la meme methode que `applyTo()` appelle.
     * Deux ecritures de cette clause seraient exactement le defaut que cette
     * classe existe pour empecher.
     *
     * @param  list<string>  $authorizedLoopIds
     */
    private function applyDerivedFamily(Builder $query, string $organizationId, array $authorizedLoopIds): void
    {
        $query->whereNotNull('dossier_chunks.derived_knowledge_note_id')
            ->where('derived_knowledge_notes.organization_id', $organizationId)
            ->where('derived_knowledge_notes.status', DerivedKnowledgeNote::STATUS_ACTIVE)
            // LA moitie Boucle de l'intersection. Sans elle, une note
            // rangee dans un Dossier org-visible sortirait d'une
            // Boucle privee.
            ->whereNotNull('derived_knowledge_notes.source_loop_id')
            ->whereIn('derived_knowledge_notes.source_loop_id', $authorizedLoopIds);
    }

    /**
     * TASK-1549 (remediation autorite) — resoudre UN chunk cite en l'enonce
     * qu'il porte, pour CE spectateur, sous la clause de cette classe.
     *
     * ## Pourquoi cette primitive existe
     *
     * Le panneau « Pourquoi ? » doit nommer la memoire durable qu'une reponse
     * a citee. Il lisait pour cela `dossier_chunks` en direct, depuis un
     * service de lecture : la garde d'ACL redevenait alors une CONVENTION
     * D'ORDRE D'APPEL — resoudre la note, puis penser a verifier le droit.
     * Une convention ne protege rien : il suffit d'un appelant qui oublie la
     * seconde moitie.
     *
     * L'eligibilite est donc decidee ICI, par la clause, et l'appelant ne peut
     * plus obtenir une note qu'il n'a pas le droit de lire — meme en appelant
     * la primitive isolement.
     *
     * ## Les trois verdicts, et pourquoi `denied` n'est pas `none`
     *
     *  - `granted` : le chunk porte un enonce ACTIF, et la clause l'admet pour
     *    ce spectateur. La note est rendue.
     *  - `denied` : le chunk porte bien un enonce, mais la clause le refuse —
     *    Boucle source non autorisee. La note n'est JAMAIS rendue. Le verdict
     *    seul sort, parce que la surface doit pouvoir dire « une information
     *    de memoire citee n'est plus accessible » sans en divulguer ni
     *    l'auteur, ni le titre, ni le contenu (decision T1549).
     *  - `none` : rien ne prouve que ce chunk soit une memoire durable
     *    adressable — document ordinaire, ligne disparue, note deplacee
     *    (garde de staleness de {@see self::joinTo()}), ou digest non
     *    adressable par sujet.
     *
     * Ferme par defaut : tout ce qui n'est pas explicitement admis sort en
     * `none` ou `denied`, jamais avec une note.
     *
     * @return array{state: 'none'|'denied'|'granted', note: ?DerivedKnowledgeNote}
     */
    public function derivedClaimForViewer(string $organizationId, ?User $viewer, string $chunkId): array
    {
        $none = ['state' => 'none', 'note' => null];

        if ($organizationId === '' || $chunkId === '') {
            return $none;
        }

        // 1. Le chunk porte-t-il, DANS CE TENANT, une note derivee encore
        //    rattachee a son Dossier ? La jointure de staleness fait foi.
        $ligne = $this->chunkQuery($organizationId, $chunkId);
        $this->joinTo($ligne);

        $noteId = $ligne->value('derived_knowledge_notes.id');

        if ($noteId === null) {
            return $none;
        }

        // 2. Un enonce adressable par sujet, ou un digest ? On ne propose pas
        //    de verdict sur ce qu'aucun `subject_key` ne designe.
        $note = DerivedKnowledgeNote::query()
            ->where('organization_id', $organizationId)
            ->find($noteId);

        if ($note === null || ! $note->isClaim()) {
            return $none;
        }

        // 3. LA decision, prise par la clause elle-meme — jamais par une
        //    comparaison refaite ici.
        $eligible = $this->chunkQuery($organizationId, $chunkId);
        $this->joinTo($eligible);
        $this->applyDerivedFamily(
            $eligible,
            $organizationId,
            $this->authorizedLoopIds($organizationId, $viewer),
        );

        return $eligible->exists()
            ? ['state' => 'granted', 'note' => $note]
            : ['state' => 'denied', 'note' => null];
    }

    /**
     * Une ligne `dossier_chunks` designee, bornee au tenant. Point d'entree
     * unique des deux passes de {@see self::derivedClaimForViewer()}.
     */
    private function chunkQuery(string $organizationId, string $chunkId): Builder
    {
        return DB::table('dossier_chunks')
            ->where('dossier_chunks.organization_id', $organizationId)
            ->where('dossier_chunks.id', $chunkId);
    }

    /**
     * La ligne `dossier_chunks` citee existe-t-elle encore, dans ce tenant ?
     *
     * N'AFFIRME RIEN SUR L'ORIGINE, et c'est tout l'interet : quand la ligne a
     * disparu, sa reference de note est partie avec elle — memoire durable et
     * document ordinaire deviennent indiscernables. Cette sonde sert le
     * TROISIEME etat du panneau (« une source citee n'est plus accessible »),
     * qui se dit sans nommer de famille. Elle vit ICI parce qu'elle interroge
     * la table que cette classe gouverne.
     */
    public function citedChunkStillExists(string $organizationId, string $chunkId): bool
    {
        if ($organizationId === '' || $chunkId === '') {
            return false;
        }

        return $this->chunkQuery($organizationId, $chunkId)->exists();
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
