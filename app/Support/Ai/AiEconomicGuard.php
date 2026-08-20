<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Ai\OrganizationAiEconomicUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

final class AiEconomicGuard
{
    public const REASON_MONTHLY_BUDGET_REACHED = 'monthly_budget_reached';

    public const REASON_UNKNOWN_QUOTA_REACHED = 'unknown_quota_reached';

    /**
     * TASK-1260 (G11-b) : les process dont l'autorite GENERATION de cette
     * garde est le ledger canonique `ai_provider_invocations` a partir de
     * `LEDGER_AUTHORITY_SINCE`. Liste FERMEE — exactement le perimetre dont
     * TASK-1259 (G11-a) a prouve la parite exacte registre/ledger par
     * `correlation_id` : capabilities canoniques + Blog/Explorer. Le bac a
     * sable de doctrine reutilise le process de la capability essayee
     * (distingue par `feature = ai_doctrine_sandbox`) : aucune valeur a lui.
     *
     * Ce filtre n'est pas une optimisation : le ledger recoit une ligne de
     * TOUTES les familles d'appel (y compris agent de profil / offre de
     * service / bancs SuperAdmin, hors perimetre, dont la trace
     * operationnelle est `admin_ai_interactions`), la ou `ai_interactions`
     * ne recevait structurellement que le perimetre garde. Sans lui, le
     * plafond Organization se mettrait a compter des familles que leur
     * propre bascule (TASK dediee future) n'a pas encore migrees.
     */
    public const LEDGER_AUTHORITY_PROCESSES = [
        'chatloop.summarize',
        'chatloop.answer',
        'chatloop.ask',
        'help_request.clarify',
        'loop_knowledge.answer',
        'blog.article_generate',
        'blog.article_correct',
        'blog.method_selection',
        'blog.explorer_dialogue',
        'blog.explorer_note',
    ];

    /**
     * TASK-1260 : instant de bascule d'autorite generation, en UTC. Decision
     * produit FIGEE — jamais deduite d'un MIN(created_at) : le ledger est ne
     * le 17/08 a 14:53, le premier minuit UTC entierement couvert est retenu
     * (17/08 00:00 perdrait les traces du 17/08 matin, anterieures a la
     * premiere ecriture ledger). Avant cet instant : `ai_interactions`
     * (requete historique inchangee). A partir de cet instant, inclus :
     * le ledger, borne a `LEDGER_AUTHORITY_PROCESSES`. Les deux fenetres ne
     * se chevauchent jamais ; les lignes ecrites dans les deux tables entre
     * la naissance du ledger et le cutover ne sont lues que cote registre.
     */
    public const LEDGER_AUTHORITY_SINCE = '2026-08-18T00:00:00+00:00';

    /**
     * TASK-1212 : plafond mensuel porte par l'Organization elle-meme, toutes
     * capabilities confondues. Verifie AVANT le plafond par process.
     *
     * TASK-1222 : ce plafond couvre generation + embeddings, en additionnant
     * DEUX registres SANS overlap (aucun embedding n'est ecrit dans
     * `ai_interactions`). TASK-1260 : la part generation est lue depuis
     * `ai_interactions` avant `LEDGER_AUTHORITY_SINCE` et depuis le ledger
     * canonique (bornee a `LEDGER_AUTHORITY_PROCESSES`) a partir de cet
     * instant — fenetres disjointes, une generation moderne presente dans
     * les deux registres ne compte toujours qu'une fois.
     */
    public const REASON_ORGANIZATION_BUDGET_REACHED = 'organization_monthly_budget_reached';

    /**
     * TASK-1222 : trop d'invocations embeddings au cout INCONNU ce mois-ci.
     * `unknown` n'est pas 0 — et il n'est pas non plus un droit illimite.
     */
    public const REASON_EMBEDDING_UNKNOWN_QUOTA_REACHED = 'embedding_unknown_quota_reached';

