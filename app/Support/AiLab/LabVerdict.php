<?php

namespace App\Support\AiLab;

use App\Support\Ai\AiTurnComparison;

/**
 * TASK-1591 / CDC-NIGHT L-C_CORE — le VERDICT d'un scenario : `expected`
 * confronte a ce que la trace a REELLEMENT ecrit.
 *
 *   PASS         chaque attente declaree est satisfaite
 *   FAIL         une attente est contredite -> first_failed_component (etape P0.3, ou `data`)
 *   UNAVAILABLE  le pipeline n'a pas ete juge (preconditions NO/UNAVAILABLE,
 *                trace absente alors qu'attendue, execution impossible)
 *
 * Pure : des tableaux en entree (inspection, projection, derives
 * d'historique), un tableau en sortie. Aucun LLM juge (CDC-03 §5.1) : les
 * assertions de contenu sont celles du fichier, mot pour mot.
 *
 * `first_failed_component` est la PREMIERE etape (ordre P0.3) dont l'attente
 * est contredite ; les ecarts d'identite (`execution_path`), de fixture et de
 * fuite sont classes `data` ; un contenu contredit est classe a l'etape qui
 * l'a produit (`generation`) ou, si le tour s'est arrete avant, a son `stage`.
 */
final class LabVerdict
{
    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNAVAILABLE = 'UNAVAILABLE';

    public const COMPONENT_DATA = 'data';

