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
     * garde est le ledger canonique `ai_provider_invocations`, chacun a
     * partir de SON instant de bascule (UTC). Mapping FERME, SEULE source de
     * verite (la liste des process migres est `array_keys()`, jamais une
     * seconde constante) — exactement le perimetre dont TASK-1259 (G11-a) a
     * prouve la parite exacte registre/ledger par `correlation_id` :
     * capabilities canoniques + Blog/Explorer. Le bac a sable de doctrine
     * reutilise le process de la capability essayee (distingue par
     * `feature = ai_doctrine_sandbox`) : aucune valeur a lui.
     *
     * Les cutovers sont des decisions produit FIGEES — jamais deduites d'un
     * `MIN(created_at)` runtime. Doctrine : « le premier minuit UTC
     * ENTIEREMENT couvert par les ecritures ledger du process » :
     *  - canoniques : le ledger est ne le 17/08 a 14:53 -> 18/08 00:00Z
     *    (17/08 00:00 perdrait les traces du 17/08 matin) ;
     *  - Blog/Explorer : leurs ecritures ledger n'ont commence qu'avec
     *    T1247/T1248, merges le 19/08 -> 20/08 00:00Z (signal S2 : un
     *    cutover au 18/08 aurait fait disparaitre du budget les traces
     *    registre Blog du 18-19/08, sans ligne ledger en face) ;
     *  - agent de profil (TASK-1286, la « TASK dediee » annoncee ci-dessous) :
     *    la reponse automatique (`member_profile.loop_agent_reply`) ecrit au
     *    ledger depuis T1251 (PR #255, merge 19/08 10:38 UTC) et le chat
     *    visiteur (`member_profile.agent_visitor_chat`) depuis T1252
     *    (PR #256, merge 19/08 11:36 UTC) -> premier minuit UTC entierement
     *    couvert = 20/08 00:00Z, le meme instant que Blog/Explorer. Leur
     *    tenant est EXPLICITE et fail-closed : l'Organization du PROFIL
     *    (`tenantOf()`, aucun repli — le job exige en plus profil et Boucle
     *    dans la MEME Organization), jamais une resolution par defaut.
     *  - surface courte (TASK-1291) : `member_profile.agent_setup` et
     *    `service_offer.master`. Leur HARD GATE tenant (l'Organization PAR
     *    DEFAUT que `ResolveUrlOrganization` lie aux pages sans prefixe et a
     *    l'endpoint d'update Livewire) est leve par T1291 : le tenant vient
     *    de l'ACTEUR (`users.organization_id`), garde d'appartenance
     *    fail-closed 404 AVANT tout provider, sur les deux surfaces.
     *    Correctif merge le 24/08 -> premier minuit UTC ENTIEREMENT couvert
     *    par le comportement corrige = 25/08 00:00Z. Le ledger reel ne
     *    porte AUCUNE ligne historique au tenant incorrect (0 ligne setup,
     *    1 ligne master au tenant juste, verifie T1291) — mais la date
     *    protege la PERIODE couverte par le comportement corrige, jamais la
     *    chance qu'aucune mauvaise ligne n'existe.
     * Avant l'instant d'un process : `ai_interactions` (requete historique).
     * A partir de cet instant, inclus : le ledger. Fenetres disjointes PAR
     * PROCESS ; les lignes ecrites dans les deux tables avant le cutover ne
     * sont lues que cote registre.
     *
     * Le filtre par process n'est pas une optimisation : le ledger recoit
     * une ligne de TOUTES les familles d'appel (y compris les chemins restes
     * hors perimetre, dont la trace operationnelle est
     * `admin_ai_interactions`), la ou `ai_interactions` ne recevait
     * structurellement que le perimetre garde. Sans lui, le plafond
     * Organization se mettrait a compter des familles non migrees.
     *
     * RESTENT DEHORS apres TASK-1291, chacun pour une raison ecrite :
     *  - `supervision.content` (banc SuperAdmin) : son tenant EST
     *    `DefaultOrganizationResolver::resolve()`, exclu d'office ;
     *  - `member_profile.admin_llm_test` (banc test LLM) : tenant explicite
     *    (l'Organization du profil teste) mais convergence = DECISION
     *    PRODUIT EN ATTENTE (un test SuperAdmin pourrait epuiser le budget
     *    d'un client et bloquer SES membres) — voir Review Notes T1286.
     */
    public const LEDGER_AUTHORITY_SINCE_BY_PROCESS = [
        'chatloop.summarize' => '2026-08-18T00:00:00+00:00',
        'chatloop.answer' => '2026-08-18T00:00:00+00:00',
        'chatloop.ask' => '2026-08-18T00:00:00+00:00',
        'help_request.clarify' => '2026-08-18T00:00:00+00:00',
        'loop_knowledge.answer' => '2026-08-18T00:00:00+00:00',
        'blog.article_generate' => '2026-08-20T00:00:00+00:00',
        'blog.article_correct' => '2026-08-20T00:00:00+00:00',
        'blog.method_selection' => '2026-08-20T00:00:00+00:00',
        'blog.explorer_dialogue' => '2026-08-20T00:00:00+00:00',
        'blog.explorer_note' => '2026-08-20T00:00:00+00:00',
        'member_profile.loop_agent_reply' => '2026-08-20T00:00:00+00:00',
        'member_profile.agent_visitor_chat' => '2026-08-20T00:00:00+00:00',
        'member_profile.agent_setup' => '2026-08-25T00:00:00+00:00',
        'service_offer.master' => '2026-08-25T00:00:00+00:00',
    ];

    /**
     * Les process migres — derives du mapping, jamais une liste a part.
     *
     * @return list<string>
     */
    public static function ledgerAuthorityProcesses(): array
    {
        return array_keys(self::LEDGER_AUTHORITY_SINCE_BY_PROCESS);
    }

    /**
     * TASK-1212 : plafond mensuel porte par l'Organization elle-meme, toutes
     * capabilities confondues. Verifie AVANT le plafond par process.
     *
     * TASK-1222 : ce plafond couvre generation + embeddings, en additionnant
     * DEUX registres SANS overlap (aucun embedding n'est ecrit dans
     * `ai_interactions`). TASK-1260 : la part generation est lue depuis
     * `ai_interactions` avant le cutover PROPRE A CHAQUE process
     * (`LEDGER_AUTHORITY_SINCE_BY_PROCESS`) et depuis le ledger canonique a
     * partir de cet instant — fenetres disjointes par process, une
     * generation moderne presente dans les deux registres ne compte
     * toujours qu'une fois.
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
     * embeddings du ledger canonique. Zero double comptage, zero perte.
     *
     * TASK-1260 (G11-b, correctif S2) : la part GENERATION se somme en
     * trois morceaux strictement disjoints :
     *
     *  1. les traces `ai_interactions` HORS perimetre migre (process NULL
     *     ou hors mapping) sur TOUT le mois — aucun cutover ne s'applique a
     *     elles, exactement le comportement anterieur a la bascule ;
     *  2. pour CHAQUE groupe de cutover du mapping (canoniques 18/08,
     *     Blog/Explorer 20/08, surface courte T1291 25/08) :
     *     `ai_interactions` du groupe AVANT son
     *     cutover — c'est ce qui garde comptees les traces Blog du
     *     18-19/08, ecrites avant que T1247/T1248 ne commencent le ledger ;
     *  3. le ledger du groupe A PARTIR de son cutover.
     *
     * Un cutover global applique a tort a un groupe qui a le sien propre
     * perdrait du cout (signal S2) ou le compterait deux fois : chaque
     * groupe porte ses deux fenetres. La part EMBEDDING ne subit aucun
     * cutover : deja lue depuis le ledger sur tout le mois (TASK-1222).
     */
    private function organizationMonthlyKnownCost(
        Organization $organization,
        Carbon $monthStart,
        Carbon $nextMonthStart,
    ): float {
        $generationKnown = (float) AiInteraction::query()
            ->where('organization_id', $organization->id)
            ->where(static function ($query): void {
                // `whereNotIn` seul exclurait les process NULL (semantique
                // SQL de NOT IN face a NULL) : l'historique sans process
                // resterait alors hors budget, une perte silencieuse.
                $query->whereNull('process')
                    ->orWhereNotIn('process', self::ledgerAuthorityProcesses());
            })
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->where('cost_unknown', false)
            ->sum('cost_usd');

        foreach ($this->processGroupsByCutover() as $sinceIso => $processes) {
            $cutover = $this->ledgerAuthorityCutover($sinceIso, $monthStart, $nextMonthStart);

            $generationKnown += (float) AiInteraction::query()
                ->where('organization_id', $organization->id)
                ->whereIn('process', $processes)
                ->where('created_at', '>=', $monthStart)
                ->where('created_at', '<', $cutover)
                ->where('cost_unknown', false)
                ->sum('cost_usd');

            $generationKnown += (float) AiProviderInvocation::query()
                ->where('organization_id', $organization->id)
                ->where('operation', AiProviderInvocation::OPERATION_GENERATION)
                ->whereIn('process', $processes)
                ->where('cost_status', AiProviderInvocation::COST_KNOWN)
                ->where('created_at', '>=', $cutover)
                ->where('created_at', '<', $nextMonthStart)
                ->sum('provider_cost');
        }

        $embeddingKnown = (float) AiProviderInvocation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->where('cost_status', AiProviderInvocation::COST_KNOWN)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->sum('provider_cost');

        return $generationKnown + $embeddingKnown;
    }

    /**
     * Le mapping process -> cutover, groupe par instant de bascule : une
     * entree par cutover distinct, avec les process qui le partagent.
     *
     * @return array<string, list<string>>
     */
    private function processGroupsByCutover(): array
    {
        $groups = [];

        foreach (self::LEDGER_AUTHORITY_SINCE_BY_PROCESS as $process => $sinceIso) {
            $groups[$sinceIso][] = $process;
        }

        return $groups;
    }

    /**
     * Usage mensuel d'un PROCESS : cout connu (somme) et inconnus reussis
     * (compte d'operations), pour le verrou par process de `authorize()`.
     *
     * TASK-1260 (G11-b) — deux autorites, choisies par le mapping ferme,
     * jamais devinees :
     *
     *  - process de `LEDGER_AUTHORITY_SINCE_BY_PROCESS` : fenetres de
     *    cutover disjointes, `ai_interactions` avant l'instant PROPRE au
     *    process, ledger canonique ensuite. Cote ledger, le cout connu reste une
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
     *  - tout autre process (chemins restes hors mapping apres TASK-1291 —
     *    les bancs SuperAdmin : `supervision.content` au tenant par defaut
     *    par construction, `member_profile.admin_llm_test` en decision
     *    produit) : lecture historique `ai_interactions` INCHANGEE sur tout
     *    le mois, jusqu'a une levee explicite de leur raison d'exclusion.
     *
     * @return array{0: float, 1: int}
     */
    private function processMonthlyUsage(
        Organization $organization,
        string $process,
        Carbon $monthStart,
        Carbon $nextMonthStart,
    ): array {
        $sinceIso = self::LEDGER_AUTHORITY_SINCE_BY_PROCESS[$process] ?? null;

        $legacyWindowEnd = $sinceIso !== null
            ? $this->ledgerAuthorityCutover($sinceIso, $monthStart, $nextMonthStart)
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
     * Borne de bascule EFFECTIVE d'un instant de cutover dans la fenetre du
     * mois : le cutover clampe dans [monthStart, nextMonthStart]. Mois
     * entierement anterieur au cutover -> nextMonthStart (fenetre ledger
     * vide) ; mois entierement posterieur -> monthStart (fenetre registre
     * vide) ; mois de transition -> l'instant exact. La ligne ecrite PILE au
     * cutover appartient au ledger (`>=`), jamais au registre (`<` strict) :
     * comptee une fois, jamais deux, jamais zero.
     */
    private function ledgerAuthorityCutover(string $sinceIso, Carbon $monthStart, Carbon $nextMonthStart): Carbon
    {
        $cutover = Carbon::parse($sinceIso);

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
