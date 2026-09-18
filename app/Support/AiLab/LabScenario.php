<?php

namespace App\Support\AiLab;

use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiTurnComparison;
use App\Support\Ai\AiTurnProjection;
use App\Support\Ai\AiTurnReason;
use App\Support\ScenarioPacks\Packs\AiLabPack;

/**
 * TASK-1590 / CDC-NIGHT L-B_CORE — un scenario de Lab : ses attentes DECLAREES
 * avant toute execution (CDC-03 K3, §5.1).
 *
 * Un scenario est un fichier JSON sous `database/scenario-packs/ai-lab/
 * scenarios/<KEY>.json`. Cette classe le charge, le VALIDE contre le schema
 * §5.1 et rend ses champs types. Elle n'execute rien, ne requete rien : le
 * runner (L-C_CORE) consomme un `LabScenario` valide.
 *
 * Vocabulaire (K4 + gate TRACE0_SCHEMA_FROZEN) : les attentes parlent la
 * langue de la TRACE — `execution_path` ∈ `AiExecutionPath::all()`, etapes ∈
 * `AiTurnComparison::STEPS` (les 8 reellement emises), statuts d'etape ceux
 * qu'ecrivent les writers (+ `conditional`, joker du Lab), `reason_code` ∈
 * `AiTurnReason::all()`, `source_type` ∈ `AiTurnProjection::SOURCE_TYPE_*`,
 * derives d'historique = ceux d'`AiConversationTrace`. Aucun vocabulaire
 * parallele, jamais de `scenario_id` (cle de scenario de PROMPT, reservee).
 */
final class LabScenario
{
    public const DIRECTORY = 'scenario-packs/ai-lab/scenarios';

    public const KEY_PATTERN = '/^(LAB|ARTSCILAB|MAIN)\.[A-Z0-9_]+$/';

    public const SURFACES = ['loop_chat'];

    public const MODES = ['dossiers', 'ia_dossiers', 'ia'];

    public const REPLY_TO = ['previous_ai', 'previous_user'];

    public const EXECUTIONS = ['new_execution', 'existing_turn', 'new_execution_bounded'];

    public const PRECONDITION_REQUIRED = ['required', 'not_applicable'];

    public const ACCESS_EXPECTED = ['allowed', 'denied', 'not_applicable'];

    public const ACCESS_STATUSES = ['allowed', 'denied'];

    public const ACCESS_STAGES = ['surface_authorization', 'ai_pipeline'];

    public const TURN_PRESENCE = ['present', 'absent'];

    public const ANSWER_CLASSES = ['factual', 'abstain', 'clarify', 'refuse', 'observe'];

    public const ASSERTION_TYPES = ['regex', 'contains_any', 'contains_all', 'none'];

    /** FACT : statuts d'etape ecrits par les writers (grep app/) + `conditional` (joker du Lab). */
    public const STEP_STATUSES = ['executed', 'bypassed', 'skipped', 'not_applicable', 'denied', 'failed', 'abstained', 'fallback', 'conditional'];

    /** Les derives d'`AiConversationTrace` (T1579) qu'une attente peut viser. */
    public const HISTORY_DERIVED = ['PREVIOUS_AI_ANSWER_VISIBLE', 'PREVIOUS_USER_MESSAGE_VISIBLE', 'MODE_CHANGED', 'EXECUTION_PATH_CHANGED', 'CONTEXT_BUILDER_CHANGED', 'DOSSIER_CONTEXT_PRESERVED'];

    public const HISTORY_VALUES = ['YES', 'NO', 'UNAVAILABLE'];

    public const SOURCE_TYPES = [AiTurnProjection::SOURCE_TYPE_FILE, AiTurnProjection::SOURCE_TYPE_ARTICLE, AiTurnProjection::SOURCE_TYPE_DERIVED_KNOWLEDGE];

    /** @param  array<string, mixed>  $data */
    private function __construct(
        public readonly string $key,
        public readonly string $path,
        public readonly array $data,
    ) {}

    public static function directory(): string
    {
        return database_path(self::DIRECTORY);
    }

    /**
     * Charge UN fichier : leve si invalide (la liste complete des erreurs est
     * dans le message — un scenario partiellement lu n'existe pas).
     */
    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("Scenario introuvable ou illisible : {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException("Scenario non JSON : {$path}");
        }

        $errors = self::validate($decoded);

        if ($errors !== []) {
            throw new \InvalidArgumentException("Scenario invalide ({$path}) : ".implode(' ; ', $errors));
        }

        if (basename($path, '.json') !== $decoded['lab_scenario_key']) {
            throw new \InvalidArgumentException("Scenario {$path} : le nom du fichier doit etre la cle ({$decoded['lab_scenario_key']}).");
        }

