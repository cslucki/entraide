<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * TASK-1585 — le PLUS PETIT executeur partage CLI + web d'un tour IA observe.
 *
 * Extrait tel quel du mode EXECUTE de `ai:inspect-turn` (T1558/T1575/T1583) :
 * il ne fait que ce que la commande faisait deja, et la commande passe
 * desormais par lui. Aucun pipeline parallele — les VRAIS services produit,
 * avec leurs vraies gardes :
 *
 *   dossiers | ia_dossiers  -> `LoopKnowledgeAnswerService::answer/answerHybrid`
 *   ia                      -> `ChatLoopAiService::respondInThread` (declencheur OBLIGATOIRE)
 *
 * toujours en `publish: false` : verrou, idempotence, `AiEconomicGuard`, cle
 * de l'Organization, provider, ledger, `AiInteraction` — tout s'execute ;
 * seule la bulle `loop_messages` n'est pas ecrite. Aucune gratuite, aucun
 * cout invente, aucune regle economique touchee : ce que le membre aurait
 * paye, le test le paie.
 *
 * Tenant : l'Organization est revalidee ICI (utilisateur, Boucle, declencheur
 * dans l'Organization) en plus des services — un identifiant recu n'est pas
 * une autorisation. Le tenant est lie dans le conteneur le temps de
 * l'execution (`DossierPolicy::view` lit `current_organization`, mesure
 * T1558), puis restaure.
 *
 * Run (TRACE-1B) : chaque execution porte un run — celui donne par l'appelant
 * (serie CLI) ou un run d'UN tour cree ici. C'est par `turn.run.id` que le
 * tour est retrouve apres un REFUS (arret anticipe non generatif, V0-B) : le
 * service leve, mais le tour qu'il a ecrit existe et s'inspecte.
 */
final class AiTurnExecutor
{
    public const MODES = ['dossiers', 'ia_dossiers', 'ia'];

    public function __construct(
        private readonly LoopKnowledgeAnswerService $knowledge,
        private readonly ChatLoopAiService $chatLoop,
    ) {}

    /**
     * @param  string|null  $runId  run existant (serie CLI) ; `null` = run d'un tour, cree ici
     *
     * @throws \InvalidArgumentException entree invalide (mode, tenant, declencheur) — AVANT toute execution
     * @throws \RuntimeException manifeste corrompu ou run d'une autre Organization
     */
    public function execute(
        Organization $organization,
        User $user,
        Loop $loop,
        string $mode,
        string $question,
        ?LoopMessage $trigger = null,
        ?string $runId = null,
        string $runKind = AiTurnTrace::RUN_KIND_CLI,
    ): AiTurnExecution {
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Mode non reproductible : « {$mode} ». Disponible : ".implode(', ', self::MODES));
        }

        $question = trim($question);

        if ($question === '') {
            throw new \InvalidArgumentException('Aucune question.');
        }

        $orgId = (string) $organization->id;

        if ((string) $user->organization_id !== $orgId) {
            throw new \InvalidArgumentException('Utilisateur introuvable dans cette Organization.');
        }

        if ((string) $loop->organization_id !== $orgId) {
            throw new \InvalidArgumentException('Boucle introuvable dans cette Organization.');
        }

        if ($mode === 'ia') {
            if (! $trigger instanceof LoopMessage) {
                throw new \InvalidArgumentException('Le mode ia exige un message declencheur : le chemin IA repond toujours a un message du fil.');
            }

            if ((string) $trigger->organization_id !== $orgId || (string) $trigger->loop_id !== (string) $loop->id) {
                throw new \InvalidArgumentException('Message declencheur introuvable dans cette Boucle.');
            }
        }

        $runId ??= (string) Str::uuid();

        // Leve RuntimeException si le run appartient a une autre Organization
        // ou si son manifeste est corrompu — AVANT toute execution.
        AiRunManifest::start($runId, $runKind, $orgId);
        AiTurnTrace::beginRun($runId, $runKind);