    /**
     * TASK-1229 : le CREDIT IA du mois de l'UTILISATEUR est epuise. Troisieme
     * notion, distincte du budget Organization (`organization_monthly_budget_
     * reached`, une depense reelle en monnaie) et du credential absent (refus
     * du resolveur, avant meme cette garde) : un utilisateur peut etre bloque
     * par son credit alors que l'Organization a du budget, et l'inverse.
     * Compte en UTILISATIONS, jamais en dollars.
     */
    public const REASON_USER_CREDIT_EXHAUSTED = 'user_monthly_credit_exhausted';

    /**
     * Dependances resolues paresseusement : la garde reste constructible sans
     * argument (`new AiEconomicGuard`), comme avant TASK-1229.
     */
    public function __construct(
        private ?AiUserCreditSettings $creditSettings = null,
        private ?OrganizationAiEconomicUsage $usage = null,
    ) {}

    /**
     * @param  User|null  $user  TASK-1229 : l'utilisateur dont le CREDIT est
     *                           evalue. NULL = aucun credit applique (bac a
     *                           sable de doctrine, ingestion, traitements sans
     *                           utilisateur) — jamais un contournement pour un
     *                           chemin utilisateur.
     */
    public function authorize(
        Organization $organization,
        string $process,
        string $provider,
        string $model,
        float $monthlyBudgetUsd,
        int $monthlyUnknownLimit,
        ?User $user = null,
    ): AiEconomicVerdict {
        $monthStart = now()->startOfMonth();
        $nextMonthStart = $monthStart->copy()->addMonth();

        // Evalue une fois, rendu sur tous les verdicts (alerte de seuil
        // comprise) ; applique en DERNIER : quand l'Organization elle-meme ne
        // peut plus travailler, le message parle de l'Organization, jamais du
        // credit personnel — s'abonner n'y changerait rien.
        $credit = $user !== null ? $this->userCreditStatus($organization, $user, $monthStart, $nextMonthStart) : null;

        $organizationBudget = $organization->aiSetting?->monthly_budget_usd;

        if ($organizationBudget !== null) {
            $organizationMonthlyCost = $this->organizationMonthlyKnownCost($organization, $monthStart, $nextMonthStart);

            if ($organizationMonthlyCost >= (float) $organizationBudget) {
                return AiEconomicVerdict::refuse(
                    self::REASON_ORGANIZATION_BUDGET_REACHED,
                    $organizationMonthlyCost,
                    0,
                    AiPricingCatalog::hasRate($provider, $model),
                    $credit,
                );
            }
        }

        [$knownMonthlyCost, $successfulUnknownCount] = $this->processMonthlyUsage(
            $organization,
            $process,
            $monthStart,
            $nextMonthStart,
        );

        $pricingKnown = AiPricingCatalog::hasRate($provider, $model);

        if ($knownMonthlyCost >= $monthlyBudgetUsd) {
            return AiEconomicVerdict::refuse(
                self::REASON_MONTHLY_BUDGET_REACHED,
                $knownMonthlyCost,
                $successfulUnknownCount,
                $pricingKnown,
                $credit,
            );
        }

        if ($successfulUnknownCount >= $monthlyUnknownLimit) {
            return AiEconomicVerdict::refuse(
                self::REASON_UNKNOWN_QUOTA_REACHED,
                $knownMonthlyCost,
                $successfulUnknownCount,
                $pricingKnown,
                $credit,
            );
        }

        // TASK-1229 : le credit de l'utilisateur, en dernier. Un refus ici
        // n'ecrit rien : ni trace, ni ligne de ledger, ni utilisation
        // decomptee — un appel qui n'est pas parti n'est pas une utilisation.
        if ($credit !== null && $credit->isExhausted()) {
            return AiEconomicVerdict::refuse(
                self::REASON_USER_CREDIT_EXHAUSTED,
                $knownMonthlyCost,
                $successfulUnknownCount,
                $pricingKnown,
                $credit,
            );
        }

        return AiEconomicVerdict::allow($knownMonthlyCost, $successfulUnknownCount, $pricingKnown, $credit);
    }

