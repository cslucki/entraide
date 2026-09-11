<?php

namespace App\Services\Knowledge;

use App\Ai\ProviderResolver;
use App\Models\DerivedKnowledgeNote;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Services\Dossiers\ArticleChunker;
use App\Services\Dossiers\DossierChunkEmbeddingService;
use App\Services\Dossiers\DossierSemanticSearchGate;
use App\Support\Ai\AiEconomicGuard;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1534 — rendre une note derivee retrouvable, par le moteur EXISTANT.
 *
 * ## Pourquoi aucun second index
 *
 * `dossier_chunks` est le seul index semantique du depot — mesure : aucune
 * autre table ne porte de colonne vectorielle, il n'y a ni Scout, ni
 * full-text. Une note derivee qui vivrait dans son propre index serait un
 * second moteur de recherche, avec ses propres regles d'eligibilite et sa
 * propre facon de se tromper.
 *
 * Cet indexeur est donc le TROISIEME du meme style que
 * `DossierArticleIndexer` et `DossierFileIndexer` : meme chunker, meme service
 * d'embedding, meme garde economique, meme discipline de staleness — quand on
 * ne peut pas reindexer, on SUPPRIME plutot que de servir des vecteurs
 * perimes comme s'ils etaient a jour.
 *
 * ## Ce qu'il n'a pas, et pourquoi
 *
 * Pas de `content_hash` comme court-circuit d'entree : l'idempotence de cette
 * famille vit en amont, dans `source_fingerprint` de la note. Une note qui
 * arrive ici a deja ete jugee nouvelle ; la re-chunker est la consequence, pas
 * une decision.
 */
final class DerivedKnowledgeNoteIndexer
{
    public function __construct(
        private readonly DossierSemanticSearchGate $gate,
        private readonly ArticleChunker $chunker,
        private readonly DossierChunkEmbeddingService $embeddings,
        private readonly ProviderResolver $providers,
        private readonly AiEconomicGuard $economicGuard,
    ) {}

    /**
     * @return int le nombre de chunks ecrits
     */
    public function synchronize(DerivedKnowledgeNote $note): int
    {
        $organization = Organization::query()->whereKey($note->organization_id)->first();

        if ($organization === null || ! $note->isActive()) {
            $this->forget($note);

            return 0;
        }

        if (! $this->gate->isEnabledFor((string) $organization->id)) {
            // Recherche documentaire coupee pour ce tenant : rien a indexer,
            // et surtout rien a laisser derriere.
            $this->forget($note);

            return 0;
        }

        $chunks = $this->chunker->chunk((string) $note->content);

        if ($chunks === []) {
            $this->forget($note);

            return 0;
        }

        if (! $this->economicGuard->authorizeEmbeddings($organization)->allowed) {
            // Budget atteint : la note reste en base, elle n'est simplement
            // pas retrouvable. Mieux vaut une connaissance invisible qu'une
            // connaissance perimee servie comme fraiche.
            $this->forget($note);

            return 0;
        }

        try {
            $instance = $this->providers->resolveEmbeddingInstance((string) $organization->id);
            $embeddingResult = $this->embeddings->embed(array_column($chunks, 'content'), $instance);
        } catch (\Throwable) {
            $this->forget($note);

            return 0;
        }

        return DB::transaction(function () use ($note, $chunks, $embeddingResult): int {
            $this->forget($note);

            $indexedAt = now();

            foreach ($chunks as $index => $chunk) {
                DossierChunk::create([
                    'organization_id' => $note->organization_id,
                    // Le Dossier de rangement : c'est par lui que le perimetre
                    // documentaire de la recherche atteint la note.
                    'dossier_id' => $note->dossier_id,
                    'blog_post_id' => null,
                    'dossier_file_id' => null,
                    'derived_knowledge_note_id' => $note->id,
                    'chunk_index' => $chunk['chunk_index'],
                    'content' => $chunk['content'],
                    'content_hash' => $chunk['content_hash'],
                    'token_count' => $chunk['token_count'],
                    'embedding' => $embeddingResult['embeddings'][$index],
                    'embedding_provider' => $embeddingResult['provider'],
                    'embedding_model' => $embeddingResult['model'],
                    'indexed_at' => $indexedAt,
                ]);
            }

            return count($chunks);
        });
    }

    /**
     * Retire les vecteurs d'une note — version superseded, budget refuse,
     * recherche coupee, contenu vide.
     *
     * Laisser des chunks derriere soi creerait une seconde verite : la note
     * dirait une chose, l'index en servirait une autre.
     */
    public function forget(DerivedKnowledgeNote $note): void
    {
        DossierChunk::query()
            ->where('organization_id', $note->organization_id)
            ->where('derived_knowledge_note_id', $note->id)
            ->delete();
    }
}