        try {
            return $this->dansLeTenant($organization, function () use ($organization, $user, $loop, $mode, $question, $trigger, $runId, $runKind): AiTurnExecution {
                try {
                    if ($mode === 'ia') {
                        $interaction = $this->chatLoop->respondInThread($loop, $user, $question, $trigger, publish: false);

                        if (! $interaction instanceof AiInteraction) {
                            throw new \LogicException('Le seam publish:false devait rendre l\'AiInteraction du tour.');
                        }

                        $this->inscrireAuManifeste($runId, $interaction);

                        return new AiTurnExecution($mode, $runId, $runKind, $interaction->refresh(), null);
                    }

                    // TASK-1568 / V0-G — un tour observe EST un tour
                    // `loop_chat.*` : meme chemin produit, memes gardes.
                    $reponse = $mode === 'ia_dossiers'
                        ? $this->knowledge->answerHybrid($loop, $user, $question, null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_IA_DOSSIERS)
                        : $this->knowledge->answer($loop, $user, $question, null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);

                    $interaction = $reponse->interactionId === null
                        ? null
                        : AiInteraction::query()->where('organization_id', (string) $organization->id)->whereKey($reponse->interactionId)->first();

                    $this->inscrireAuManifeste($runId, $interaction);

                    return new AiTurnExecution($mode, $runId, $runKind, $interaction, $reponse);
                } catch (\RuntimeException $exception) {
                    // Un refus du service — ACL, economie, idempotence, panne —
                    // est un RESULTAT d'observation. Le tour ecrit par l'arret
                    // anticipe, s'il existe, est retrouve par son run.
                    $interaction = $this->tourDuRun($organization, $runId);
                    $this->inscrireAuManifeste($runId, $interaction);

                    return new AiTurnExecution($mode, $runId, $runKind, $interaction, null, $exception->getMessage(), $exception::class);
                }
            });
        } finally {
            AiTurnTrace::endRun();
        }
    }

    /**
     * Les Boucles ou l'utilisateur est membre ACTIF, dans son Organization —
     * la condition que `LoopKnowledgeAnswerService`/`ChatLoopAiService`
     * imposent au requerant. Sert aux appelants a proposer un choix honnete,
     * jamais a autoriser : les services revalident.
     *
     * @return Collection<int, Loop>
     */
    public static function accessibleLoops(Organization $organization, User $user): Collection
    {
        if ((string) $user->organization_id !== (string) $organization->id) {
            return collect();
        }

        $loopIds = LoopMember::query()
            ->where('organization_id', (string) $organization->id)
            ->where('user_id', (string) $user->id)
            ->where('status', 'active')
            ->pluck('loop_id');

        return Loop::query()
            ->where('organization_id', (string) $organization->id)
            ->whereIn('id', $loopIds)
            ->orderBy('name')
            ->get(['id', 'name', 'organization_id']);
    }

    private function tourDuRun(Organization $organization, string $runId): ?AiInteraction
    {
        return AiInteraction::query()
            ->where('organization_id', (string) $organization->id)
            ->where('metadata->'.AiTurnTrace::TURN_METADATA_KEY.'->run->id', $runId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /** TRACE-1B — des ids, rien d'autre. */
    private function inscrireAuManifeste(string $runId, ?AiInteraction $interaction): void
    {
        if ($interaction === null) {
            return;
        }

        $turn = is_array($interaction->metadata) ? ($interaction->metadata[AiTurnTrace::TURN_METADATA_KEY] ?? null) : null;

        AiRunManifest::addTurn($runId, [
            'turn_id' => is_array($turn) ? ($turn['id'] ?? null) : null,
            'interaction_id' => (string) $interaction->id,
        ]);
    }

    /**
     * TASK-1558 — SANS cette liaison, l'observation ment : `DossierPolicy::view`
     * lit `current_organization` dans le conteneur ; hors requete HTTP, rien
     * ne le lie et le perimetre documentaire tombe a zero. Poser, puis
     * restaurer — idiome de `DossierFileIndexer`.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function dansLeTenant(Organization $organization, callable $callback): mixed
    {
        $avait = app()->bound('current_organization');
        $precedent = $avait ? app('current_organization') : null;

        app()->instance('current_organization', $organization);

        try {
            return $callback();
        } finally {
            if ($avait) {
                app()->instance('current_organization', $precedent);
            } else {
                app()->forgetInstance('current_organization');
            }
        }
    }
}