    /**
     * Garde economique d'une INGESTION d'embeddings (TASK-1222), a executer
     * AVANT l'appel provider. Deux verrous seulement :
     *
     *  1. le plafond mensuel de l'Organization (generation gardee +
     *     embeddings connus, memes registres et meme fenetre que
     *     `authorize()`) ;
     *  2. le quota mensuel d'invocations embeddings au cout INCONNU.
     *
     * Un refus n'ecrit RIEN : ni trace, ni ligne de ledger — un appel qui
     * n'est pas parti n'est pas une consommation. Et un refus budgetaire ne
     * detruit pas l'index existant : l'appelant conserve les chunks en place
     * (contrainte temporaire de budget != credential disparu).
     */
    public function authorizeEmbeddings(Organization $organization, ?User $user = null): AiEconomicVerdict
    {
        $monthStart = now()->startOfMonth();
        $nextMonthStart = $monthStart->copy()->addMonth();

        // TASK-1229 : credit de l'utilisateur (recherche documentaire
        // declenchee par un membre) — jamais pour l'ingestion, qui est une
        // maintenance de la base de connaissances de l'Organization.
        $credit = $user !== null ? $this->userCreditStatus($organization, $user, $monthStart, $nextMonthStart) : null;

        $knownCost = $this->organizationMonthlyKnownCost($organization, $monthStart, $nextMonthStart);

        // Comme le quota historique par process : seuls les appels REUSSIS au
        // cout non mesurable comptent. Un echec provider a `cost_status
        // unknown` aussi, mais une panne (multipliee par les retries de job)
        // ne doit pas fermer l'ingestion du mois — l'echec a son propre
        // compteur, ailleurs.
        $unknownCount = AiProviderInvocation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->where('status', AiProviderInvocation::STATUS_SUCCESS)
            ->where('cost_status', AiProviderInvocation::COST_UNKNOWN)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->count();

        // Verite du diagnostic : le tarif de la famille d'embedding configuree
        // est-il reellement au catalogue ? (Jamais un `true` de complaisance.)
        $family = trim((string) config('ai.default_for_embeddings', 'openai'));
        $model = trim((string) config("ai.providers.{$family}.models.embeddings.default", ''));
        $pricingKnown = AiPricingCatalog::hasRate($family, $model);

        $organizationBudget = $organization->aiSetting?->monthly_budget_usd;

        if ($organizationBudget !== null && $knownCost >= (float) $organizationBudget) {
            return AiEconomicVerdict::refuse(
                self::REASON_ORGANIZATION_BUDGET_REACHED,
                $knownCost,
                $unknownCount,
                $pricingKnown,
                $credit,
            );
        }

        $unknownLimit = (int) config('ai.embeddings.economic_guard.monthly_unknown_limit', 50);

        // Une limite absente, vide ou invalide ne signifie jamais « zero
        // appel autorise » : on retombe sur le defaut.
        if ($unknownLimit <= 0) {
            $unknownLimit = 50;
        }

        if ($unknownCount >= $unknownLimit) {
            return AiEconomicVerdict::refuse(
                self::REASON_EMBEDDING_UNKNOWN_QUOTA_REACHED,
                $knownCost,
                $unknownCount,
                $pricingKnown,
                $credit,
            );
        }

        if ($credit !== null && $credit->isExhausted()) {
            return AiEconomicVerdict::refuse(
                self::REASON_USER_CREDIT_EXHAUSTED,
                $knownCost,
                $unknownCount,
                $pricingKnown,
                $credit,
            );
        }

        return AiEconomicVerdict::allow($knownCost, $unknownCount, $pricingKnown, $credit);
    }

