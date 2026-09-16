<?php

namespace App\Support\AiLab;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Ai\AiConversationTrace;
use App\Support\Ai\AiRunManifest;
use App\Support\Ai\AiTurnComparison;
use App\Support\Ai\AiTurnExecutor;
use App\Support\Ai\AiTurnInspection;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnProjection;
use App\Support\Ai\AiTurnTrace;
use App\Support\ScenarioPacks\Packs\AiLabPack;
use Illuminate\Support\Str;

/**
 * TASK-1591 / CDC-NIGHT L-C_CORE — le RUNNER du Lab : un scenario, un run.
 *
 * Adaptateur INDEPENDANT (CDC-03 §6.1 — jamais `Artisan::call` comme API
 * interne) qui partage les supports : `LabPreconditions`, `AiTurnExecutor`
 * (`executeForLab`, Option B), `AiTurnInspection`, `AiTurnProjection`,
 * `AiConversationTrace`, `AiTurnComparison`, `AiRunManifest`, `LabVerdict`.
 *
 * Ce qu'il fait, dans l'ordre : resout Organization / utilisateur / Loop ;
 * etablit les preconditions PAR RAPPORT A L'ATTENDU ; ouvre un manifeste
 * `run_kind = lab` ; modelise la SURFACE (un utilisateur non membre n'entre
 * pas dans la Boucle : refus avant tout tour, `surface_authorization`) ; pour
 * chaque tour : publie le message humain (en reply de la bulle IA precedente
 * si `reply_to = previous_ai`), execute le VRAI service par `executeForLab`,
 * inspecte, projette ; puis confronte a `expected`.
 *
 * Le Lab MESURE : aucun seuil change, aucun correctif, aucune economie
 * speciale — le tour paie ce qu'un membre paierait.
 */
final class LabRunner
{
    public function __construct(
        private readonly AiTurnExecutor $executor,
        private readonly LabPreconditions $preconditions,
        private readonly LoopMessageService $messages,
    ) {}

