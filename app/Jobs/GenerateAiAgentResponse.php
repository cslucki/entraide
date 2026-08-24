<?php

namespace App\Jobs;

use App\Events\LoopMessageCreated;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\Ai\SupervisionEconomicScope;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiProcess;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reponse automatique de l'agent de profil a un message poste dans une
 * Boucle agent (gap #14 du gap analysis T1246).
 *
 * TASK-1251 — ce job s'execute SOUS AUTORITE ECONOMIQUE (G2 CRITICAL) : la
 * garde `AiEconomicGuard` s'applique ICI, dans le job, juste avant l'appel
 * provider reel — pas au dispatch, qui peut etre retarde alors que le budget
 * change entre-temps. Chaque tentative provider reelle ecrit sa ligne au
 * ledger canonique `ai_provider_invocations` (succes ET echec, cle plateforme
 * DECLAREE, usage observe, cout catalogue ou NULL — jamais 0 invente) ; un
 * refus n'ecrit rien au ledger et aucun appel ne part.
 *
 * IDENTITE ECONOMIQUE (G10 HIGH) — tranchee ici en T1251, CONFIRMEE
 * DEFINITIVE par l'attribution canonique User/Organization/Capability
 * (TASK-1253 : c'est la regle commune a tous les writers du ledger, voir
 * `SupervisionEconomicScope`) :
 *
 *  - TENANT de record = l'Organization du PROFIL membre
 *    (`member_ai_profiles.organization_id`), celle du membre dont l'agent
 *    repond — JAMAIS celle d'un visiteur. Une Boucle agent est ouverte par
 *    `AiAgentLoopController::startConversation()` par un utilisateur
 *    AUTHENTIFIE de la MEME Organization que le profil (403 sinon) :
 *    `loop.organization_id === profile.organization_id` par construction.
 *    Le job le VERIFIE ; une divergence est un defaut de donnees : aucun
 *    appel, aucun message, log — on ne devine pas un tenant.
 *  - ACTEUR = l'expediteur du message (`loop_messages.sender_id`), celui qui
 *    a DECLENCHE l'appel (`user_id` du ledger) — pas le proprietaire du
 *    profil, qui n'a rien fait a cet instant.
 *  - CREDIT (T1229) = l'expediteur egalement : chemin MEMBRE (jamais sans
 *    credit — doctrine `SupervisionEconomicScope`), et c'est celui qui pose
 *    la question a l'IA qui consomme son credit, comme « Demander a l'IA »
 *    dans une ChatLoop ou la formulation d'offre (#13). Le proprietaire ne
 *    porte pas le credit de chaque visiteur — acteur = credit est un
 *    invariant du scope depuis T1253 (le ledger n'a qu'un `user_id`).
 *  - FEATURE = `member_profile_agent_loop_reply` ; PROCESS =
 *    `member_profile.loop_agent_reply` (celui de la trace operationnelle
 *    `member_ai_profile_interactions`, inchange : ledger et trace portent le
 *    meme process) ; capability NULL (pas une capability canonique).
 *
 * REFUS en contexte ASYNCHRONE (aucune reponse HTTP a renvoyer) : le job ne
 * plante pas (rejouer un refus budgetaire n'a pas de sens), n'ecrit AUCUN
 * `LoopMessage` (ni faux message assistant, ni reponse rule-based de
 * substitution — un budget atteint n'est pas une panne provider a contourner
 * « gratuitement »), ne `touch()` pas la Boucle ; il ecrit un log metier et
 * une ligne `member_ai_profile_interactions` `status = refused` (reponse
 * NULL, cout NULL/NULL « non evalue ») : l'etat de l'echange, visible sur la
 * page « Echanges avec mon agent IA » du proprietaire. Ce n'est pas une ligne
 * economique (aucun lecteur economique ne lit cette table).
 *
 * ECHEC provider (appel parti) : ligne `failed` au ledger (cout NULL), puis
 * repli rule-based publie comme avant (comportement produit inchange) — la
 * trace dit la verite : `metadata.fallback_after_provider_failure`.
 */
class GenerateAiAgentResponse implements ShouldQueue
{
    use Queueable;

    /** Fonction produit emettrice (`ai_provider_invocations.feature`). */
    public const FEATURE = 'member_profile_agent_loop_reply';

    /** Statut de la trace operationnelle quand la garde a refuse l'appel (alias, cf. modele). */
    public const INTERACTION_STATUS_REFUSED = MemberAiProfileInteraction::STATUS_REFUSED;

    /**
     * TASK-1131 — propagation asynchrone de la corrélation.
     *
     * `$correlationId` est figé au moment du DISPATCH, c'est-à-dire dans
     * l'opération d'origine (le message posté dans la boucle). Il est sérialisé
     * avec le job, donc :
     * - une même opération conserve sa corrélation jusqu'à l'écriture de trace ;
     * - un retry rejoue le même payload et conserve donc la même corrélation ;
     * - deux opérations distinctes produisent deux jobs et deux corrélations.
     *
     * La corrélation n'est jamais recréée à l'exécution.
     */
    public function __construct(
        public Loop $loop,
        public LoopMessage $message,
        public ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? AiCorrelation::id();
    }

    public function handle(MemberProfileAgentResponder $responder): void
    {
        // Réadopte la corrélation de l'opération d'origine : le worker exécute
        // ce job dans un scope neuf, sans quoi chaque job repartirait sur une
        // corrélation arbitraire.
        AiCorrelation::bind($this->correlationId);

        $profile = $this->loop->memberAiProfile;

        if (! $profile) {
            return;
        }

        $sender = $this->message->sender;

        if (! $sender) {
            return;
        }

        if ($sender->id === $profile->user_id) {
            return;
        }

        $tenant = $this->tenantOf($profile);

        if ($tenant === null) {
            return;
        }

        $scope = new SupervisionEconomicScope(
            organization: $tenant,
            actor: $sender,
            creditUser: $sender,
            feature: self::FEATURE,
        );

        try {
            $result = $responder->answerUnderEconomicAuthority(
                $profile,
                $this->message->body,
                $scope,
                AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY,
            );
        } catch (AiRefusedException $refused) {
            $this->recordRefusal($profile, $sender, $refused, $responder->defaultProviderSelection());

            return;
        }

        $responseMessage = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $profile->user_id,
            'body' => $result['response'],
            'type' => 'user',
            'metadata' => ['ai_generated' => true],
            'organization_id' => $this->loop->organization_id,
        ]);

        event(new LoopMessageCreated($responseMessage));

        $this->loop->touch();

        // TASK-1132 / TASK-1251 : usage OBSERVE par le responder quand le
        // provider le rapporte (chat/completions, ollama), sinon non observe.
        // Le catalogue tranche : `rule_based` ou `ollama` -> cout reellement
        // nul et CONNU ; provider distant avec usage -> cout catalogue ; sans
        // usage -> `cost_unknown = true` et cout NULL. Jamais un zero fabrique.
        $usage = $result['usage'] ?? AiUsage::notObserved();
        $cost = AiPricingCatalog::cost(
            $result['provider'] ?? null,
            $result['model'] ?? null,
            $usage,
        );

        $metadata = [];

        if (isset($result['fallback_after_provider_failure'])) {
            $metadata['fallback_after_provider_failure'] = $result['fallback_after_provider_failure'];
        }

        // TASK-1285 : traces de composition du chemin canonique (doctrine
        // reellement composee, sources et provenance du Context Builder).
        // Absentes d'une reponse rule-based : elle n'a rien compose.
        if (isset($result['composition'])) {
            $metadata['composition'] = $result['composition'];
        }

        MemberAiProfileInteraction::create([
            'organization_id' => $tenant->id,
            'correlation_id' => AiCorrelation::id(),
            'process' => AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY,
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $profile->user_id,
            'visitor_user_id' => $sender->id,
            'visitor_type' => 'user',
            'provider' => $result['provider'] ?? null,
            'model' => $result['model'] ?? null,
            'status' => 'success',
            'question' => $this->message->body,
            'response' => $result['response'],
            'matched_fields' => $result['fields'] ?? [],
            'metadata' => $metadata !== [] ? $metadata : null,
            'latency_ms' => $result['latency_ms'] ?? null,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            ...$cost->traceAttributes(),
        ]);
    }

    /**
     * Le tenant de record : l'Organization du PROFIL (cf. docblock de classe).
     * La Boucle doit lui appartenir ; sinon (defaut de donnees) on n'appelle
     * pas, on ne devine pas, on le dit.
     */
    private function tenantOf(MemberAiProfile $profile): ?Organization
    {
        $organizationId = trim((string) $profile->organization_id);

        if ($organizationId === '' || (string) $this->loop->organization_id !== $organizationId) {
            Log::warning('Member profile agent reply skipped: the Loop and the profile do not share an Organization.', [
                'loop_id' => $this->loop->id,
                'loop_organization_id' => $this->loop->organization_id,
                'member_ai_profile_id' => $profile->id,
                'profile_organization_id' => $profile->organization_id,
                'correlation_id' => AiCorrelation::id(),
            ]);

            return null;
        }

        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            Log::warning('Member profile agent reply skipped: the profile Organization does not exist.', [
                'loop_id' => $this->loop->id,
                'member_ai_profile_id' => $profile->id,
                'profile_organization_id' => $organizationId,
                'correlation_id' => AiCorrelation::id(),
            ]);
        }

        return $organization;
    }

    /**
     * Refus de la garde : log metier + etat de l'echange (`status = refused`,
     * aucune reponse, cout non evalue — `MemberAiProfileInteraction::recordRefusal()`,
     * meme forme que le chat visiteur T1252). Rien au ledger, aucun message dans
     * la Boucle — cf. docblock de classe.
     *
     * @param  array{provider: string, model: string}|null  $attempted  provider/modele qui AURAIENT ete appeles
     */
    private function recordRefusal(MemberAiProfile $profile, User $sender, AiRefusedException $refused, ?array $attempted): void
    {
        Log::warning('Member profile agent reply refused by the economic guard.', [
            'code' => $refused->refusalCode,
            'loop_id' => $this->loop->id,
            'member_ai_profile_id' => $profile->id,
            'organization_id' => $profile->organization_id,
            'sender_id' => $sender->id,
            'provider' => $attempted['provider'] ?? null,
            'model' => $attempted['model'] ?? null,
            'correlation_id' => AiCorrelation::id(),
        ]);

        MemberAiProfileInteraction::recordRefusal(
            $profile,
            AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY,
            self::FEATURE,
            $refused,
            $this->message->body,
            $sender,
            $attempted,
        );
    }
}