    /**
     * TASK-1229 : etat du CREDIT IA d'un utilisateur dans une Organization —
     * politique effective (cascade plateforme -> override Organization) +
     * utilisations deja emises sur la fenetre du budget (mois UTC, la MEME
     * que `authorize()`). Une seule definition, lue par la garde pour
     * bloquer et par les ecrans pour afficher : le chiffre montre est celui
     * qui bloque.
     *
     * Le compte vient de l'autorite 1228 (`OrganizationAiEconomicUsage`) :
     * generations hors essais de doctrine + recherches documentaires,
     * attribuees a l'utilisateur, tenant-safe. Jamais un log, jamais une
     * absence de ligne.
     */
    public function userCreditStatus(
        Organization $organization,
        User $user,
        ?Carbon $monthStart = null,
        ?Carbon $nextMonthStart = null,
    ): AiUserCreditStatus {
        $monthStart ??= now()->startOfMonth();
        $nextMonthStart ??= $monthStart->copy()->addMonth();

        $from = CarbonImmutable::instance($monthStart);
        $to = CarbonImmutable::instance($nextMonthStart);

        $policy = $this->creditSettings()->policyFor($organization);

        // Illimite : aucun compte a tenir n'est necessaire pour decider, mais
        // l'ecran montre quand meme ce qui a ete utilise — une seule lecture.
        $used = $this->usage()->userCreditUses((string) $organization->id, $from, $to, (string) $user->id);

        return new AiUserCreditStatus($policy, $used, $from, $to);
    }

    private function creditSettings(): AiUserCreditSettings
    {
        return $this->creditSettings ??= app(AiUserCreditSettings::class);
    }

    private function usage(): OrganizationAiEconomicUsage
    {
        return $this->usage ??= app(OrganizationAiEconomicUsage::class);
    }

    /**
     * Cout mensuel CONNU de l'Organization : generations gardees +
     * embeddings du ledger canonique. Zero double comptage.
     *
     * TASK-1260 (G11-b) : la part GENERATION est lue en deux fenetres
     * disjointes — `ai_interactions` (requete historique, sans filtre
     * process : cette table ne recoit structurellement que le perimetre
     * garde, prouve par G11-a) avant `LEDGER_AUTHORITY_SINCE`, puis le
     * ledger canonique, borne a `LEDGER_AUTHORITY_PROCESSES`, a partir de
     * cet instant. Aucun backfill : le total du mois de transition est la
     * somme exacte des deux fenetres. La part EMBEDDING ne subit pas le
     * cutover : elle etait deja lue depuis le ledger sur tout le mois
     * (TASK-1222), elle le reste.
     */
    private function organizationMonthlyKnownCost(
        Organization $organization,
        Carbon $monthStart,
        Carbon $nextMonthStart,
    ): float {
        $cutover = $this->ledgerAuthorityCutover($monthStart, $nextMonthStart);

        $legacyGenerationKnown = (float) AiInteraction::query()
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $cutover)
            ->where('cost_unknown', false)
            ->sum('cost_usd');

        $ledgerGenerationKnown = (float) AiProviderInvocation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', AiProviderInvocation::OPERATION_GENERATION)
            ->whereIn('process', self::LEDGER_AUTHORITY_PROCESSES)
            ->where('cost_status', AiProviderInvocation::COST_KNOWN)
            ->where('created_at', '>=', $cutover)
            ->where('created_at', '<', $nextMonthStart)
            ->sum('provider_cost');

        $embeddingKnown = (float) AiProviderInvocation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->where('cost_status', AiProviderInvocation::COST_KNOWN)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->sum('provider_cost');