    /** @return array<string, mixed> */
    public function run(LabScenario $scenario): array
    {
        $organization = Organization::query()->where('slug', $scenario->organization())->first();

        if (! $organization instanceof Organization) {
            return $this->unavailable($scenario, null, "Organization « {$scenario->organization()} » absente");
        }

        // L'outsider est, par contrat, un utilisateur d'une AUTRE Organization
        // (jamais une entite du pack) — DECLARE par la config, jamais devine ;
        // tout autre persona vit dans le Lab.
        $user = $scenario->user() === 'lab.outsider'
            ? User::query()->where('email', (string) config('scenario_packs.lab_outsider_email', AiLabPack::OUTSIDER_EMAIL))->first()
            : User::query()->where('organization_id', (string) $organization->id)->where('email', AiLabPack::emailFor($scenario->user()))->first();
        $loop = Loop::query()->where('organization_id', (string) $organization->id)->where('name', AiLabPack::LOOPS[$scenario->loop()]['name'] ?? '')->first();

        $pre = $this->preconditions->establish($scenario, $organization, $user, $loop);

        $runId = (string) Str::uuid();
        try {
            AiRunManifest::start($runId, AiTurnTrace::RUN_KIND_LAB, (string) $organization->id, $scenario->key);
        } catch (\RuntimeException $e) {
            return $this->unavailable($scenario, null, 'manifeste de run impossible : '.$e->getMessage(), $pre);
        }

        // Carte dossier -> cle de Loop sur TOUT le Lab : une source venue d'une
        // autre Loop est nommee (L4), pas seulement « pas L1 ».
        $loopDossiers = [];
        foreach (AiLabPack::LOOPS as $key => $definition) {
            $labLoop = Loop::query()->where('organization_id', (string) $organization->id)->where('name', $definition['name'])->first();
            if ($labLoop instanceof Loop) {
                foreach (Dossier::query()->withoutGlobalScopes()->where('loop_id', (string) $labLoop->id)->pluck('id') as $id) {
                    $loopDossiers[(string) $id] = $key;
                }
            }
        }

        $turns = [];

        if ($pre['verdict'] === LabPreconditions::YES && $user instanceof User && $loop instanceof Loop) {
            // La SURFACE : ce que la page LoopChat fait avant tout tour. Un
            // utilisateur qui n'est pas membre actif de la Boucle — ou d'une
            // autre Organization — n'y entre pas : refus, aucun message, aucun
            // tour. Meme lecture que la precondition `gold_access` ; la garde
            // du service et celle de l'executeur restent derriere.
            $admis = (string) $user->organization_id === (string) $organization->id
                && LoopMember::query()->where('loop_id', $loop->id)->where('user_id', $user->id)->where('status', 'active')->exists();

            if (! $admis) {
                $turns[] = ['order' => 1, 'refused' => true, 'refusal' => 'surface : utilisateur non admis dans cette Boucle', 'refused_before_run' => true, 'inspection' => null, 'projection' => null, 'history_derived' => null, 'response' => null, 'interaction_id' => null, 'message_id' => null, 'bubble_id' => null];
            } else {
                $turns = $this->executeTurns($scenario, $organization, $user, $loop, $runId);
            }
        } elseif ($pre['verdict'] === LabPreconditions::YES) {
            // Preconditions YES mais acteur/Boucle introuvables : un outsider
            // absent du banc, par exemple — non etablissable.
            $pre['verdict'] = LabPreconditions::UNAVAILABLE;
            $pre['checks']['actor'] = ['expected' => 'present', 'actual' => 'absent', 'status' => LabPreconditions::UNAVAILABLE, 'detail' => $user === null ? 'utilisateur introuvable' : 'Boucle introuvable'];
        }

        $verdict = LabVerdict::judge($scenario, $pre, $turns, $loopDossiers);

        $comparison = null;
        if (count($turns) >= 2 && ($turns[0]['inspection'] ?? null) !== null && ($turns[1]['inspection'] ?? null) !== null) {
            $comparison = AiTurnComparison::compare($turns[0]['inspection'], $turns[1]['inspection']);
        }

        return [
            'lab_scenario_key' => $scenario->key,
            'organization' => $scenario->organization(),
            'loop' => $scenario->loop(),
            'user' => $scenario->user(),
            'surface_mode' => implode('→', array_map(static fn (array $t): string => $t['surface'].'.'.$t['mode'], $scenario->turns())),
            'expected' => ['answer_class' => $scenario->answerClass(), 'turn' => $scenario->expected()['turn'], 'access' => $scenario->expected()['access']],
            'run_id' => $runId,
            'preconditions' => $pre,
            'PRECONDITIONS_MATCH_EXPECTED' => $pre['verdict'],
            'turns' => array_map(static fn (array $t): array => array_diff_key($t, ['inspection' => 1, 'projection' => 1]), $turns),
            'result' => $verdict['result'],
            'first_failed_component' => $verdict['first_failed_component'],
            'divergence_class' => $verdict['divergence_classes'],
            'divergences' => $verdict['divergences'],
            'leak' => $verdict['leak'],
            'unavailable_reason' => $verdict['unavailable_reason'],
            'comparison' => $comparison === null ? null : ['first_divergent_step' => $comparison['first_divergent_step'], 'divergence_classes' => $comparison['divergence_classes'] ?? null, 'identity_differences' => $comparison['identity_differences']],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function executeTurns(LabScenario $scenario, Organization $organization, User $user, Loop $loop, string $runId): array
    {
        $turns = [];
        $previousBubble = null;
        $previousUserMessage = null;

        foreach ($scenario->turns() as $spec) {
            AiTurnLock::forgetRequestState();

            $replyTo = match ($spec['reply_to']) {
                'previous_ai' => $previousBubble?->id,
                'previous_user' => $previousUserMessage?->id,
                default => null,
            };

            // Le message HUMAIN du tour, par la primitive canonique — publie
            // dans la Boucle du Lab (c'est le but du Lab) et marque.
            $message = $this->messages->sendUserMessage($loop, $user, $spec['question'], ['lab_scenario_key' => $scenario->key, 'lab_run_id' => $runId], $replyTo !== null ? (string) $replyTo : null);
            $previousUserMessage = $message;

            try {
                $execution = $this->executor->executeForLab($organization, $user, $loop, $spec['mode'], $spec['question'], $message, $runId, $scenario->key);
            } catch (\InvalidArgumentException $e) {
                $turns[] = ['order' => $spec['order'], 'refused' => true, 'refusal' => $e->getMessage(), 'refused_before_run' => true, 'inspection' => null, 'projection' => null, 'history_derived' => null, 'response' => null, 'interaction_id' => null, 'message_id' => (string) $message->id, 'bubble_id' => null];
                break;
            }

            $interaction = $execution->interaction;
            $inspection = $interaction !== null ? AiTurnInspection::fromPersistedTurn($interaction) : null;
            $projection = $interaction !== null ? AiTurnProjection::project($interaction) : null;
            $bubble = $execution->publishedMessage;
            $previousBubble = $bubble ?? $previousBubble;

            // Les derives d'historique (T1579), lus sur la bulle de CE tour.
            $derived = null;
            if ($bubble instanceof LoopMessage && $spec['order'] >= 2) {
                $trace = AiConversationTrace::fromLoopMessage($bubble, (string) $organization->id);
                foreach ($trace['turns'] ?? [] as $t) {
                    if (($t['message_id'] ?? null) === (string) $bubble->id) {
                        $derived = $t['derived'] ?? null;
                    }
                }
            }

            $turns[] = [
                'order' => $spec['order'],
                'refused' => $execution->refused(),
                'refusal' => $execution->refusalMessage,
                'refused_before_run' => false,
                'inspection' => $inspection,
                'projection' => $projection,
                'history_derived' => $derived,
                'response' => $interaction?->response,
                'interaction_id' => $interaction?->id !== null ? (string) $interaction->id : null,
                'turn_id' => $inspection['run']['turn_id'] ?? null,
                'status' => $inspection['decision']['status'] ?? null,
                'execution_path' => $inspection['identity']['execution_path'] ?? null,
                'message_id' => (string) $message->id,
                'bubble_id' => $bubble?->id !== null ? (string) $bubble->id : null,
                'manifest_failure' => $execution->manifestFailure,
            ];

            if ($execution->refused()) {
                break;
            }
        }

        return $turns;
    }

    /** @return array<string, mixed> */
    private function unavailable(LabScenario $scenario, ?string $runId, string $reason, ?array $pre = null): array
    {
        return [
            'lab_scenario_key' => $scenario->key,
            'organization' => $scenario->organization(),
            'loop' => $scenario->loop(),
            'user' => $scenario->user(),
            'surface_mode' => implode('→', array_map(static fn (array $t): string => $t['surface'].'.'.$t['mode'], $scenario->turns())),
            'expected' => ['answer_class' => $scenario->answerClass(), 'turn' => $scenario->expected()['turn'], 'access' => $scenario->expected()['access']],
            'run_id' => $runId,
            'preconditions' => $pre ?? ['verdict' => LabPreconditions::UNAVAILABLE, 'checks' => []],
            'PRECONDITIONS_MATCH_EXPECTED' => LabPreconditions::UNAVAILABLE,
            'turns' => [],
            'result' => LabVerdict::UNAVAILABLE,
            'first_failed_component' => LabVerdict::UNAVAILABLE,
            'divergence_class' => [],
            'divergences' => [],
            'leak' => false,
            'unavailable_reason' => $reason,
            'comparison' => null,
        ];
    }
}
