<?php

namespace App\Support\Ai;

use App\Ai\Context\DossierRetrievalTraceRecorder;
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
 * C'est pourquoi `candidates_found` valait `null` en v0 : le bassin de candidats
 * vit a l'interieur de `DossierRetrievalSource`, avant `max_distance` et avant
 * `diversify()`, et rien ne l'exposait. Le mesurer aurait exige soit une requete
 * artificielle — qui n'observerait plus le meme tour — soit une instrumentation
 * du moteur.
 *
 * TASK-1565 a choisi la seconde, dans le seul sens acceptable : **le pipeline
 * reel produit sa trace, l'inspecteur la lit.** `candidates_found` rend
 * desormais le bassin DENSE reellement interroge — lu dans
 * `metadata['retrieval_trace']`, ecrit par la source elle-meme. La regle ne
 * bouge pas d'un iota : sans trace sur l'interaction, la valeur reste `null`,
 * et jamais `0`.
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
            'retrieval' => self::retrieval($question, $answer, $metadata),
            // TASK-1565 — les etages que le retrieval a REELLEMENT traverses.
            // `null` quand cette interaction ne porte pas la trace.
            'retrieval_trace' => self::retrievalTrace($metadata),
            // TASK-1567 / CDC-01 V0-L — ce que le tour a VU de la conversation.
            // `null` quand le chemin ne l'a pas ecrit : UNAVAILABLE, jamais une
            // reconstruction.
            'history' => self::history($metadata),
            'selection' => self::selection($answer),
            'llm_input' => self::llmInput($answer),
            'output' => self::output($answer, $metadata),
            'provider' => self::provider($metadata, $interaction),
        ];
    }

    /**
     * TASK-1569 / CDC-01 V0-H0 — lire un tour DEJA persiste, sans le rejouer.
     *
     * Mode EXPLAIN (CDC-01 §9.1). Aucun `KnowledgeAnswer` vivant : tout vient
     * de la ligne `ai_interactions` et de sa metadata, telle que le moteur l'a
     * ecrite pendant le tour. Rien n'est recalcule, rien n'est infere depuis
     * `execution_path` ou depuis un autre champ ; un champ que le tour n'a pas
     * ecrit est `null` — `UNAVAILABLE` chez le lecteur —, jamais une valeur
     * voisine, jamais un `0`, jamais un `[]`.
     *
     * ## Les sections, et pourquoi elles sont fixes
     *
     * Le JSON est un CONTRAT machine (tests, diagnostic par agent) : les memes
     * cles quel que soit l'age du tour. Un tour anterieur a V0-A rend un bloc
     * `turn` a `null` et des sections vides de valeurs, pas des sections
     * manquantes.
     *
     * ## C19 — l'identite canonique
     *
     * `turn.id` (bloc V0-A) est l'identite du tour. `metadata.turn_id`, au
     * premier niveau, est la cle HISTORIQUE que seul le chemin RAG ecrivait
     * avant V0-A : elle ne sert de repli QUE pour ces anciens tours, et la
     * source retenue est rendue (`turn_id_source`) pour qu'un lecteur ne prenne
     * jamais un repli pour une mesure canonique.
     *
     * ## Ce que ce mode ne rend PAS
     *
     * Les sections qui exigent la reponse VIVANTE (`retrieval.entries`,
     * `selection`, `llm_input`) : elles ne sont pas persistees, et les
     * reconstruire depuis `retrieval.consulted` ou `sources_used` decrirait une
     * autre chose que ce que le modele a recu. Elles sont `null`, avec ce
     * docblock pour seule explication — pas un `[]` qui se lirait « rien
     * envoye ».
     *
     * @return array<string, mixed>
     */
    public static function fromPersistedTurn(AiInteraction $interaction): array
    {
        $metadata = is_array($interaction->metadata) ? $interaction->metadata : [];
        $turn = $metadata[AiTurnTrace::TURN_METADATA_KEY] ?? null;
        $turn = is_array($turn) ? $turn : null;

        $inspection = [
            'mode' => 'explain',
            'run' => self::persistedRun($metadata, $turn, $interaction),
            // `identity` du bloc `turn` : surface, mode, execution_path,
            // capability, provider_*, fallback_* — ce que le moteur a DEPOSE.
            'identity' => is_array($turn['identity'] ?? null) && $turn['identity'] !== [] ? $turn['identity'] : null,
            'decision' => self::decision($turn),
            'steps' => is_array($turn['steps'] ?? null) && $turn['steps'] !== [] ? array_values($turn['steps']) : null,
            'history' => self::history($metadata),
            'sources' => is_array($turn['sources'] ?? null) && $turn['sources'] !== [] ? $turn['sources'] : null,
            'retrieval_trace' => self::retrievalTrace($metadata),
            'state' => self::persistedState($metadata, $turn),
            'output' => self::persistedOutput($metadata, $interaction),
            'provider' => self::provider($metadata, $interaction),
        ];

        // TASK-1575 / V0-H — la verite de chaque champ, section du LECTEUR.
        $inspection['truth'] = self::truthLabels($inspection, $turn);

        return $inspection;
    }

    /**
     * Champs DECLARES : leur valeur vient d'une declaration (registre de
     * capability, nom de chemin fourni par l'appelant — C15/G-β —, config),
     * pas d'une observation du tour. `section.champ`.
     *
     * @var list<string>
     */
    private const DECLARED_FIELDS = [
        'run.capability', 'run.process', 'run.feature',
        'identity.surface', 'identity.mode', 'identity.execution_path', 'identity.capability', 'identity.producer',
        'provider.provider',
    ];

    /**
     * Champs DERIVES par ce lecteur, avec les champs MESURES dont ils
     * dependent. Un derive dont une dependance est UNAVAILABLE est UNAVAILABLE :
     * on ne calcule rien sur du vide (CDC-01 §8.2).
     *
     * @var array<string, list<string>>
     */
    private const DERIVED_FIELDS = [
        'run.turn_id_source' => ['run.turn_id'],
        'state.source' => [],
        'state.rule' => ['state.turn_status', 'state.verification_status', 'state.degraded_reason'],
    ];

    /**
     * TASK-1575 / CDC-01 V0-H — un label de verite par champ de l'inspection
     * (correction C1 : le vocabulaire existait au CDC-02, jamais dans le code).
     *
     * Le label ne juge pas la valeur, il dit d'ou elle vient. `null` est
     * UNAVAILABLE — c'est la regle « NULL reste NULL » qui prend un nom — SAUF
     * quand le bloc `turn` persiste porte la cle explicitement : un writer qui
     * ecrit `degraded_reason: null` a MESURE « pas de degradation », il n'a pas
     * oublie le champ. Le lecteur ne peut le savoir qu'en regardant le bloc,
     * jamais la valeur seule.
     *
     * Le repli `legacy_metadata` de `state` (tours anterieurs a V0-A) est une
     * derivation du lecteur (`fromTurnMetadata`) : etiquete DERIVED, et
     * UNAVAILABLE des que la mesure d'origine manque.
     *
     * @param  array<string, mixed>  $inspection
     * @param  array<string, mixed>|null  $turn  le bloc `turn` persiste, tel qu'ecrit
     * @return array<string, string>  `section.champ` => label
     */
    public static function truthLabels(array $inspection, ?array $turn = null): array
    {
        $labels = [];

        foreach (['run', 'identity', 'decision', 'history', 'sources', 'retrieval_trace', 'state', 'output', 'provider'] as $section) {
            $valeurs = $inspection[$section] ?? null;

            if (! is_array($valeurs)) {
                $labels[$section] = AiTruthLabel::UNAVAILABLE;

                continue;
            }

            foreach ($valeurs as $champ => $valeur) {
                $cle = $section.'.'.$champ;
                $labels[$cle] = match (true) {
                    in_array($cle, self::DECLARED_FIELDS, true) => $valeur === null ? AiTruthLabel::UNAVAILABLE : AiTruthLabel::DECLARED,
                    array_key_exists($cle, self::DERIVED_FIELDS) => $valeur === null ? AiTruthLabel::UNAVAILABLE : AiTruthLabel::DERIVED,
                    $valeur === null && ! self::turnPorteLaCle($turn, $section, (string) $champ) => AiTruthLabel::UNAVAILABLE,
                    default => AiTruthLabel::MEASURED,
                };
            }
        }

        // `steps` : une chronologie deposee par les executants — mesuree en
        // bloc, UNAVAILABLE si le tour n'en porte aucune.
        $labels['steps'] = is_array($inspection['steps'] ?? null) ? AiTruthLabel::MEASURED : AiTruthLabel::UNAVAILABLE;

        foreach (self::derivations($inspection) as $cle => $dependances) {
            if (($labels[$cle] ?? AiTruthLabel::UNAVAILABLE) === AiTruthLabel::UNAVAILABLE) {
                continue;
            }

            $labels[$cle] = AiTruthLabel::DERIVED;

            foreach ($dependances as $dependance) {
                if (($labels[$dependance] ?? AiTruthLabel::UNAVAILABLE) === AiTruthLabel::UNAVAILABLE) {
                    $labels[$cle] = AiTruthLabel::UNAVAILABLE;

                    break;
                }
            }
        }

        return $labels;
    }

    /**
     * Les champs que CE lecteur calcule, et les champs mesures dont chacun
     * depend — l'autorite unique pour `truthLabels()` et pour la garde de test
     * « aucun DERIVED sur un UNAVAILABLE » (CDC-01 §8.2).
     *
     * @param  array<string, mixed>  $inspection
     * @return array<string, list<string>>
     */
    public static function derivations(array $inspection): array
    {
        $derives = self::DERIVED_FIELDS;

        if (($inspection['state']['source'] ?? null) === 'legacy_metadata') {
            // Aucun bloc `turn` : les trois axes sont CALCULES par le lecteur
            // depuis l'ancien format (`fromTurnMetadata`), ils ne sont pas lus.
            $derives['state.turn_status'] = ['run.status'];
            $derives['state.verification_status'] = ['output.grounded'];
            $derives['state.degraded_reason'] = ['provider.sources_denied'];
        }

        return $derives;
    }

    /**
     * Le bloc `turn` persiste porte-t-il explicitement ce champ (meme a
     * `null`) ? `decision` est lu a la racine du bloc (`status`, `stage`,
     * `reason_code`, `decided_by`, `latency_ms`) ; `identity`, `sources`,
     * `state` sont des sous-blocs.
     *
     * @param  array<string, mixed>|null  $turn
     */
    private static function turnPorteLaCle(?array $turn, string $section, string $champ): bool
    {
        if ($turn === null) {
            return false;
        }

        return match ($section) {
            'decision' => array_key_exists($champ, $turn),
            'identity', 'sources', 'state' => is_array($turn[$section] ?? null) && array_key_exists($champ, $turn[$section]),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $turn
     * @return array<string, mixed>
     */
    private static function persistedRun(array $metadata, ?array $turn, AiInteraction $interaction): array
    {
        $canonique = self::stringOrNull($turn['id'] ?? null);
        $historique = self::stringOrNull($metadata['turn_id'] ?? null);

        return [
            'turn_id' => $canonique ?? $historique,
            'turn_id_source' => $canonique !== null ? 'turn.id' : ($historique !== null ? 'metadata.turn_id' : null),
            'turn_schema' => self::intOrNull($turn['schema'] ?? null),
            'correlation_id' => self::stringOrNull($interaction->correlation_id),
            'ai_interaction_id' => self::stringOrNull($interaction->id),
            'organization_id' => self::stringOrNull($interaction->organization_id),
            'capability' => self::stringOrNull($metadata['capability'] ?? null),
            'process' => self::stringOrNull($interaction->process),
            'feature' => self::stringOrNull($interaction->feature),
            'status' => self::stringOrNull($metadata['status'] ?? null),
            'created_at' => $interaction->created_at?->toIso8601String(),
        ];
    }

    /**
     * Le VERDICT du tour, tel que le writer l'a ecrit (CDC-01 §5.2). Chaque cle
     * est presente, `null` quand le writer ne l'a pas fournie — les writers non
     * pilotes n'en ecrivent aucune avant V0-B / V0-C, et c'est exactement ce
     * que cette section doit montrer.
     *
     * @param  array<string, mixed>|null  $turn
     * @return array<string, mixed>
     */
    private static function decision(?array $turn): array
    {
        return [
            'status' => self::stringOrNull($turn['status'] ?? null),
            'stage' => self::stringOrNull($turn['stage'] ?? null),
            'reason_code' => self::stringOrNull($turn['reason_code'] ?? null),
            'decided_by' => self::stringOrNull($turn['decided_by'] ?? null),
            'latency_ms' => self::intOrNull($turn['latency_ms'] ?? null),
        ];
    }

    /**
     * Les trois axes, lus du bloc `turn` quand il existe (V0-A,
     * `fromTurnBlock` : aucune derivation), de l'ancien format sinon
     * (`fromTurnMetadata`, qui derive l'axe 2 de `grounded` — c'est le repli
     * documente pour les tours anterieurs, et la source est dite).
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $turn
     * @return array<string, mixed>
     */
    private static function persistedState(array $metadata, ?array $turn): array
    {
        $etat = $turn !== null
            ? AiTurnState::fromTurnBlock($turn)
            : AiTurnState::fromTurnMetadata(['status' => $metadata['status'] ?? null, 'grounded' => $metadata['grounded'] ?? null], $metadata);

        return [
            'source' => $turn !== null ? 'turn' : 'legacy_metadata',
            'turn_status' => $etat->turnStatus,
            'verification_status' => $etat->verificationStatus,
            'degraded_reason' => $etat->degradedReason,
            'rule' => $etat->rule(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private static function persistedOutput(array $metadata, AiInteraction $interaction): array
    {
        $retrieval = is_array($metadata['retrieval'] ?? null) ? $metadata['retrieval'] : null;

        return [
            'response' => self::stringOrNull($interaction->response),
            'failure' => self::stringOrNull($metadata['failure'] ?? null),
            // `grounded` n'est ecrit que par les producteurs documentaires qui
            // citent ; absent ailleurs, et l'absence est une information.
            'grounded' => self::boolOrNull($metadata['grounded'] ?? null),
            // `retrieval.{consulted,cited}` : les ids que le chemin documentaire
            // ecrit deja (compat lecteurs, CDC-01 P0.6). Des identifiants, pas
            // les extraits — ceux-la ne sont pas persistes et ne sont pas
            // reconstruits.
            'consulted_chunk_ids' => is_array($retrieval['consulted'] ?? null) ? array_values($retrieval['consulted']) : null,
            'cited_chunk_ids' => is_array($retrieval['cited'] ?? null) ? array_values($retrieval['cited']) : null,
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
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function retrieval(string $question, KnowledgeAnswer $answer, array $metadata): array
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

        // TASK-1565 : le bassin dense, lu dans la trace que la source a ecrite.
        // Absente, la valeur reste `null` — la regle de tete est inchangee.
        $trace = self::dossierRetrievalTrace($metadata);

        return [
            'query' => $question,
            'candidates_found' => self::intOrNull($trace['dense_candidates_count'] ?? null),
            'consulted_count' => count($answer->consulted),
            'entries' => $lignes,
        ];
    }

    /**
     * TASK-1565 — les etages du retrieval documentaire, tels que le pipeline
     * les a ecrits.
     *
     * Aucune valeur n'est recomposee ici : ce qui n'a pas ete trace est `null`,
     * et un `null` ne devient jamais un `0`. La section entiere vaut `null`
     * quand l'interaction ne porte pas la cle — une trace anterieure a T1565,
     * ou un tour dont la collecte etait coupee.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private static function retrievalTrace(array $metadata): ?array
    {
        $bloc = $metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY] ?? null;

        if (! is_array($bloc)) {
            return null;
        }

        $trace = self::dossierRetrievalTrace($metadata);

        return [
            // T1554 / W3A — porte ici et non au premier niveau de la metadata :
            // au premier niveau, cette cle allume un bandeau visible par le
            // membre (`AiResponseExplanationService` -> `loops.why_denied`).
            // L'inspection la voit, le produit ne change pas.
            'sources_denied' => is_array($bloc['sources_denied'] ?? null) ? $bloc['sources_denied'] : null,
            'dense_candidates_count' => self::intOrNull($trace['dense_candidates_count'] ?? null),
            'after_distance_filter_count' => self::intOrNull($trace['after_distance_filter_count'] ?? null),
            'max_distance' => isset($trace['max_distance']) ? (float) $trace['max_distance'] : null,
            'rerank_attempted' => self::boolOrNull($trace['rerank_attempted'] ?? null),
            'rerank_succeeded' => self::boolOrNull($trace['rerank_succeeded'] ?? null),
            'reason_not_attempted' => self::stringOrNull($trace['reason_not_attempted'] ?? null),
            'candidates_sent_to_rerank_count' => self::intOrNull($trace['candidates_sent_to_rerank_count'] ?? null),
            'rerank_result_count' => self::intOrNull($trace['rerank_result_count'] ?? null),
            'rerank_provider' => self::stringOrNull($trace['rerank_provider'] ?? null),
            'rerank_model' => self::stringOrNull($trace['rerank_model'] ?? null),
            'rerank_duration_ms' => self::intOrNull($trace['rerank_duration_ms'] ?? null),
            'rerank_failure_reason' => self::stringOrNull($trace['rerank_failure_reason'] ?? null),
            'overview' => self::boolOrNull($trace['overview'] ?? null),
            'final_context_count' => self::intOrNull($trace['final_context_count'] ?? null),
            'candidates' => is_array($trace['candidates'] ?? null) ? array_values($trace['candidates']) : [],
        ];
    }

    /**
     * Les refus portes par `retrieval_trace`, quand le premier niveau ne les
     * porte pas (chemin Loop, T1565). `null` quand ni l'un ni l'autre ne les a.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>|null
     */
    private static function deniedFromTrace(array $metadata): ?array
    {
        $bloc = $metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY] ?? null;

        if (! is_array($bloc) || ! is_array($bloc['sources_denied'] ?? null)) {
            return null;
        }

        return $bloc['sources_denied'];
    }

    /**
     * La sous-trace ecrite par `DossierRetrievalSource`, ou `[]`.
     *
     * `[]` et non `null` parce que les appelants y piochent cle par cle avec
     * `?? null` : c'est le tableau qui est absent, jamais la valeur qui devient
     * zero.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private static function dossierRetrievalTrace(array $metadata): array
    {
        $bloc = $metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY] ?? null;

        if (! is_array($bloc) || ! is_array($bloc['dossier_retrieval'] ?? null)) {
            return [];
        }

        return $bloc['dossier_retrieval'];
    }

    /**
     * L'historique conversationnel que le tour a REELLEMENT recu.
     *
     * Lecture PURE du bloc `turn.history` ecrit par le moteur. Aucune
     * reconstruction : ni depuis `context_message_ids` (qui vit sur la BULLE et
     * non sur le tour), ni depuis `AiShellThread`, ni depuis aucune autre
     * source. Ces sources secondaires decrivent la fenetre CANDIDATE, pas le
     * contexte injecte — et un lecteur qui comble un trou avec une valeur
     * voisine fabrique une certitude que personne n'a mesuree.
     *
     * `null` signifie « ce tour n'a pas ecrit son historique » : un tour
     * anterieur a V0-L, ou un chemin qui n'en a pas.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private static function history(array $metadata): ?array
    {
        $turn = $metadata[AiTurnTrace::TURN_METADATA_KEY] ?? null;

        if (! is_array($turn)) {
            return null;
        }

        $history = $turn['history'] ?? null;

        return is_array($history) && $history !== [] ? $history : null;
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
            // T1554 : ecrites par `DossierInsightsService` ; T1565 : le chemin
            // Loop les porte enfin (dette W3A payee). `null` dit « cette trace
            // ne les porte pas », et surtout pas « aucune source refusee ».
            'sources_used' => isset($metadata['sources_used']) && is_array($metadata['sources_used'])
                ? array_values($metadata['sources_used'])
                : null,
            // T1565 : le chemin Loop les depose dans `retrieval_trace` et non
            // au premier niveau — au premier niveau, la cle allume un bandeau
            // visible par le membre. Les deux emplacements sont lus ici pour
            // que l'inspection rende UNE reponse, quelle que soit l'ecriture.
            'sources_denied' => isset($metadata['sources_denied']) && is_array($metadata['sources_denied'])
                ? $metadata['sources_denied']
                : self::deniedFromTrace($metadata),
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

    /** `false` est une MESURE, `null` une absence de mesure : jamais confondus. */
    private static function boolOrNull(mixed $valeur): ?bool
    {
        return $valeur === null ? null : (bool) $valeur;
    }
}