        return new self($decoded['lab_scenario_key'], $path, $decoded);
    }

    /**
     * Tous les scenarios du repertoire, tries par cle ; leve sur le PREMIER
     * invalide ou sur une cle dupliquee (K4 : la cle est l'identite).
     *
     * @return array<string, self>
     */
    public static function all(?string $directory = null): array
    {
        $directory ??= self::directory();
        $files = glob(rtrim($directory, '/').'/*.json') ?: [];
        sort($files);
        $scenarios = [];

        foreach ($files as $file) {
            $scenario = self::fromFile($file);

            if (isset($scenarios[$scenario->key])) {
                throw new \InvalidArgumentException("Cle de scenario dupliquee : {$scenario->key}");
            }

            $scenarios[$scenario->key] = $scenario;
        }

        return $scenarios;
    }

    public static function find(string $key, ?string $directory = null): ?self
    {
        $path = rtrim($directory ?? self::directory(), '/')."/{$key}.json";

        return is_file($path) ? self::fromFile($path) : null;
    }

    /**
     * Validation du schema §5.1 — chaque erreur nomme le champ et la valeur
     * refusee ; la liste est complete (pas d'arret au premier defaut).
     *
     * @param  array<string, mixed>  $d
     * @return list<string>
     */
    public static function validate(array $d): array
    {
        $e = [];
        $in = static function (string $champ, mixed $valeur, array $vocab) use (&$e): void {
            if (! is_string($valeur) || ! in_array($valeur, $vocab, true)) {
                $e[] = "{$champ} = ".json_encode($valeur).' hors vocabulaire ['.implode('|', $vocab).']';
            }
        };

        if (array_key_exists('scenario_id', $d) || str_contains((string) json_encode($d), '"scenario_id"')) {
            $e[] = '`scenario_id` est RESERVE (cle de scenario de prompt) — utiliser lab_scenario_key (K4)';
        }

        $key = $d['lab_scenario_key'] ?? null;
        if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key)) {
            $e[] = 'lab_scenario_key manquante ou hors motif '.self::KEY_PATTERN;
        }

        if (($d['organization'] ?? null) !== AiLabPack::ORGANIZATION_SLUG && ! in_array($d['organization'] ?? null, ['artscilab-demo', 'main'], true)) {
            $e[] = 'organization = '.json_encode($d['organization'] ?? null).' inconnue';
        }
        if (($d['organization'] ?? null) === AiLabPack::ORGANIZATION_SLUG) {
            if (! isset(AiLabPack::LOOPS[$d['loop'] ?? ''])) {
                $e[] = 'loop = '.json_encode($d['loop'] ?? null).' inconnue du pack ['.implode('|', array_keys(AiLabPack::LOOPS)).']';
            }
            if (! isset(AiLabPack::PERSONAS[$d['user'] ?? '']) && ($d['user'] ?? null) !== 'lab.outsider') {
                $e[] = 'user = '.json_encode($d['user'] ?? null).' inconnu du pack';
            }
        }

        $turns = $d['turns'] ?? null;
        if (! is_array($turns) || $turns === []) {
            $e[] = 'turns : au moins un tour';
        } else {
            foreach (array_values($turns) as $i => $t) {
                $n = $i + 1;
                if (($t['order'] ?? null) !== $n) {
                    $e[] = "turns[{$i}].order doit valoir {$n}";
                }
                $in("turns[{$i}].surface", $t['surface'] ?? null, self::SURFACES);
                $in("turns[{$i}].mode", $t['mode'] ?? null, self::MODES);
                if (! is_string($t['question'] ?? null) || trim($t['question']) === '') {
                    $e[] = "turns[{$i}].question manquante";
                }
                if (! array_key_exists('reply_to', $t)) {
                    $e[] = "turns[{$i}].reply_to doit etre declare (null ou ".implode('|', self::REPLY_TO).')';
                } elseif ($t['reply_to'] !== null) {
                    $in("turns[{$i}].reply_to", $t['reply_to'], self::REPLY_TO);
                    if ($n === 1) {
                        $e[] = 'turns[0].reply_to doit etre null (aucun tour precedent)';
                    }
                }
            }
        }

        $in('execution', $d['execution'] ?? null, self::EXECUTIONS);

        $p = $d['preconditions'] ?? null;
        if (! is_array($p)) {
            $e[] = 'preconditions manquantes';
        } else {
            foreach (['pack_loaded', 'gold_exists', 'gold_indexed', 'derived_chunks_absent', 'quota'] as $c) {
                $in("preconditions.{$c}", $p[$c] ?? null, self::PRECONDITION_REQUIRED);
            }
            $in('preconditions.gold_access.expected', $p['gold_access']['expected'] ?? null, self::ACCESS_EXPECTED);
        }

        $x = $d['expected'] ?? null;
        if (! is_array($x)) {
            $e[] = 'expected manquant (K3 : un scenario sans expected n\'est pas un scenario)';

            return $e;
        }

        $in('expected.access.status', $x['access']['status'] ?? null, self::ACCESS_STATUSES);
        $in('expected.access.stage', $x['access']['stage'] ?? null, self::ACCESS_STAGES);
        $in('expected.turn', $x['turn'] ?? null, self::TURN_PRESENCE);

        $class = $x['answer']['class'] ?? null;
        $in('expected.answer.class', $class, self::ANSWER_CLASSES);
        $assertion = $x['answer']['assertion'] ?? null;
        if ($class === 'observe') {
            if (($assertion['type'] ?? 'none') !== 'none') {
                $e[] = 'expected.answer.class = observe exige assertion.type = none (le scenario MESURE)';
            }
        } elseif (in_array($class, self::ANSWER_CLASSES, true) && ($x['turn'] ?? null) === 'present') {
            $in('expected.answer.assertion.type', $assertion['type'] ?? null, array_diff(self::ASSERTION_TYPES, ['none']));
            if (! is_array($assertion['values'] ?? null) || ($assertion['values'] ?? []) === []) {
                $e[] = 'expected.answer.assertion.values : liste non vide obligatoire hors observe';
            }
        }

        if (($x['turn'] ?? null) === 'present') {
            $in('expected.execution_path', $x['execution_path'] ?? null, AiExecutionPath::all());
            if (! is_array($x['steps'] ?? null)) {
                $e[] = 'expected.steps manquant';
            } else {
                foreach ($x['steps'] as $step => $status) {
                    if (! in_array($step, AiTurnComparison::STEPS, true)) {
                        $e[] = "expected.steps.{$step} : etape hors des 8 emises [".implode('|', AiTurnComparison::STEPS).']';
                    }
                    $in("expected.steps.{$step}", $status, self::STEP_STATUSES);
                }
            }
        }

        foreach (($x['reason_code'] ?? []) as $step => $code) {
            if (! AiTurnReason::isKnown($code)) {
                $e[] = "expected.reason_code.{$step} = ".json_encode($code).' hors registre AiTurnReason';
            }
        }

        foreach (($x['history'] ?? []) as $turnKey => $derives) {
            if (! preg_match('/^turn_[2-9]$/', (string) $turnKey)) {
                $e[] = "expected.history.{$turnKey} : cle attendue turn_N (N ≥ 2)";
            }
            foreach ((array) $derives as $derive => $value) {
                if (! in_array(strtoupper((string) $derive), self::HISTORY_DERIVED, true)) {
                    $e[] = "expected.history.{$turnKey}.{$derive} : derive inconnu d'AiConversationTrace";
                }
                $in("expected.history.{$turnKey}.{$derive}", $value, self::HISTORY_VALUES);
            }
        }

        $s = $x['sources'] ?? [];
        foreach (['must_use_dossier'] as $c) {
            if (isset($s[$c]) && ! is_string($s[$c])) {
                $e[] = "expected.sources.{$c} : chaine attendue";
            }
        }
        foreach (['must_not_use', 'must_not_use_source_type'] as $c) {
            if (isset($s[$c]) && ! is_array($s[$c])) {
                $e[] = "expected.sources.{$c} : liste attendue";
            }
        }
        foreach (($s['must_not_use_source_type'] ?? []) as $type) {
            $in('expected.sources.must_not_use_source_type[]', $type, self::SOURCE_TYPES);
        }

        if (isset($x['tenant']) && ! is_string($x['tenant']['must_not_leak'] ?? null)) {
            $e[] = 'expected.tenant.must_not_leak : chaine attendue';
        }

        return $e;
    }

    // ────────────────────────────── accesseurs

    public function organization(): string
    {
        return $this->data['organization'];
    }

    public function loop(): string
    {
        return $this->data['loop'];
    }

    public function user(): string
    {
        return $this->data['user'];
    }

    /** @return list<array{order: int, surface: string, mode: string, question: string, reply_to: ?string}> */
    public function turns(): array
    {
        return array_values($this->data['turns']);
    }

    public function execution(): string
    {
        return $this->data['execution'];
    }

    /** @return array<string, mixed> */
    public function preconditions(): array
    {
        return $this->data['preconditions'];
    }

    /** @return array<string, mixed> */
    public function expected(): array
    {
        return $this->data['expected'];
    }

    public function answerClass(): string
    {
        return $this->data['expected']['answer']['class'];
    }

    public function expectsTurn(): bool
    {
        return $this->data['expected']['turn'] === 'present';
    }

    public function publishes(): bool
    {
        // Un scenario a plusieurs tours en reply exige la publication du tour
        // precedent (le tour 2 REPOND a la bulle du tour 1) — Lab seulement,
        // par `executeForLab` (L-C_CORE, Option B).
        foreach ($this->turns() as $t) {
            if ($t['reply_to'] !== null) {
                return true;
            }
        }

        return false;
    }
}
