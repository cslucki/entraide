<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Loops\LoopDossierAnswerService;
use App\Support\AiLab\LabScenario;
use App\Support\ScenarioPacks\Packs\AiLabPack;
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
 *   dossiers                -> `LoopDossierAnswerService::answer` (TASK-1595)
 *   ia_dossiers             -> `LoopKnowledgeAnswerService::answerHybrid`
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
        private readonly LoopDossierAnswerService $dossierAnswers,
    ) {}

    /**
     * Execution OBSERVEE : structurellement `publish: false` — aucune bulle
     * dans la Boucle, jamais. C'est l'entree de l'Inspector et de
     * `ai:inspect-turn`.
     *
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
        $this->valider($organization, $user, $loop, $mode, $question, $trigger);

        return $this->pipeline($organization, $user, $loop, $mode, trim($question), $trigger, $runId ?? (string) Str::uuid(), $runKind, null, false);
    }

    /**
     * TASK-1591 / CDC-NIGHT L-C_CORE — OPTION B (decision MASTER) : la SEULE
     * entree qui publie, reservee au Lab. Le tour 2 d'un scenario multi-turn
     * doit pouvoir REPONDRE a la bulle du tour 1 : il faut donc que le tour 1
     * ait ete publie — dans l'Organization `ai-lab`, et nulle part ailleurs.
     *
     * Conditions cumulatives, verifiees AVANT toute execution ; toute autre
     * Organization = REFUS DUR :
     *   1. `organization.slug === AiLabPack::ORGANIZATION_SLUG` ;
     *   2. ce slug est dans `config('scenario_packs.allowed_organizations')` ;
     *   3. le pack `ai-lab` est REELLEMENT charge (`scenario_pack_loads`) ;
     *   4. `run_kind = lab` (impose ici, pas un parametre).
     *
     * Meme pipeline interne que `execute()` — aucune duplication ; aucun
     * booleen `publish` public. Garde d'architecture : seule cette methode
     * passe `publish: true` a un seam, et seul le runner Lab l'appelle.
     *
     * @throws \InvalidArgumentException refus dur (hors Lab) ou entree invalide
     */
    public function executeForLab(
        Organization $organization,
        User $user,
        Loop $loop,
        string $mode,
        string $question,
        LoopMessage $trigger,
        string $runId,
        string $labScenarioKey,
    ): AiTurnExecution {
        if ($organization->slug !== AiLabPack::ORGANIZATION_SLUG) {
            throw new \InvalidArgumentException("REFUS : la publication est reservee a l'Organization Lab « ".AiLabPack::ORGANIZATION_SLUG." » (recu « {$organization->slug} »).");
        }

        if (! in_array(AiLabPack::ORGANIZATION_SLUG, (array) config('scenario_packs.allowed_organizations', []), true)) {
            throw new \InvalidArgumentException('REFUS : « '.AiLabPack::ORGANIZATION_SLUG." » n'est pas dans l'allowlist des scenario packs.");
        }

        $chargee = ScenarioPackLoad::query()
            ->where('pack_id', AiLabPack::PACK_ID)
            ->where('organization_id', (string) $organization->id)
            ->exists();

        if (! $chargee) {
            throw new \InvalidArgumentException('REFUS : le pack « '.AiLabPack::PACK_ID." » n'est pas charge dans cette Organization.");
        }

        if (! preg_match(LabScenario::KEY_PATTERN, $labScenarioKey)) {
            throw new \InvalidArgumentException("lab_scenario_key invalide : {$labScenarioKey}");
        }

        $this->valider($organization, $user, $loop, $mode, $question, $trigger);

        return $this->pipeline($organization, $user, $loop, $mode, trim($question), $trigger, $runId, AiTurnTrace::RUN_KIND_LAB, $labScenarioKey, true);
    }

    /** Les validations communes — AVANT tout run, tout service. */
    private function valider(Organization $organization, User $user, Loop $loop, string $mode, string $question, ?LoopMessage $trigger): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Mode non reproductible : « {$mode} ». Disponible : ".implode(', ', self::MODES));
        }

        if (trim($question) === '') {
            throw new \InvalidArgumentException('Aucune question.');
        }

        $orgId = (string) $organization->id;

        if ((string) $user->organization_id !== $orgId) {
            throw new \InvalidArgumentException('Utilisateur introuvable dans cette Organization.');
        }

        if ((string) $loop->organization_id !== $orgId) {
            throw new \InvalidArgumentException('Boucle introuvable dans cette Organization.');
        }

        if ($mode === 'ia' && ! $trigger instanceof LoopMessage) {
            throw new \InvalidArgumentException('Le mode ia exige un message declencheur : le chemin IA repond toujours a un message du fil.');
        }

        if ($trigger instanceof LoopMessage && ((string) $trigger->organization_id !== $orgId || (string) $trigger->loop_id !== (string) $loop->id)) {
            throw new \InvalidArgumentException('Message declencheur introuvable dans cette Boucle.');
        }
    }

    /**
     * Le pipeline UNIQUE : run, tenant, vrais services, refus = resultat,
     * manifeste. `$publish` est PRIVE : `execute()` passe toujours `false`,
     * `executeForLab()` toujours `true` — il n'existe aucune autre voie.
     */
    private function pipeline(
        Organization $organization,
        User $user,
        Loop $loop,
        string $mode,
        string $question,
        ?LoopMessage $trigger,
        string $runId,
        string $runKind,
        ?string $labScenarioKey,
        bool $publish,
    ): AiTurnExecution {
        $orgId = (string) $organization->id;

        // Leve RuntimeException si le run appartient a une autre Organization
        // ou si son manifeste est corrompu/inaccessible — AVANT toute execution.
        AiRunManifest::start($runId, $runKind, $orgId, $labScenarioKey);
        AiTurnTrace::beginRun($runId, $runKind, $labScenarioKey);
        // Review Opus F7 — les ids d'interaction que le run portait DEJA :
        // un refus qui n'ecrit rien (verrou, idempotence) ne doit jamais
        // faire remonter le tour d'une invocation precedente de la serie.
        $dejaConnus = array_map(static fn ($id): string => (string) $id, $this->idsDuRun($organization, $runId));

        try {
            return $this->dansLeTenant($organization, function () use ($organization, $user, $loop, $mode, $question, $trigger, $runId, $runKind, $dejaConnus, $publish): AiTurnExecution {
                try {
                    if ($mode === 'ia') {
                        $resultat = $this->chatLoop->respondInThread($loop, $user, $question, $trigger, publish: $publish);

                        // publish:false rend l'AiInteraction ; publish:true rend la
                        // BULLE, dont le lien `metadata.ai_interaction_id` (existant,
                        // ecrit par le service) designe le tour — jamais une
                        // heuristique texte/temps.
                        [$interaction, $bulle] = $resultat instanceof AiInteraction
                            ? [$resultat, null]
                            : [$this->interactionDeLaBulle($organization, $resultat), $resultat];

                        if (! $interaction instanceof AiInteraction) {
                            throw new \LogicException('Le seam devait rendre l\'AiInteraction du tour (directement, ou par metadata.ai_interaction_id de la bulle).');
                        }

                        $manifeste = $this->inscrireAuManifeste($runId, $interaction, $bulle);

                        return new AiTurnExecution($mode, $runId, $runKind, $interaction->refresh(), null, manifestFailure: $manifeste, publishedMessage: $bulle);
                    }

                    // TASK-1568 / V0-G — un tour observe EST un tour
                    // `loop_chat.*` : meme chemin produit, memes gardes. Hors
                    // publication, aucun declencheur n'est transmis (T1558) ; en
                    // Lab, le message humain publie EST le declencheur.
                    $inThread = $publish ? $trigger : null;
                    // TASK-1595 — le mode `dossiers` passe desormais par
                    // `LoopDossierAnswerService` (moteur documentaire canonique
                    // sur le Dossier racine), comme le composeur. L'Inspector et
                    // le Lab doivent observer L'IMPLEMENTATION DU PRODUIT :
                    // laisser ce seam sur l'ancien service aurait produit une
                    // mesure plausible et fausse, exactement ce que CDC-01
                    // interdit. Le mode `ia_dossiers` reste inchange.
                    $reponse = $mode === 'ia_dossiers'
                        ? $this->knowledge->answerHybrid($loop, $user, $question, $inThread, publish: $publish, executionPath: AiExecutionPath::LOOP_CHAT_IA_DOSSIERS)
                        : $this->dossierAnswers->answer($loop, $user, $question, $inThread, publish: $publish);

                    // TASK-1595 — un tour qui S'ABSTIENT ne porte pas d'id
                    // d'interaction dans son DTO : `interactionId: null` est le
                    // signal PRODUIT que rien n'a ete genere, et `LoopChat` le
                    // lit pour prevenir l'auteur. Il a pourtant laisse un tour.
                    //
                    // On le retrouve alors par son RUN, exactement comme le
                    // `catch` ci-dessous retrouve celui d'un refus : meme
                    // mecanisme, meme borne tenant, aucune seconde regle.
                    $interaction = $reponse->interactionId === null
                        ? $this->tourDuRun($organization, $runId, $dejaConnus)
                        : AiInteraction::query()->where('organization_id', (string) $organization->id)->whereKey($reponse->interactionId)->first();

                    // La bulle publiee est retrouvee par le MEME lien existant.
                    $bulle = $publish && $interaction !== null
                        ? LoopMessage::query()->where('organization_id', (string) $organization->id)->where('loop_id', (string) $loop->id)->where('type', 'ai')->where('metadata->ai_interaction_id', (string) $interaction->id)->first()
                        : null;

                    $manifeste = $this->inscrireAuManifeste($runId, $interaction, $bulle);

                    return new AiTurnExecution($mode, $runId, $runKind, $interaction, $reponse, manifestFailure: $manifeste, publishedMessage: $bulle);
                } catch (\RuntimeException $exception) {
                    // Un refus du service — ACL, economie, idempotence, panne —
                    // est un RESULTAT d'observation. Le tour ecrit par l'arret
                    // anticipe, s'il existe, est retrouve par son run.
                    $interaction = $this->tourDuRun($organization, $runId, $dejaConnus);
                    $manifeste = $this->inscrireAuManifeste($runId, $interaction);

                    return new AiTurnExecution($mode, $runId, $runKind, $interaction, null, $exception->getMessage(), $exception::class, $manifeste);
                }
            });
        } finally {
            AiTurnTrace::endRun();
        }
    }

    private function interactionDeLaBulle(Organization $organization, LoopMessage $bulle): ?AiInteraction
    {
        $id = is_array($bulle->metadata) ? ($bulle->metadata['ai_interaction_id'] ?? null) : null;

        return is_string($id) && Str::isUuid($id)
            ? AiInteraction::query()->where('organization_id', (string) $organization->id)->whereKey($id)->first()
            : null;
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

    /**
     * Le tour que CETTE execution a ecrit sous le run — jamais un tour deja
     * connu du run avant elle.
     *
     * @param  list<string>  $dejaConnus
     */
    private function tourDuRun(Organization $organization, string $runId, array $dejaConnus): ?AiInteraction
    {
        return AiInteraction::query()
            ->where('organization_id', (string) $organization->id)
            ->where('metadata->'.AiTurnTrace::TURN_METADATA_KEY.'->run->id', $runId)
            ->when($dejaConnus !== [], static fn ($q) => $q->whereNotIn('id', $dejaConnus))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return list<string> */
    private function idsDuRun(Organization $organization, string $runId): array
    {
        return AiInteraction::query()
            ->where('organization_id', (string) $organization->id)
            ->where('metadata->'.AiTurnTrace::TURN_METADATA_KEY.'->run->id', $runId)
            ->pluck('id')
            ->all();
    }

    /**
     * TRACE-1B — des ids, rien d'autre. Rend le message d'echec si le
     * manifeste n'a pas pu etre complete APRES le tour (review Opus F2) : le
     * tour existe et se lit, l'echec d'inscription est porte par le resultat,
     * jamais transforme en « rien n'est parti ».
     */
    private function inscrireAuManifeste(string $runId, ?AiInteraction $interaction, ?LoopMessage $bulle = null): ?string
    {
        if ($interaction === null) {
            return null;
        }

        $turn = is_array($interaction->metadata) ? ($interaction->metadata[AiTurnTrace::TURN_METADATA_KEY] ?? null) : null;

        try {
            AiRunManifest::addTurn($runId, [
                'turn_id' => is_array($turn) ? ($turn['id'] ?? null) : null,
                'interaction_id' => (string) $interaction->id,
                // TASK-1591 — un tour PUBLIE (Lab) inscrit sa bulle : ids seulement.
                'loop_message_id' => $bulle?->id !== null ? (string) $bulle->id : null,
            ]);
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
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
