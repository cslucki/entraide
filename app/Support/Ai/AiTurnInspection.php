<?php

namespace App\Support\Ai;

use App\Listeners\RecordSdkEmbeddingsInvocation;
use App\Models\AiInteraction;
use App\Services\Ai\DTO\KnowledgeAnswer;

/**
 * TASK-1558 — le READ MODEL d'un tour observe.
 *
 * Ce qu'il est : une LECTURE. Il prend ce que le chemin produit a reellement
 * ecrit — le `KnowledgeAnswer` rendu, l'`AiInteraction` tracee — et l'assemble
 * en une forme stable, lisible par un humain comme par un test.
 *
 * Ce qu'il n'est PAS : un second moteur, un second pipeline, une seconde
 * autorite. Il ne cherche rien, ne classe rien, ne decide rien. S'il devait un
 * jour calculer une valeur que le chemin produit ne calcule pas, ce serait le
 * signe qu'on a commence a fabriquer une imitation.
 *
 * ## La regle qui gouverne chaque champ
 *
 * > **NULL reste NULL.**
 *
 * Une valeur que le chemin produit n'expose pas se rend `null`, jamais `0`,
 * jamais `[]`, jamais une estimation. Un zero est une mesure ; une absence de
 * mesure n'en est pas une, et les confondre transformerait cet outil en source
 * de fausses certitudes — exactement ce qu'il existe pour empecher.
 *
 * C'est pourquoi `candidates_found` vaut `null` en v0 : le bassin de candidats
 * vit a l'interieur de `DossierRetrievalSource`, avant `max_distance` et avant
 * `diversify()`, et rien ne l'expose. Le mesurer exigerait soit une requete
 * artificielle — qui n'observerait plus le meme tour — soit une instrumentation
 * du moteur. Les deux sont hors de ce v0, et le dire est plus utile que de
 * rendre un chiffre qui ressemblerait a une mesure.
 *
 * ## G/H
 *
 * Cette trace est ADMIN. Elle distingue les causes (c'est son role), et elle ne
 * rend que ce que les autorites existantes ont deja mis dans la provenance :
 * une source qui n'a pas franchi l'ACL n'a jamais produit de ligne ici, donc ni
 * titre, ni auteur, ni contenu ne peuvent en sortir.
 */
final class AiTurnInspection
{
    /** Nombre de caracteres d'extrait rendus par chunk. Borne, jamais le texte entier. */
    public const PREVIEW_CHARS = 240;

    /**
     * @param  array<string, mixed>  $identity  organization / user / surface / loop
     * @param  array<string, mixed>  $scope  dossiers autorises, fichiers eligibles
     */
    public static function build(
        array $identity,
        array $scope,
        string $question,
        KnowledgeAnswer $answer,
        ?AiInteraction $interaction,
    ): array {
        $metadata = is_array($interaction?->metadata) ? $interaction->metadata : [];

        return [
            'run' => self::run($metadata, $interaction),
            'identity' => $identity,
            'scope' => $scope,
            'retrieval' => self::retrieval($question, $answer),
            'selection' => self::selection($answer),
            'llm_input' => self::llmInput($answer),
            'output' => self::output($answer, $metadata),
            'provider' => self::provider($metadata, $interaction),
        ];
    }

    /** @param  array<string, mixed>  $metadata */
    private static function run(array $metadata, ?AiInteraction $interaction): array
    {
        return [
            'turn_id' => self::stringOrNull($metadata['turn_id'] ?? null),
            'correlation_id' => self::stringOrNull($interaction?->correlation_id),
            'ai_interaction_id' => self::stringOrNull($interaction?->id),
            'capability' => self::stringOrNull($metadata['capability'] ?? null),
            'process' => self::stringOrNull($interaction?->process),
            'status' => self::stringOrNull($metadata['status'] ?? null),
        ];
    }

    /**
     * Ce que le retrieval a REELLEMENT rendu, ligne par ligne.
     *
     * `query` est la question telle qu'elle est partie : le depot ne prefixe ni
     * ne reecrit la requete documentaire (mesure T1519 — prefixer la question
     * precedente DEGRADE le classement dans 3 cas sur 5). La rendre telle
     * quelle est donc exact, et non une approximation.
     */
    private static function retrieval(string $question, KnowledgeAnswer $answer): array
    {
        $lignes = [];

        foreach (array_values($answer->consulted) as $rang => $entree) {
            $lignes[] = [
                'rank' => $rang + 1,
                'ref' => self::stringOrNull($entree['ref'] ?? null),
                'source' => self::stringOrNull($entree['source'] ?? null),
                'chunk_id' => self::stringOrNull($entree['chunk_id'] ?? null),
                'chunk_index' => isset($entree['chunk_index']) ? (int) $entree['chunk_index'] : null,
                'dossier_id' => self::stringOrNull($entree['dossier_id'] ?? null),
                'dossier_name' => self::stringOrNull($entree['dossier_name'] ?? null),
                'file_id' => self::stringOrNull($entree['dossier_file_id'] ?? null),
                'title' => self::stringOrNull($entree['title'] ?? null),
                'source_type' => self::stringOrNull($entree['source_type'] ?? null),
                // `distance` n'existe que sur le retrieval semantique. Le
                // manifest et l'historique n'en ont pas, et n'en auront jamais :
                // ils ne classent rien. `null` est ici la reponse juste.
                'distance' => isset($entree['distance']) && $entree['distance'] !== null
                    ? round((float) $entree['distance'], 4)
                    : null,
                // TASK-1309 : COMMENT l'extrait a ete choisi — proximite
                // semantique, ou ouverture representative de son document.
                'selection' => self::stringOrNull($entree['selection'] ?? null),
            ];
        }

        return [
            'query' => $question,
            // Voir le bloc de tete : non observable sans requete artificielle
            // ni instrumentation du moteur. `null`, jamais 0.
            'candidates_found' => null,
            'consulted_count' => count($answer->consulted),
            'entries' => $lignes,
        ];
    }

