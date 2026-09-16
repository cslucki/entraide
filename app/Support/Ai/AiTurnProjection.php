<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\DossierChunk;
use App\Models\LoopMessage;

/**
 * TASK-1580 — la PROJECTION d'un tour persiste, pour l'Inspector graphique.
 *
 * `AiTurnInspection` (V0-H) lit UNE ligne et reste strictement query-free :
 * c'est ce qui garantit qu'elle ne fabrique rien. Mais un Inspector qui
 * affiche un tour a besoin de trois choses que cette ligne ne porte pas :
 * la QUESTION posee (elle vit sur la bulle `loop_messages`, jamais sur
 * `ai_interactions` — le prompt brut n'est pas une question), les sources
 * telles que le membre les a VUES (refs, titres, extraits publics de la
 * bulle), et la NATURE de chaque chunk consulte (document, article,
 * connaissance derivee — `dossier_chunks`).
 *
 * Ce sibling est le SEUL autorise a requeter, et il requete peu :
 *   1. la bulle IA du tour (`loop_messages.metadata.ai_interaction_id`) — 1 requete ;
 *   2. les chunks consultes/cites — 1 requete batch, jamais une par chunk.
 * Toutes deux sous l'`organization_id` de l'interaction DEJA resolue : la
 * projection ne revele l'existence de rien hors du tenant.
 *
 * ## Regles de verite
 *
 * Ce qui vient de la bulle est MEASURED (le produit l'a ecrit au moment du
 * tour) ; `source_type` est DERIVED des colonnes du chunk (meme regle que le
 * moteur, `DossierSemanticSearchService`) ; `consulted_not_cited` est DERIVED
 * de `retrieval.{consulted,cited}` ; tout ce qui manque — bulle absente
 * (`publish: false`, arret anticipe, tour Shell ou page Dossier), chunk
 * supprime ou reindexe sous un autre id — est UNAVAILABLE avec sa raison.
 * `ai_interactions.prompt` n'est JAMAIS lu ni parse : il n'est ni une
 * question ni une source.
 */
final class AiTurnProjection
{
    public const SOURCE_TYPE_DERIVED_KNOWLEDGE = 'derived_knowledge';

    public const SOURCE_TYPE_ARTICLE = 'article';

    public const SOURCE_TYPE_FILE = 'file';

    /**
     * @return array<string, mixed>
     */
    public static function project(AiInteraction $interaction): array
    {
        $organizationId = (string) $interaction->organization_id;
        $metadata = is_array($interaction->metadata) ? $interaction->metadata : [];
        $raisons = [];
        $truth = [];

        // 1. La bulle IA de ce tour — UNE requete, tenant-bound.
        $bulle = LoopMessage::query()
            ->where('organization_id', $organizationId)
            ->where('type', 'ai')
            ->where('metadata->ai_interaction_id', (string) $interaction->id)
            ->orderBy('created_at')
            ->first();

        $bulleMetadata = is_array($bulle?->metadata) ? $bulle->metadata : [];

        if ($bulle === null) {
            $raisons['question'] = 'no_loop_bubble';
            $raisons['sources'] = 'no_loop_bubble';
            $question = null;
            $sources = null;
            $consultedPublic = null;
        } else {
            $question = is_string($bulleMetadata['question'] ?? null) && $bulleMetadata['question'] !== '' ? $bulleMetadata['question'] : null;
            if ($question === null) {
                $raisons['question'] = 'bubble_without_question';
            }
            $sources = is_array($bulleMetadata['sources'] ?? null) ? array_values($bulleMetadata['sources']) : null;
            if ($sources === null) {
                $raisons['sources'] = 'bubble_without_sources';
            }
            $consultedPublic = is_array($bulleMetadata['consulted'] ?? null) ? array_values($bulleMetadata['consulted']) : null;
        }

        $truth['question'] = $question === null ? AiTruthLabel::UNAVAILABLE : AiTruthLabel::MEASURED;
        $truth['sources'] = $sources === null ? AiTruthLabel::UNAVAILABLE : AiTruthLabel::MEASURED;

        // 2. Les chunks : ids MESURES par le writer (`retrieval.consulted/cited`).
        $retrieval = is_array($metadata['retrieval'] ?? null) ? $metadata['retrieval'] : null;
        $consulted = self::chunkIds($retrieval['consulted'] ?? null);
        $cited = self::chunkIds($retrieval['cited'] ?? null);

        if ($consulted === null || $cited === null) {
            $raisons['consulted_not_cited'] = 'retrieval_ids_unavailable';
            $consultedNotCited = null;
        } else {
            $consultedNotCited = array_values(array_diff($consulted, $cited));
        }
        $truth['consulted_not_cited'] = $consultedNotCited === null ? AiTruthLabel::UNAVAILABLE : AiTruthLabel::DERIVED;

        $tousLesIds = array_values(array_unique([...($consulted ?? []), ...($cited ?? [])]));
        $chunks = self::chunks($tousLesIds, $organizationId, $cited ?? []);

        $truth['chunks'] = $tousLesIds === [] ? AiTruthLabel::UNAVAILABLE : AiTruthLabel::MEASURED;
        if ($tousLesIds === []) {
            $raisons['chunks'] = $retrieval === null ? 'retrieval_ids_unavailable' : 'no_chunk_consulted';
        }
        foreach ($chunks as $chunk) {
            $truth['chunks.'.$chunk['chunk_id'].'.source_type'] = $chunk['source_type'] === null ? AiTruthLabel::UNAVAILABLE : AiTruthLabel::DERIVED;
        }

        return [
            'ai_interaction_id' => (string) $interaction->id,
            'loop_message_id' => $bulle !== null ? (string) $bulle->id : null,
            'question' => $question,
            'sources' => $sources,
            'consulted_public' => $consultedPublic,
            'chunks' => $chunks,
            'consulted_not_cited' => $consultedNotCited,
            'unavailable_reasons' => $raisons,
            'truth' => $truth,
        ];
    }

