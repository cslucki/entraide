<?php

namespace App\Support\Ai;

/**
 * TASK-1584 / CDC-02 TRACE-1C — deux tours se COMPARENT.
 *
 * Dire OU deux executions divergent, sans opinion sur le texte. Les deux
 * entrees sont des inspections (`AiTurnInspection::fromPersistedTurn`) : la
 * comparaison ne lit rien d'autre, n'execute rien, ne juge rien.
 *
 * Trois blocs, distincts par construction :
 *   identity_differences[]  ecarts d'IDENTITE (surface, mode, chemin,
 *                           capability, provider, fallback) — rapportes a part,
 *                           ils precedent toute divergence de pipeline et
 *                           n'entrent pas dans `first_divergent_step` ;
 *   first_divergent_step    la PREMIERE etape (ordre P0.3) dont le status ou le
 *                           reason_code diverge ; null si alignees ; ce n'est
 *                           pas la premiere difference absolue ;
 *   divergences[]           { field, a, b, class }, classe fermee.
 *
 * Contrat d'etapes (arbitrage MASTER 16/09) : les HUIT etapes reellement
 * emises par les writers — `source_filtering` n'a aucun emetteur (FACT) et
 * n'est ni comparee ni ajoutee au moteur. `provider_resolution` est un stage
 * de verdict, pas une etape.
 *
 * Un tour sans bloc `turn` (anterieur au V0) rend UNAVAILABLE partout — jamais
 * un faux « identique ». Deux schemas differents (v1 / v2) restent comparables
 * sur la v1 commune, et la comparaison le DIT.
 */
final class AiTurnComparison
{
    /** L'ordre P0.3 du CDC-01, sans `source_filtering` (jamais emis). */
    public const STEPS = ['conversation_history', 'economic_check', 'context_builder', 'retrieval', 'rerank', 'grounding', 'provider_call', 'generation'];

    public const CLASSES = ['IDENTITY', 'HISTORY', 'PIPELINE', 'CONTEXT', 'RETRIEVAL', 'RERANK', 'PROVIDER', 'GENERATION', 'CITATION', 'OUTCOME', 'TIMING'];

    private const IDENTITY_FIELDS = ['surface', 'mode', 'execution_path', 'capability', 'provider_requested', 'provider_effective', 'fallback_used'];

