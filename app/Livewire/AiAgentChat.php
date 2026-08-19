<?php

namespace App\Livewire;

use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\ProfileAgentConversation;
use App\Models\ProfileAgentMessage;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\Ai\SupervisionEconomicScope;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiProcess;
use App\Support\Ai\AiRefusedException;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Chat visiteur de l'agent de profil — route PUBLIQUE `/profile/{user}/agent-ia`
 * (hors groupe `auth`), aussi montee sur `/agent-ia/test` pour le proprietaire.
 *
 * TASK-1252 — ce chemin est SOUS AUTORITE ECONOMIQUE (gap #15 du gap analysis
 * T1246, G1 CRITICAL : « la plateforme paie pour un anonyme, sans quota
 * durable ni identite »). Decision produit actee : AUCUN appel provider
 * anonyme paye en silence par la plateforme.
 *
 *  - VISITEUR NON AUTHENTIFIE : refus V1 ASSUME, explicite et humain. Aucune
 *    conversation creee, aucun message ecrit, AUCUN appel provider, AUCUNE
 *    ligne ledger. L'agent « repond » par une invitation a se connecter /
 *    creer un compte (bulle recue, pas une erreur technique), puis le
 *    composer se verrouille. Code stable
 *    `AiRefusedException::CODE_AUTHENTICATION_REQUIRED`. Etat visible du
 *    proprietaire : UNE ligne `member_ai_profile_interactions` `status =
 *    refused` (`visitor_type = guest`, cout NULL/NULL) — badge ambre de la
 *    page « Echanges avec mon agent IA » (T1251) ; bornee a UNE par session
 *    invite et par profil (cle de session) : surface d'ecriture anonyme
 *    strictement plus petite qu'avant (conversation + 2 messages + trace admin
 *    + appel provider), un invite qui retourne ses sessions peut en ecrire une
 *    par session (limite V1, assumee).
 *
 *  - VISITEUR AUTHENTIFIE : `MemberProfileAgentResponder::answerUnderEconomicAuthority()`
 *    (T1251) — cle plateforme DECLAREE, garde `AiEconomicGuard` AVANT
 *    provider, UNE ligne ledger `ai_provider_invocations` par tentative
 *    (succes ET echec, usage observe, cout catalogue ou NULL — jamais 0
 *    invente). IDENTITE ECONOMIQUE (provisoire, en attendant T1253) :
 *      · tenant de record = l'Organization du PROFIL visite
 *        (`member_ai_profiles.organization_id`), posee EXPLICITEMENT — jamais
 *        `current_organization` (la requete Livewire ne repasse pas par
 *        `ProfileController`), jamais l'Organization du visiteur : c'est
 *        l'agent de CE profil, dans CETTE Organization, qui travaille ;
 *      · acteur = credit (T1229) = le visiteur authentifie : chemin MEMBRE,
 *        celui qui pose la question consomme son credit (le proprietaire qui
 *        teste son propre agent est son propre visiteur) ;
 *      · feature `member_profile_agent_visitor_chat`, process
 *        `member_profile.agent_visitor_chat` (celui de la trace
 *        `admin_ai_interactions`, inchange), capability NULL.
 *    Refus economique : aucun appel, aucune reponse de substitution, message
 *    avec son code (+ offres si credit epuise et propose), trace `refused`
 *    pour le proprietaire. Succes : conversation + messages inchanges, trace
 *    admin avec tenant explicite et usage observe. Echec provider : ledger
 *    `failed`, repli rule-based dit tel quel.
 *
 * La borne `MAX_VISITOR_TURNS` reste une borne UX de conversation ; la borne
 * DURABLE est desormais le credit du visiteur + le budget de l'Organization
 * du profil + le budget de process.
 */
class AiAgentChat extends Component
{
    const MAX_VISITOR_TURNS = 8;

    /** Fonction produit emettrice (`ai_provider_invocations.feature`). */
    public const FEATURE = 'member_profile_agent_visitor_chat';

    public User $targetUser;

    public ?MemberAiProfile $profile = null;

    public string $question = '';

    public array $messages = [];

    public bool $isTyping = false;

    public ?string $error = null;

    /** Code stable du refus affiche dans `$error` (TASK-1252), pour l'UI/tests. */
    public ?string $errorCode = null;

    /** « Voir les offres » quand le credit est epuise et que la plateforme le propose. */
    public ?string $offersUrl = null;

    public bool $maxTurnsReached = false;

    public int $visitorTurnCount = 0;

    /**
     * TASK-1252 : le visiteur n'est pas authentifie — l'agent ne repondra pas
     * (encart d'invitation a la connexion des le montage, verrou du composer
     * apres le premier message refuse).
     */
    public bool $authRequired = false;

    public bool $guestRefused = false;

    private ?ProfileAgentConversation $conversation = null;