    /**
     * Les ids de chunks d'une liste `retrieval.*` — `null` si la cle manque
     * (tour sans retrieval ou anterieur), `[]` si elle est la et vide (mesure).
     *
     * @return list<string>|null
     */
    private static function chunkIds(mixed $liste): ?array
    {
        if (! is_array($liste)) {
            return null;
        }

        $ids = [];
        foreach ($liste as $entree) {
            $id = is_array($entree) ? ($entree['chunk_id'] ?? null) : $entree;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * UNE requete batch, tenant-bound. Un id absent du resultat = chunk
     * supprime ou reindexe sous un autre id : `source_type` UNAVAILABLE, les
     * autres chunks ne sont pas affectes.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $cited
     * @return list<array<string, mixed>>
     */
    private static function chunks(array $ids, string $organizationId, array $cited): array
    {
        if ($ids === []) {
            return [];
        }

        $lignes = DossierChunk::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->get(['id', 'dossier_id', 'dossier_file_id', 'blog_post_id', 'derived_knowledge_note_id', 'chunk_index'])
            ->keyBy(static fn (DossierChunk $c): string => (string) $c->id);

        $resultat = [];
        foreach ($ids as $id) {
            $chunk = $lignes->get($id);
            $resultat[] = [
                'chunk_id' => $id,
                'cited' => in_array($id, $cited, true),
                'present' => $chunk !== null,
                'source_type' => $chunk === null ? null : self::sourceType($chunk),
                'dossier_id' => $chunk?->dossier_id !== null ? (string) $chunk->dossier_id : null,
                'dossier_file_id' => $chunk?->dossier_file_id !== null ? (string) $chunk->dossier_file_id : null,
                'blog_post_id' => $chunk?->blog_post_id !== null ? (string) $chunk->blog_post_id : null,
                'derived_knowledge_note_id' => $chunk?->derived_knowledge_note_id !== null ? (string) $chunk->derived_knowledge_note_id : null,
                'chunk_index' => $chunk?->chunk_index,
                'unavailable_reason' => $chunk === null ? 'chunk_missing_or_reindexed' : null,
            ];
        }

        return $resultat;
    }

    /**
     * La MEME regle que le moteur (`DossierSemanticSearchService`) : une note
     * derivee prime, puis un article (`blog_post_id`), sinon un fichier.
     */
    private static function sourceType(DossierChunk $chunk): string
    {
        return match (true) {
            $chunk->derived_knowledge_note_id !== null => self::SOURCE_TYPE_DERIVED_KNOWLEDGE,
            $chunk->blog_post_id !== null => self::SOURCE_TYPE_ARTICLE,
            default => self::SOURCE_TYPE_FILE,
        };
    }
}
