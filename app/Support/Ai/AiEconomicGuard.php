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
     * TASK-1212 : plafond mensuel porte par l'Organization elle-meme, toutes
     * capabilities confondues. Verifie AVANT le plafond par process.
     *
     * TASK-1222 : ce plafond couvre desormais generation + embeddings, en
     * additionnant DEUX registres SANS overlap : les generations gardees
     * vivent dans `ai_interactions` (aucun embedding n'y est ecrit), les
     * embeddings vivent dans le ledger canonique `ai_provider_invocations`
     * (`operation = embedding`). Les generations du ledger ne sont JAMAIS
     * sommees ici : une generation moderne presente dans les deux registres
     * ne compte qu'une fois.
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

        $monthly = AiInteraction::query()
            ->where('organization_id', $organization->id)
            ->where('process', $process)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart);

        $knownMonthlyCost = (float) (clone $monthly)
            ->where('cost_unknown', false)
            ->sum('cost_usd');

        $successfulUnknownCount = (clone $monthly)
            ->where('cost_unknown', true)
            ->count();

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
     * Cout mensuel CONNU de l'Organization : generations gardees
     * (`ai_interactions`, ou aucun embedding n'est ecrit) + embeddings du
     * ledger canonique. Deux registres disjoints, zero double comptage —
     * les generations du ledger sont volontairement exclues tant que
     * l'autorite generation n'a pas migre.
     */
    private function organizationMonthlyKnownCost(
        Organization $organization,
        Carbon $monthStart,
        Carbon $nextMonthStart,
    ): float {
        $generationKnown = (float) AiInteraction::query()
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->where('cost_unknown', false)
            ->sum('cost_usd');

        $embeddingKnown = (float) AiProviderInvocation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->where('cost_status', AiProviderInvocation::COST_KNOWN)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->sum('provider_cost');

        return $generationKnown + $embeddingKnown;
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