    public function mount(User $user): void
    {
        $this->targetUser = $user;

        $organization = currentOrganization()
            ?? $user?->organization
            ?? DefaultOrganizationResolver::resolve();

        if (! $organization) {
            return;
        }

        $this->profile = MemberAiProfile::where('user_id', $user->id)
            ->where('status', MemberAiProfile::STATUS_PUBLISHED)
            ->first();

        if (! $this->profile) {
            return;
        }

        $this->authRequired = ! auth()->check();

        if ($this->authRequired) {
            // Invite : aucune conversation creee (rien a persister pour un
            // visiteur a qui l'agent ne repondra pas), seulement l'accueil.
            $this->messages[] = $this->assistantBubble($this->initialAssistantMessage($user));

            return;
        }

        $this->conversation = $this->findOrCreateConversation();

        $this->loadMessages();

        if (empty($this->messages)) {
            $this->messages[] = $this->assistantBubble($this->initialAssistantMessage($user));
        }

        $this->visitorTurnCount = ProfileAgentMessage::where('conversation_id', $this->conversation->id)
            ->where('role', 'user')
            ->count();

        $this->maxTurnsReached = $this->visitorTurnCount >= self::MAX_VISITOR_TURNS;
    }

    public function sendMessage(): void
    {
        $this->error = null;
        $this->errorCode = null;
        $this->offersUrl = null;

        if (! $this->profile) {
            $this->error = __('ai.visitor_chat_profile_missing');

            return;
        }

        $question = trim($this->question);
        if ($question === '') {
            return;
        }

        // Methode Livewire publique = action : le refus est SERVEUR, quel que
        // soit l'etat du composer cote client.
        if (! auth()->check()) {
            $this->refuseGuest($question);

            return;
        }

        if ($this->maxTurnsReached) {
            $this->error = __('ai.visitor_chat_max_turns_reached');

            return;
        }

        $tenant = $this->tenantOf($this->profile);

        if ($tenant === null) {
            $this->error = __('ai.visitor_chat_error');

            return;
        }

        /** @var User $visitor */
        $visitor = auth()->user();

        $this->messages[] = [
            'role' => 'user',
            'text' => $question,
            'time' => now()->format('H:i'),
        ];

        $this->storeMessage('user', $question);

        $this->question = '';
        $this->isTyping = true;

        $responder = app(MemberProfileAgentResponder::class);

        try {
            $result = $responder->answerUnderEconomicAuthority(
                $this->profile,
                $question,
                new SupervisionEconomicScope(
                    organization: $tenant,
                    actor: $visitor,
                    creditUser: $visitor,
                    feature: self::FEATURE,
                ),
                AiProcess::MEMBER_PROFILE_AGENT_VISITOR_CHAT,
                'profile_agent_visitor_chat',
            );

            $response = $result['response'] ?? __('ai.visitor_chat_generation_failed');

            $this->messages[] = $this->assistantBubble($response);

            $this->storeMessage('assistant', $response, $result);

            $responder->logVisitorInteraction($question, $response, $result, $tenant->id);

            $this->visitorTurnCount++;
            $this->maxTurnsReached = $this->visitorTurnCount >= self::MAX_VISITOR_TURNS;
        } catch (AiRefusedException $refused) {
            // Refus AVANT tout appel : rien de parti, rien au ledger, aucune
            // reponse de substitution. Le visiteur voit pourquoi (avec le
            // code), le proprietaire voit l'etat de l'echange.
            $this->error = $refused->getMessage();
            $this->errorCode = $refused->refusalCode;
            $this->offersUrl = $refused->offersUrl($tenant);

            MemberAiProfileInteraction::recordRefusal(
                $this->profile,
                AiProcess::MEMBER_PROFILE_AGENT_VISITOR_CHAT,
                self::FEATURE,
                $refused,
                $question,
                $visitor,
                $responder->defaultProviderSelection(),
            );
        } catch (\Throwable $e) {
            $this->error = __('ai.visitor_chat_error');
        } finally {
            $this->isTyping = false;
        }
    }

    public function resetConversation(): void
    {
        $this->messages = [];
        $this->error = null;
        $this->errorCode = null;
        $this->offersUrl = null;
        $this->question = '';
        $this->maxTurnsReached = false;
        $this->visitorTurnCount = 0;

        if ($this->authRequired || ! auth()->check()) {
            // Invite : rien en base a effacer ni a recreer ; le verrou reste.
            $this->messages[] = $this->assistantBubble($this->initialAssistantMessage($this->targetUser));

            return;
        }

        if ($this->conversation) {
            $this->conversation->messages()->delete();
            $this->conversation->delete();
            $this->conversation = null;
        }

        $this->conversation = $this->findOrCreateConversation();

        $this->messages[] = $this->assistantBubble($this->initialAssistantMessage($this->targetUser));
    }