    /**
     * Ce qui a ete RETENU, et ce qui ne l'a pas ete.
     *
     * « Rejete » se lit ici comme « consulte mais non cite » : le modele a vu
     * l'extrait et n'a pas construit sa reponse dessus. Ce n'est pas un rejet du
     * retrieval — celui-la se passe plus haut et n'est pas observable en v0 — et
     * la nuance est ecrite parce que confondre les deux ferait accuser la
     * mauvaise couche.
     */
    private static function selection(KnowledgeAnswer $answer): array
    {
        $citees = array_values(array_filter(array_map(
            static fn (array $e): ?string => self::stringOrNull($e['chunk_id'] ?? null),
            $answer->sources,
        )));

        $consultees = array_values(array_filter(array_map(
            static fn (array $e): ?string => self::stringOrNull($e['chunk_id'] ?? null),
            $answer->consulted,
        )));

        return [
            'consulted_chunk_ids' => $consultees,
            'cited_chunk_ids' => $citees,
            'consulted_not_cited' => array_values(array_diff($consultees, $citees)),
            'final_order' => array_values(array_filter(array_map(
                static fn (array $e): ?string => self::stringOrNull($e['ref'] ?? null),
                $answer->sources,
            ))),
        ];
    }

    /**
     * Ce qui est reellement parti chez le modele.
     *
     * L'extrait est BORNE et le prompt complet n'est jamais rendu : ni chaine de
     * pensee, ni embedding brut, ni secret. Ce que cette section repond est la
     * seule question qui compte pour diagnostiquer — « le bon passage
     * a-t-il ete envoye, oui ou non ? ».
     */
    private static function llmInput(KnowledgeAnswer $answer): array
    {
        $chunks = [];

        foreach (array_values($answer->consulted) as $rang => $entree) {
            $chunks[] = [
                'rank' => $rang + 1,
                'ref' => self::stringOrNull($entree['ref'] ?? null),
                'chunk_id' => self::stringOrNull($entree['chunk_id'] ?? null),
                'title' => self::stringOrNull($entree['title'] ?? null),
                'preview' => self::preview($entree['extrait'] ?? null),
            ];
        }

        return [
            'chunks_sent' => count($chunks),
            'chunks' => $chunks,
        ];
    }

    /** @param  array<string, mixed>  $metadata */
    private static function output(KnowledgeAnswer $answer, array $metadata): array
    {
        // TASK-1557 : la frontiere, lue depuis la trace de l'interaction. Le
        // chemin Loop n'ecrit pas `grounded` dans cette metadata — on le porte
        // donc depuis le DTO, qui est l'autorite du tour.
        $etat = AiTurnState::fromTurnMetadata(
            ['status' => $metadata['status'] ?? null, 'grounded' => $answer->grounded],
            $metadata,
        );

        return [
            'answer' => $answer->answer,
            'grounded' => $answer->grounded,
            'citations' => array_map(KnowledgeAnswer::publicSource(...), $answer->sources),
            'shell_turn_status' => $etat->turnStatus,
            'verification_status' => $etat->verificationStatus,
            'degraded_reason' => $etat->degradedReason,
            'rule' => $etat->rule(),
            'follow_up_questions' => $answer->followUps,
        ];
    }

    /** @param  array<string, mixed>  $metadata */
    private static function provider(array $metadata, ?AiInteraction $interaction): array
    {
        $embeddings = $metadata[RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY] ?? null;

        return [
            'provider' => self::stringOrNull($metadata['provider'] ?? null),
            'model' => self::stringOrNull($interaction?->model),
            'generation_sdk_invocation_id' => self::stringOrNull($metadata['sdk_invocation_id'] ?? null),
            // `[]` est ici une MESURE (T1556 : « reclamees une seule fois,
            // `[]` mesure, jamais null ») ; `null` signifie que la cle n'existe
            // pas sur cette trace. Les deux se distinguent.
            'embedding_sdk_invocation_ids' => is_array($embeddings) ? array_values($embeddings) : null,
            'latency_ms' => isset($metadata['latency_ms']) ? (int) $metadata['latency_ms'] : null,
            'cost_usd' => $interaction?->cost_usd !== null ? (float) $interaction->cost_usd : null,
            'input_tokens' => self::intOrNull($interaction?->input_tokens),
            'output_tokens' => self::intOrNull($interaction?->output_tokens),
            // T1554 : ecrites par `DossierInsightsService`, PAS par le chemin
            // Loop (dette nommee). `null` dit « cette trace ne les porte pas »,
            // et surtout pas « aucune source refusee ».
            'sources_used' => isset($metadata['sources_used']) && is_array($metadata['sources_used'])
                ? array_values($metadata['sources_used'])
                : null,
            'sources_denied' => isset($metadata['sources_denied']) && is_array($metadata['sources_denied'])
                ? $metadata['sources_denied']
                : null,
        ];
    }

    private static function preview(mixed $texte): ?string
    {
        if (! is_string($texte) || trim($texte) === '') {
            return null;
        }

        return mb_strimwidth(trim($texte), 0, self::PREVIEW_CHARS, '…');
    }

    private static function stringOrNull(mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        $texte = (string) $valeur;

        return $texte === '' ? null : $texte;
    }

    private static function intOrNull(mixed $valeur): ?int
    {
        return $valeur === null ? null : (int) $valeur;
    }
}