    /** Classe « domaine » d'une etape, quand seul le code diverge a status egal. */
    private const STEP_DOMAIN = [
        'conversation_history' => 'HISTORY',
        'economic_check' => 'OUTCOME',
        'context_builder' => 'CONTEXT',
        'retrieval' => 'RETRIEVAL',
        'rerank' => 'RERANK',
        'grounding' => 'CITATION',
        'provider_call' => 'PROVIDER',
        'generation' => 'GENERATION',
    ];

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    public static function compare(array $a, array $b): array
    {
        // Un bloc `turn` present porte toujours son schema ; `metadata.turn_id`
        // seul (historique) n'est PAS un tour comparable.
        $aDispo = ($a['run']['turn_schema'] ?? null) !== null;
        $bDispo = ($b['run']['turn_schema'] ?? null) !== null;

        $entete = [
            'a' => ['ai_interaction_id' => $a['run']['ai_interaction_id'] ?? null, 'turn_id' => $a['run']['turn_id'] ?? null, 'turn_schema' => $a['run']['turn_schema'] ?? null, 'turn_available' => $aDispo],
            'b' => ['ai_interaction_id' => $b['run']['ai_interaction_id'] ?? null, 'turn_id' => $b['run']['turn_id'] ?? null, 'turn_schema' => $b['run']['turn_schema'] ?? null, 'turn_available' => $bDispo],
            'steps_contract' => self::STEPS,
        ];

        if (! $aDispo || ! $bDispo) {
            // (d) : un tour pre-V0 ne se compare pas — on ne sait rien de lui,
            // et « identique » serait un mensonge.
            return $entete + [
                'comparable' => false,
                'reason' => 'turn_unavailable',
                'schema_note' => null,
                'identity_differences' => null,
                'first_divergent_step' => null,
                'steps' => null,
                'divergences' => null,
                'timing' => null,
            ];
        }

        $divergences = [];

        // ── identite (a part)
        $identites = [];
        foreach (self::IDENTITY_FIELDS as $champ) {
            $va = $a['identity'][$champ] ?? null;
            $vb = $b['identity'][$champ] ?? null;
            if ($va !== $vb) {
                $identites[] = ['field' => 'identity.'.$champ, 'a' => $va, 'b' => $vb];
                $divergences[] = ['field' => 'identity.'.$champ, 'a' => $va, 'b' => $vb, 'class' => 'IDENTITY'];
            }
        }

        // ── etapes (ordre P0.3, contrat a 8)
        $etapesA = self::etapes($a);
        $etapesB = self::etapes($b);
        $steps = [];
        $premiere = null;
        foreach (self::STEPS as $nom) {
            $sa = $etapesA[$nom] ?? ['status' => null, 'reason_code' => null];
            $sb = $etapesB[$nom] ?? ['status' => null, 'reason_code' => null];
            $diverge = $sa['status'] !== $sb['status'] || $sa['reason_code'] !== $sb['reason_code'];
            $steps[] = ['name' => $nom, 'a' => $sa, 'b' => $sb, 'divergent' => $diverge];
            if ($diverge) {
                $premiere ??= $nom;
                // Une etape ABSENTE d'un cote n'est pas « non executee » : le
                // writer ne l'a pas deposee. C'est dit tel quel (`absent_in`),
                // la classe reste celle de l'ecart de status.
                $absente = match (true) {
                    $sa['status'] === null && $sb['status'] === null => null,
                    $sa['status'] === null => 'a',
                    $sb['status'] === null => 'b',
                    default => null,
                };
                $divergences[] = ['field' => 'steps.'.$nom, 'a' => $sa, 'b' => $sb, 'class' => self::classeEtape($nom, $sa, $sb), 'absent_in' => $absente];
            }
        }

        // ── historique (jamais les textes)
        foreach (['strategy', 'count'] as $champ) {
            self::diff($divergences, 'history.'.$champ, $a['history'][$champ] ?? null, $b['history'][$champ] ?? null, 'HISTORY');
        }
        self::diff($divergences, 'history.trigger_present', isset($a['history']['trigger_id']), isset($b['history']['trigger_id']), 'HISTORY');

        // ── sources (compteurs et ids, jamais les extraits)
        foreach (['candidates', 'after_filter', 'final'] as $champ) {
            self::diff($divergences, 'sources.retrieved.'.$champ, $a['sources']['retrieved'][$champ] ?? null, $b['sources']['retrieved'][$champ] ?? null, 'RETRIEVAL');
        }
        foreach (['attempted', 'succeeded', 'result_count'] as $champ) {
            self::diff($divergences, 'sources.reranked.'.$champ, $a['sources']['reranked'][$champ] ?? null, $b['sources']['reranked'][$champ] ?? null, 'RERANK');
        }
        self::diff($divergences, 'sources.used', self::triee($a['sources']['used'] ?? null), self::triee($b['sources']['used'] ?? null), 'CONTEXT');
        self::diff($divergences, 'sources.denied', self::deniedTrie($a['sources']['denied'] ?? null), self::deniedTrie($b['sources']['denied'] ?? null), 'CONTEXT');

        // ── retrieval fin (retrieval_trace)
        foreach (['dense_candidates_count', 'after_distance_filter_count', 'max_distance'] as $champ) {
            self::diff($divergences, 'retrieval_trace.'.$champ, $a['retrieval_trace'][$champ] ?? null, $b['retrieval_trace'][$champ] ?? null, 'RETRIEVAL');
        }
        $candA = self::candidats($a);
        $candB = self::candidats($b);
        if ($candA !== null && $candB !== null) {
            self::diff($divergences, 'retrieval_trace.candidates.chunk_ids', array_keys($candA), array_keys($candB), 'RETRIEVAL');
            self::diff($divergences, 'retrieval_trace.candidates.selected_final', self::selection($candA), self::selection($candB), 'RETRIEVAL');
        }

        // ── provider (le modele ; le provider est une identite)
        self::diff($divergences, 'provider.model', $a['identity']['model'] ?? null, $b['identity']['model'] ?? null, 'PROVIDER');

        // ── outcome
        foreach (['status', 'stage', 'reason_code', 'decided_by'] as $champ) {
            self::diff($divergences, 'decision.'.$champ, $a['decision'][$champ] ?? null, $b['decision'][$champ] ?? null, 'OUTCOME');
        }
        self::diff($divergences, 'state.verification_status', $a['state']['verification_status'] ?? null, $b['state']['verification_status'] ?? null, 'CITATION');
        self::diff($divergences, 'state.degraded_reason', $a['state']['degraded_reason'] ?? null, $b['state']['degraded_reason'] ?? null, 'OUTCOME');
        self::diff($divergences, 'output.grounded', $a['output']['grounded'] ?? null, $b['output']['grounded'] ?? null, 'CITATION');

        // ── timing : rapporte a part, MEASURED des deux cotes ou UNAVAILABLE ;
        // une difference de latence est TOUJOURS une divergence TIMING (elle ne
        // dit rien du pipeline) et n'entre jamais dans first_divergent_step.
        $timing = [];
        foreach (['latency_ms' => ['decision', 'latency_ms'], 'rerank_duration_ms' => ['retrieval_trace', 'rerank_duration_ms']] as $nom => [$section, $champ]) {
            $ta = $a[$section][$champ] ?? null;
            $tb = $b[$section][$champ] ?? null;
            $timing[$nom] = ['a' => $ta, 'b' => $tb, 'label' => $ta === null || $tb === null ? AiTruthLabel::UNAVAILABLE : AiTruthLabel::MEASURED];
            if ($ta !== null && $tb !== null && $ta !== $tb) {
                $divergences[] = ['field' => 'timing.'.$nom, 'a' => $ta, 'b' => $tb, 'class' => 'TIMING'];
            }
        }

        $schemaA = $a['run']['turn_schema'] ?? null;
        $schemaB = $b['run']['turn_schema'] ?? null;

        return $entete + [
            'comparable' => true,
            'reason' => null,
            // s3 : deux schemas differents — comparables sur la v1 commune ;
            // dit, jamais tu.
            'schema_note' => $schemaA === $schemaB ? null : "schemas differents ({$schemaA} vs {$schemaB}) : compares sur le socle v1 commun, le lien run n'est pas compare",
            'identity_differences' => $identites,
            'first_divergent_step' => $premiere,
            'steps' => $steps,
            'divergences' => $divergences,
            'divergence_classes' => array_values(array_unique(array_column($divergences, 'class'))),
            'timing' => $timing,
        ];
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array<string, array{status: ?string, reason_code: ?string}>
     */
    private static function etapes(array $trace): array
    {
        $etapes = [];
        foreach ($trace['steps'] ?? [] as $etape) {
            $nom = $etape['name'] ?? null;
            if (is_string($nom) && ! isset($etapes[$nom])) {
                $etapes[$nom] = ['status' => $etape['status'] ?? null, 'reason_code' => $etape['reason_code'] ?? null];
            }
        }

        return $etapes;
    }

    /**
     * Status different -> PIPELINE (le tour n'a pas fait la meme chose) — sauf
     * `context_builder` bypasse par la voie documentaire directe
     * (`DOCUMENT_PATH_DIRECT_EXECUTION`) : c'est le CONTEXTE qui a ete
     * construit autrement, pas le pipeline (DONE (b)). Meme status, code
     * different -> classe du domaine de l'etape.
     *
     * @param  array{status: ?string, reason_code: ?string}  $sa
     * @param  array{status: ?string, reason_code: ?string}  $sb
     */
    private static function classeEtape(string $nom, array $sa, array $sb): string
    {
        if ($sa['status'] !== $sb['status']) {
            if ($nom === 'context_builder' && in_array(AiTurnReason::CONTEXT_BUILDER_DOCUMENT_PATH_DIRECT_EXECUTION, [$sa['reason_code'], $sb['reason_code']], true)) {
                return 'CONTEXT';
            }

            return 'PIPELINE';
        }

        return self::STEP_DOMAIN[$nom] ?? 'PIPELINE';
    }

    /** @param  list<array<string, mixed>>  $divergences */
    private static function diff(array &$divergences, string $champ, mixed $va, mixed $vb, string $classe): void
    {
        if ($va !== $vb) {
            $divergences[] = ['field' => $champ, 'a' => $va, 'b' => $vb, 'class' => $classe];
        }
    }

    /** @return list<string>|null */
    private static function triee(mixed $liste): ?array
    {
        if (! is_array($liste)) {
            return null;
        }
        $liste = array_values(array_map(static fn ($e): string => is_array($e) ? (string) json_encode($e) : (string) $e, $liste));
        sort($liste);

        return $liste;
    }

    /** @return list<string>|null */
    private static function deniedTrie(mixed $liste): ?array
    {
        if (! is_array($liste)) {
            return null;
        }
        $cles = array_map(static fn ($d): string => is_array($d) ? (($d['source'] ?? '?').':'.($d['reason'] ?? '?')) : (string) $d, $liste);
        sort($cles);

        return array_values($cles);
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array<string, bool>|null  chunk_id => selected_final
     */
    private static function candidats(array $trace): ?array
    {
        $liste = $trace['retrieval_trace']['candidates'] ?? null;
        if (! is_array($liste) || $liste === []) {
            return null;
        }
        $resultat = [];
        foreach ($liste as $c) {
            if (is_array($c) && isset($c['chunk_id'])) {
                $resultat[(string) $c['chunk_id']] = (bool) ($c['selected_final'] ?? false);
            }
        }
        ksort($resultat);

        return $resultat;
    }

    /** @param  array<string, bool>  $candidats @return list<string> */
    private static function selection(array $candidats): array
    {
        return array_values(array_keys(array_filter($candidats)));
    }
}