    /**
     * TASK-1252 — refus V1 d'un visiteur anonyme : la question s'affiche, la
     * « reponse » est une invitation a se connecter, le composer se
     * verrouille. Rien n'est ecrit pour le visiteur ; UNE trace `refused`
     * (au plus une par session et par profil) pour le proprietaire.
     */
    private function refuseGuest(string $question): void
    {
        $this->authRequired = true;
        $this->guestRefused = true;

        $this->messages[] = [
            'role' => 'user',
            'text' => $question,
            'time' => now()->format('H:i'),
        ];

        $this->messages[] = $this->assistantBubble(__('ai.visitor_chat_guest_refusal_message', [
            'member_name' => $this->targetUser->name,
        ]));

        $this->question = '';

        $refused = new AiRefusedException(
            AiRefusedException::CODE_AUTHENTICATION_REQUIRED,
            __('ai.visitor_chat_guest_refusal_message', ['member_name' => $this->targetUser->name]),
        );

        $sessionKey = 'profile_agent_guest_refusal_recorded.'.$this->profile->id;

        if (session()->has($sessionKey)) {
            return;
        }

        MemberAiProfileInteraction::recordRefusal(
            $this->profile,
            AiProcess::MEMBER_PROFILE_AGENT_VISITOR_CHAT,
            self::FEATURE,
            $refused,
            $question,
            null,
            null,
        );

        session([$sessionKey => true]);
    }

    /**
     * Le tenant de record : l'Organization du PROFIL (cf. docblock de classe).
     * Un profil sans Organization est un defaut de donnees : aucun appel, on
     * ne devine pas un tenant, on le dit.
     */
    private function tenantOf(MemberAiProfile $profile): ?Organization
    {
        $organizationId = trim((string) $profile->organization_id);
        $organization = $organizationId !== '' ? Organization::query()->find($organizationId) : null;

        if ($organization === null) {
            Log::warning('Member profile visitor chat skipped: the profile has no Organization.', [
                'member_ai_profile_id' => $profile->id,
                'profile_organization_id' => $profile->organization_id,
                'correlation_id' => AiCorrelation::id(),
            ]);
        }

        return $organization;
    }

    /**
     * @return array{role: string, text: string, time: string}
     */
    private function assistantBubble(string $text): array
    {
        return [
            'role' => 'assistant',
            'text' => $text,
            'time' => now()->format('H:i'),
        ];
    }

    private function initialAssistantMessage(User $user): string
    {
        return __('ai.visitor_chat_initial_message', ['member_name' => $user->name]);
    }

    /**
     * Conversation d'un visiteur AUTHENTIFIE (TASK-1252 : un invite n'en a
     * plus — l'agent ne lui repond pas). `visitor_session_id` reste en base
     * pour les conversations anonymes historiques, plus alimente ici.
     *
     * L'Organization de la conversation est celle du PROFIL (le dialogue
     * appartient a l'agent de ce profil, c'est la que le proprietaire le
     * relit) — plus `current_organization`, qui dans une requete Livewire
     * `/livewire/update` est l'Organization du VISITEUR connecte (ou rien), et
     * qui faisait naitre une seconde conversation invisible du proprietaire
     * pour un visiteur d'une autre Organization.
     */
    private function findOrCreateConversation(): ProfileAgentConversation
    {
        $organizationId = $this->profile->organization_id
            ?: (currentOrganization()?->id
                ?? $this->targetUser?->organization_id
                ?? DefaultOrganizationResolver::resolve()?->id);

        return ProfileAgentConversation::query()
            ->where('member_ai_profile_id', $this->profile->id)
            ->where('profile_owner_user_id', $this->targetUser->id)
            ->where('organization_id', $organizationId)
            ->where('visitor_user_id', auth()->id())
            ->firstOrCreate([
                'organization_id' => $organizationId,
                'member_ai_profile_id' => $this->profile->id,
                'profile_owner_user_id' => $this->targetUser->id,
                'visitor_user_id' => auth()->id(),
                'visitor_session_id' => null,
            ]);
    }

    private function loadMessages(): void
    {
        if (! $this->conversation) {
            return;
        }

        $dbMessages = ProfileAgentMessage::where('conversation_id', $this->conversation->id)
            ->orderBy('created_at')
            ->get();

        foreach ($dbMessages as $msg) {
            $this->messages[] = [
                'role' => $msg->role,
                'text' => $msg->content,
                'time' => $msg->created_at->format('H:i'),
            ];
        }
    }

    private function storeMessage(string $role, string $content, ?array $result = null): void
    {
        if (! $this->conversation) {
            $this->conversation = $this->findOrCreateConversation();
        }

        $metadata = null;

        if ($result) {
            $metadata = [
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'latency_ms' => $result['latency_ms'] ?? null,
                'fields' => $result['fields'] ?? [],
            ];

            if (isset($result['fallback_after_provider_failure'])) {
                $metadata['fallback_after_provider_failure'] = $result['fallback_after_provider_failure'];
            }
        }

        ProfileAgentMessage::create([
            'conversation_id' => $this->conversation->id,
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
        ]);
    }

    public function render()
    {
        return view('livewire.ai-agent-chat');
    }
}