        return $legacyGenerationKnown + $ledgerGenerationKnown + $embeddingKnown;
    }

    /**
     * Usage mensuel d'un PROCESS : cout connu (somme) et inconnus reussis
     * (compte d'operations), pour le verrou par process de `authorize()`.
     *
     * TASK-1260 (G11-b) — deux autorites, choisies par la liste fermee,
     * jamais devinees :
     *
     *  - process de `LEDGER_AUTHORITY_PROCESSES` : fenetres de cutover
     *    disjointes, `ai_interactions` avant `LEDGER_AUTHORITY_SINCE`,
     *    ledger canonique ensuite. Cote ledger, le cout connu reste une
     *    SOMME BRUTE (une retry reellement facturee est une depense reelle
     *    de plus), mais le quota d'inconnus est un compte d'OPERATIONS
     *    (`COUNT(DISTINCT COALESCE(correlation_id, id))`) : le ledger ecrit
     *    une ligne PAR TENTATIVE provider, une operation retentee ne
     *    consomme pas le quota deux fois ; une ligne sans correlation est
     *    sa propre operation, jamais ignoree. Seuls les appels REUSSIS
     *    comptent (`status = success`, la regle du quota embeddings) : dans
     *    le registre, un echec portait `cost_unknown = NULL` et n'entrait
     *    nulle part — au ledger il porte `cost_status = unknown`, le filtre
     *    de statut est donc obligatoire pour ne pas fermer un process a
     *    cause d'une panne.
     *
     *  - tout autre process (familles heritees `SupervisionEconomicAuthority`,
     *    bancs SuperAdmin) : lecture historique `ai_interactions` INCHANGEE
     *    sur tout le mois, jusqu'a leur TASK de bascule dediee.
     *
     * @return array{0: float, 1: int}
     */
    private function processMonthlyUsage(
        Organization $organization,
        string $process,
        Carbon $monthStart,
        Carbon $nextMonthStart,
    ): array {
        $legacyWindowEnd = in_array($process, self::LEDGER_AUTHORITY_PROCESSES, true)
            ? $this->ledgerAuthorityCutover($monthStart, $nextMonthStart)
            : $nextMonthStart;

        $legacy = AiInteraction::query()
            ->where('organization_id', $organization->id)
            ->where('process', $process)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $legacyWindowEnd);

        $knownCost = (float) (clone $legacy)
            ->where('cost_unknown', false)
            ->sum('cost_usd');

        $unknownOperations = (clone $legacy)
            ->where('cost_unknown', true)
            ->count();

        if ($legacyWindowEnd->lessThan($nextMonthStart)) {
            $ledger = AiProviderInvocation::query()
                ->where('organization_id', $organization->id)
                ->where('operation', AiProviderInvocation::OPERATION_GENERATION)
                ->where('process', $process)
                ->where('created_at', '>=', $legacyWindowEnd)
                ->where('created_at', '<', $nextMonthStart);

            $knownCost += (float) (clone $ledger)
                ->where('cost_status', AiProviderInvocation::COST_KNOWN)
                ->sum('provider_cost');

            $unknownRow = (clone $ledger)
                ->where('status', AiProviderInvocation::STATUS_SUCCESS)
                ->where('cost_status', AiProviderInvocation::COST_UNKNOWN)
                ->selectRaw('COUNT(DISTINCT COALESCE(correlation_id, id)) as operations')
                ->first();

            $unknownOperations += (int) ($unknownRow->operations ?? 0);
        }

        return [$knownCost, $unknownOperations];
    }

    /**
     * Borne de bascule EFFECTIVE dans la fenetre du mois : le cutover clampe
     * dans [monthStart, nextMonthStart]. Mois entierement anterieur au
     * cutover -> nextMonthStart (fenetre ledger vide) ; mois entierement
     * posterieur -> monthStart (fenetre registre vide) ; mois de transition
     * -> l'instant exact. La ligne ecrite PILE au cutover appartient au
     * ledger (`>=`), jamais au registre (`<` strict) : comptee une fois,
     * jamais deux, jamais zero.
     */
    private function ledgerAuthorityCutover(Carbon $monthStart, Carbon $nextMonthStart): Carbon
    {
        $cutover = Carbon::parse(self::LEDGER_AUTHORITY_SINCE);

        if ($cutover->lessThanOrEqualTo($monthStart)) {
            return $monthStart->copy();
        }

        return $cutover->greaterThan($nextMonthStart) ? $nextMonthStart->copy() : $cutover;
    }

    public function finalize(
        string $provider,
        string $model,
        AiUsage $usage,
        mixed $providerReportedCost = null,
    ): AiCost {
        $reported = $this->normalizeProviderReportedCost($providerReportedCost);

        return $reported === null
            ? AiPricingCatalog::cost($provider, $model, $usage)
            : AiCost::known($reported, AiCost::SOURCE_PROVIDER_REPORTED);
    }

    private function normalizeProviderReportedCost(mixed $cost): ?float
    {
        if (! is_numeric($cost)) {
            return null;
        }

        $normalized = (float) $cost;

        return is_finite($normalized) && $normalized >= 0.0 ? $normalized : null;
    }
}