    /**
     * @param  array<string, mixed>  $preconditions  sortie de LabPreconditions::establish()
     * @param  list<array{order: int, refused: bool, refusal: ?string, refused_before_run: bool, inspection: ?array<string, mixed>, projection: ?array<string, mixed>, history_derived: ?array<string, string>, response: ?string}>  $turns
     * @param  array<string, string>  $loopDossierIds  dossier_id => loop key, les Dossiers de la Loop du scenario
     * @return array<string, mixed>
     */
    public static function judge(LabScenario $scenario, array $preconditions, array $turns, array $loopDossierIds): array
    {
        $x = $scenario->expected();
        $divergences = [];
        $leak = false;

        // Fuite : verifiee TOUJOURS, meme sur un pipeline non juge — c'est une STOP condition.
        $sentinel = $x['tenant']['must_not_leak'] ?? null;
        if (is_string($sentinel) && $sentinel !== '') {
            foreach ($turns as $t) {
                $corpus = json_encode([$t['response'], $t['refusal'], $t['inspection'], $t['projection']], JSON_UNESCAPED_UNICODE) ?: '';
                if (stripos($corpus, $sentinel) !== false) {
                    $leak = true;
                    $divergences[] = ['component' => self::COMPONENT_DATA, 'field' => 'tenant.must_not_leak', 'expected' => 'absent', 'actual' => 'PRESENT', 'leak' => true];
                }
            }
        }

        // Preconditions par rapport a l'attendu : NO -> data ; UNAVAILABLE -> non juge.
        if ($preconditions['verdict'] === LabPreconditions::UNAVAILABLE) {
            return self::result(self::UNAVAILABLE, $leak ? self::COMPONENT_DATA : self::UNAVAILABLE, $divergences, $leak, 'preconditions non etablissables : '.self::failing($preconditions));
        }
        if ($preconditions['verdict'] === LabPreconditions::NO) {
            return self::result(self::UNAVAILABLE, self::COMPONENT_DATA, $divergences, $leak, 'precondition non conforme (fixture) : '.self::failing($preconditions));
        }

        // Acces : attendu vs observe.
        $last = $turns === [] ? null : $turns[array_key_last($turns)];
        if ($last !== null && ($last['not_established'] ?? false)) {
            return self::result(self::UNAVAILABLE, $leak ? self::COMPONENT_DATA : self::UNAVAILABLE, $divergences, $leak, 'tour non etabli : '.$last['refusal']);
        }
        // Un refus DANS le pipeline garde son AiInteraction non generative (T1570) : refuse = refuse.
        $observedDenied = $last !== null && $last['refused'];
        $observedStage = $last !== null && $last['refused_before_run'] ? 'surface_authorization' : 'ai_pipeline';
        $expectedAccess = $x['access']['status'];

        if ($expectedAccess === 'denied') {
            if (! $observedDenied) {
                $divergences[] = ['component' => self::COMPONENT_DATA, 'field' => 'access.status', 'expected' => 'denied', 'actual' => 'allowed'];
            } elseif (($x['access']['stage'] ?? null) !== $observedStage) {
                $divergences[] = ['component' => self::COMPONENT_DATA, 'field' => 'access.stage', 'expected' => $x['access']['stage'], 'actual' => $observedStage];
            }
            if (($x['turn'] ?? 'absent') === 'absent' && $last !== null && $last['inspection'] !== null) {
                $divergences[] = ['component' => self::COMPONENT_DATA, 'field' => 'turn', 'expected' => 'absent', 'actual' => 'present'];
            }

            return self::result($divergences === [] ? self::PASS : self::FAIL, $divergences === [] ? null : $divergences[0]['component'], $divergences, $leak, null);
        }

        // Acces attendu allowed : un refus est une divergence a l'etape qui a refuse.
        if ($last === null) {
            return self::result(self::UNAVAILABLE, self::UNAVAILABLE, $divergences, $leak, 'aucun tour execute');
        }
        if ($last['inspection'] === null || $last['refused']) {
            // Acces autorise mais tour refuse (economie, panne) : attendu si le
            // scenario declare `turn = absent` + classe `refuse`, divergence sinon.
            $attendu = ($x['turn'] ?? 'present') === 'absent' && $x['answer']['class'] === 'refuse';
            if ($attendu) {
                return self::result($divergences === [] ? self::PASS : self::FAIL, self::firstComponent($divergences), $divergences, $leak, null);
            }
            $component = $last['refused_before_run'] ? self::COMPONENT_DATA : ($last['inspection']['decision']['stage'] ?? 'economic_check');

            return self::result(self::FAIL, $component, [...$divergences, ['component' => $component, 'field' => 'turn', 'expected' => 'present', 'actual' => 'refused', 'detail' => $last['refusal']]], $leak, null);
        }

        $trace = $last['inspection'];

        // Identite : le chemin attendu.
        if (($x['execution_path'] ?? null) !== null && ($trace['identity']['execution_path'] ?? null) !== $x['execution_path']) {
            $divergences[] = ['component' => self::COMPONENT_DATA, 'field' => 'execution_path', 'expected' => $x['execution_path'], 'actual' => $trace['identity']['execution_path'] ?? null];
        }

        // Etapes : ordre P0.3, `conditional` = joker.
        $steps = [];
        foreach ($trace['steps'] ?? [] as $s) {
            $steps[$s['name']] ??= $s;
        }
        foreach (AiTurnComparison::STEPS as $name) {
            if (! array_key_exists($name, $x['steps'] ?? [])) {
                continue;
            }
            $expected = $x['steps'][$name];
            $actual = $steps[$name]['status'] ?? null;
            if ($expected === 'conditional') {
                continue;
            }
            if ($actual !== $expected) {
                $divergences[] = ['component' => $name, 'field' => "steps.{$name}", 'expected' => $expected, 'actual' => $actual ?? 'absent'];
            }
            $code = $x['reason_code'][$name] ?? null;
            if ($code !== null && ($steps[$name]['reason_code'] ?? null) !== $code) {
                $divergences[] = ['component' => $name, 'field' => "reason_code.{$name}", 'expected' => $code, 'actual' => $steps[$name]['reason_code'] ?? null];
            }
        }

        // Reponse : classe + assertion declaree.
        $status = $trace['decision']['status'] ?? null;
        $stage = $trace['decision']['stage'] ?? 'generation';
        $class = $x['answer']['class'];
        $assertion = $x['answer']['assertion'] ?? ['type' => 'none', 'values' => []];
        $response = (string) ($last['response'] ?? '');

        if ($class === 'factual') {
            if ($status !== 'answered') {
                $divergences[] = ['component' => $stage ?? 'generation', 'field' => 'answer.class', 'expected' => 'factual (answered)', 'actual' => $status];
            } elseif (! self::asserts($assertion, $response)) {
                $divergences[] = ['component' => 'generation', 'field' => 'answer.assertion', 'expected' => $assertion, 'actual' => mb_substr($response, 0, 200)];
            }
        } elseif ($class === 'abstain') {
            $abstained = $status === 'abstained' || ($status === 'answered' && self::asserts($assertion, $response));
            if (! $abstained) {
                $divergences[] = ['component' => 'grounding', 'field' => 'answer.class', 'expected' => 'abstain', 'actual' => $status.' : '.mb_substr($response, 0, 200)];
            }
        } elseif ($class === 'refuse' && $status !== 'refused') {
            $divergences[] = ['component' => $stage ?? 'economic_check', 'field' => 'answer.class', 'expected' => 'refuse', 'actual' => $status];
        }
        // observe : aucune assertion (le scenario MESURE).

        // Historique (tour ≥ 2) : les derives d'AiConversationTrace.
        foreach (($x['history'] ?? []) as $turnKey => $expectedDerived) {
            $n = (int) substr((string) $turnKey, 5);
            $turn = $turns[$n - 1] ?? null;
            $actualDerived = $turn['history_derived'] ?? null;
            foreach ($expectedDerived as $derive => $value) {
                $actual = $actualDerived[$derive] ?? 'UNAVAILABLE';
                if ($actual !== $value) {
                    $divergences[] = ['component' => 'conversation_history', 'field' => "history.{$turnKey}.{$derive}", 'expected' => $value, 'actual' => $actual];
                }
            }
        }

        // Sources : Dossier attendu, types interdits (projection, ids seulement).
        $chunks = $last['projection']['chunks'] ?? [];
        $must = $x['sources']['must_use_dossier'] ?? null;
        if ($must !== null && $status === 'answered') {
            $usedLoops = array_values(array_unique(array_filter(array_map(static fn (array $c): ?string => $loopDossierIds[$c['dossier_id'] ?? ''] ?? null, array_filter($chunks, static fn (array $c): bool => (bool) ($c['cited'] ?? false) || (bool) ($c['present'] ?? false))))));
            if (! in_array($must, $usedLoops, true)) {
                $divergences[] = ['component' => 'retrieval', 'field' => 'sources.must_use_dossier', 'expected' => $must, 'actual' => $usedLoops === [] ? 'aucune source' : implode(',', $usedLoops)];
            }
        }
        foreach (($x['sources']['must_not_use_source_type'] ?? []) as $type) {
            $hits = array_filter($chunks, static fn (array $c): bool => ($c['source_type'] ?? null) === $type);
            if ($hits !== []) {
                $divergences[] = ['component' => 'retrieval', 'field' => 'sources.must_not_use_source_type', 'expected' => "aucun {$type}", 'actual' => count($hits)." chunk(s) {$type}"];
            }
        }

        $first = self::firstComponent($divergences);

        return self::result($divergences === [] ? self::PASS : self::FAIL, $first, $divergences, $leak, null);
    }

    /** @param  array{type: string, values: list<string>}  $assertion */
    private static function asserts(array $assertion, string $response): bool
    {
        $values = $assertion['values'] ?? [];

        return match ($assertion['type'] ?? 'none') {
            'contains_any' => array_any($values, static fn (string $v): bool => mb_stripos($response, $v) !== false),
            'contains_all' => array_all($values, static fn (string $v): bool => mb_stripos($response, $v) !== false),
            'regex' => array_any($values, static fn (string $v): bool => @preg_match('/'.str_replace('/', '\\/', $v).'/u', $response) === 1),
            default => true,
        };
    }

    /** @param  list<array<string, mixed>>  $divergences */
    private static function firstComponent(array $divergences): ?string
    {
        if ($divergences === []) {
            return null;
        }
        // `data` (fixture, fuite) prime : on ne juge pas un pipeline sur des donnees fausses.
        $order = array_flip([self::COMPONENT_DATA, ...AiTurnComparison::STEPS]);
        usort($divergences, static fn (array $a, array $b): int => ($order[$a['component']] ?? 99) <=> ($order[$b['component']] ?? 99));

        return $divergences[0]['component'];
    }

    /** @param  array<string, mixed>  $preconditions */
    private static function failing(array $preconditions): string
    {
        $parts = [];
        foreach ($preconditions['checks'] as $name => $check) {
            if ($check['status'] !== LabPreconditions::YES) {
                $parts[] = "{$name}={$check['status']}".(isset($check['detail']) ? " ({$check['detail']})" : '');
            }
        }

        return implode(' ; ', $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $divergences
     * @return array<string, mixed>
     */
    private static function result(string $result, ?string $first, array $divergences, bool $leak, ?string $reason): array
    {
        return [
            'result' => $result,
            'first_failed_component' => $first,
            'divergences' => $divergences,
            'divergence_classes' => array_values(array_unique(array_column($divergences, 'component'))),
            'leak' => $leak,
            'unavailable_reason' => $reason,
        ];
    }
}
