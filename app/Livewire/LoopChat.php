<?php

namespace App\Livewire;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Reaction;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopMessageService;
use App\Services\Loops\LoopAnswerCapitalizationService;
use App\Services\Loops\LoopLifecycleService;
use App\Services\UrlPreviewService;
use App\Support\Ai\AiTurnLock;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class LoopChat extends Component
{
    use WithFileUploads;

    public Loop $loop;

    public string $body = '';

    public bool $isMember = false;

    public ?string $replyToMessageId = null;

    public ?array $replyingTo = null;

    /**
     * TASK-1308 : le moteur du PROCHAIN tour, choisi independamment du fil
     * de conversation (reply_to_id) — `normal` (message humain, aucun appel
     * IA), `ia` (LLM direct) ou `dossiers` (RAG Loop-scoped). Un reply
     * PRESELECTIONNE ce mode depuis le parent (voir `replyTo()`) mais ne le
     * verrouille jamais : le membre peut toujours le changer avant d'envoyer.
     *
     * TASK-1309 : quatrieme etat `ia_dossiers` — les DEUX moteurs sur le meme
     * message. Ce n'est pas un troisieme bouton : c'est les deux boutons
     * existants actifs en meme temps (voir `toggleComposerEngine()`).
     */
    public string $composerMode = 'normal';

    /**
     * Les quatre etats du composeur (TASK-1308 / TASK-1309), et le seul
     * endroit ou cette liste existe.
     *
     * @var list<string>
     */
    private const COMPOSER_MODES = ['normal', 'ia', 'dossiers', 'ia_dossiers'];

    public $photo = null;

    public int $messagePageSize = 30;

    public array $loadedMessageIds = [];

    public bool $hasOlderMessages = false;

    /**
     * TASK-1310 : etat du formulaire « Ajouter au Dossier ». `null` = ferme.
     * Rien n'est ecrit tant que l'humain n'a pas valide : ces quatre champs
     * sont un BROUILLON, editable jusqu'a l'enregistrement.
     */
    public ?string $capitalizingMessageId = null;

    public string $capitalizeTitle = '';

    public string $capitalizeContent = '';

    public string $capitalizeDossierId = '';

    /**
     * La confirmation d'enregistrement, portee par l'ETAT du composant et non
     * par un flash de session.
     *
     * PIEGE DEJA PAYE AILLEURS (T1213) : cette page porte un `wire:poll`. Un
     * flash de session est lu — donc consomme — par le premier re-render venu,
     * y compris celui du poll, et l'utilisateur ne voit rien. Constate en
     * recette reelle sur ce meme geste. Une propriete publique, elle, survit
     * aux re-renders et ne disparait que lorsque NOUS la vidons.
     */
    public string $capitalizeFlash = '';

    public ?string $editingMessageId = null;

    public string $editingBody = '';

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
        $this->refreshMembership();
        $this->loadInitialMessages();
    }

    /**
     * Recalculer l'adhesion **a chaque requete**, y compris les mises a jour
     * Livewire.
     *
     * `$isMember` est une propriete publique : elle voyage dans le snapshot, et
     * Livewire la restitue telle qu'elle etait au chargement de la page. Une
     * personne retiree de la Boucle gardait donc `true` et continuait a lire —
     * y compris des messages postes **apres** son depart — en rejouant son
     * dernier snapshot. Le snapshot est une capacite durable : il n'a ni nonce,
     * ni expiration.
     *
     * `booted()` s'execute a l'hydratation comme au montage : l'adhesion est
     * desormais une conclusion tiree a chaque fois, pas un fait recopie.
     */
    public function booted(): void
    {
        $this->refreshMembership();
    }

    private function refreshMembership(): void
    {
        $user = auth()->user();

        $this->isMember = $user
            && ! $user->isDeactivated()
            && LoopMember::where('loop_id', $this->loop->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
    }

    public function aiRoute(): string
    {
        // L'Organization vient de la **Boucle**, pas de `request()`. Sur une
        // mise a jour Livewire, la requete est le `POST /livewire/update` et ne
        // porte aucun parametre `organization` : la route retombait alors sur
        // sa forme sans prefixe, et changeait donc entre le chargement de la
        // page et le premier clic.
        $organization = request()->route('organization') ?? $this->loop->organization?->slug;

        // `routeIs('organization.*')` ne tient pas non plus sur un POST
        // Livewire — la route courante y est celle de Livewire. C'est
        // l'existence d'une Organization pour cette Boucle qui decide, et elle
        // ne change pas d'une requete a l'autre.
        if ($organization && Route::has('organization.loops.ai')) {
            return route('organization.loops.ai', [
                'organization' => $organization,
                'loop' => $this->loop,
            ]);
        }

        return route('loops.ai', $this->loop);
    }

    public function replyTo(string $messageId): void
    {
        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->with('sender')
            ->first();

        if (! $message) {
            return;
        }

        $this->replyToMessageId = $message->id;
        $this->replyingTo = [
            'body' => $message->isDeleted() ? __('messages.deleted_message_placeholder') : mb_substr($message->body, 0, 120),
            'sender_name' => $message->type === 'ai'
                ? $this->aiBubbleLabel($message)
                : ($message->sender?->publicDisplayName() ?? __('messages.member')),
        ];
        // TASK-1308 : le mode herite du parent est un DEFAUT visible dans le
        // composeur, jamais un verrou — voir setComposerMode().
        $this->composerMode = $this->defaultModeForParent($message);
    }

    public function cancelReply(): void
    {
        $this->replyToMessageId = null;
        $this->replyingTo = null;
        $this->composerMode = 'normal';
    }

    /**
     * TASK-1308 : selection EXPLICITE du moteur du prochain tour. Ignore
     * silencieusement une valeur inconnue plutot que de lever — un evenement
     * front perime ou un double-clic ne doivent pas casser le composeur.
     */
    public function setComposerMode(string $mode): void
    {
        if (! in_array($mode, self::COMPOSER_MODES, true)) {
            return;
        }

        $this->composerMode = $mode;
    }

    /**
     * TASK-1309 : les DEUX actions existantes — « Demander a l'IA » et
     * « Consulter les Dossiers » — deviennent deux interrupteurs qui se
     * combinent. Quatre etats accessibles sans troisieme bouton et sans
     * redesign : aucun -> normal, IA -> ia, Dossiers -> dossiers, les deux ->
     * ia_dossiers. Le clic sur un moteur DEJA actif l'eteint, ce qui donne
     * enfin son sens au « × » que ces boutons affichaient deja.
     *
     * Ignore une valeur inconnue en silence, comme `setComposerMode()` : un
     * evenement front perime ne doit pas casser le composeur.
     */
    public function toggleComposerEngine(string $engine): void
    {
        if (! in_array($engine, ['ia', 'dossiers'], true)) {
            return;
        }

        $active = $this->activeEngines();
        $active[$engine] = ! ($active[$engine] ?? false);

        $this->composerMode = match (true) {
            $active['ia'] && $active['dossiers'] => 'ia_dossiers',
            $active['ia'] => 'ia',
            $active['dossiers'] => 'dossiers',
            default => 'normal',
        };
    }

    /**
     * Les moteurs actuellement selectionnes, derives du mode — jamais un
     * second etat a maintenir en parallele de `$composerMode`.
     *
     * @return array{ia: bool, dossiers: bool}
     */
    public function activeEngines(): array
    {
        return [
            'ia' => in_array($this->composerMode, ['ia', 'ia_dossiers'], true),
            'dossiers' => in_array($this->composerMode, ['dossiers', 'ia_dossiers'], true),
        ];
    }

    /**
     * TASK-1308 : le mode PRESELECTIONNE quand on repond a `$parent` — un
     * message humain retombe toujours sur `normal` (section 16 : le mode
     * Dossiers/IA reste possible, mais volontaire, jamais herite d'un
     * humain) ; un message IA herite son propre moteur.
     */
    private function defaultModeForParent(LoopMessage $parent): string
    {
        if ($parent->type !== 'ai') {
            return 'normal';
        }

        return match ($this->resolvedAiMode($parent)) {
            'rag' => 'dossiers',
            'llm_rag' => 'ia_dossiers',
            default => 'ia',
        };
    }

    /**
     * TASK-1308 : `ai_mode` est le discriminant canonique ('llm'|'rag'),
     * ecrit par les deux moteurs unifies. Les messages IA anterieurs a cette
     * TASK n'ont pas cette cle : leur `action` historique (knowledge /
     * slash_ia / continuation / ask / answer) permet de deriver le meme
     * discriminant sans migration de donnees.
     *
     * TASK-1309 : troisieme valeur `llm_rag` (mode IA + Dossiers). Aucun
     * message anterieur ne peut la porter — elle n'a donc pas de derivation
     * historique, et n'en aura jamais besoin.
     */
    private function resolvedAiMode(LoopMessage $message): string
    {
        $mode = $message->metadata['ai_mode'] ?? null;

        if (in_array($mode, ['llm', 'rag', 'llm_rag'], true)) {
            return $mode;
        }

        $action = $message->metadata['action'] ?? null;

        return in_array($action, ['knowledge', 'slash_ia', 'continuation', 'dossiers'], true) ? 'rag' : 'llm';
    }

    /**
     * TASK-1308 : identite tenant-generique d'une bulle IA — jamais
     * « Facilitateur IA », jamais un nom d'Organization code en dur.
     */
    private function aiBubbleLabel(LoopMessage $message): string
    {
        $orgName = $this->loop->organization?->name ?? config('app.name', 'BouclePro');

        return $orgName.' · '.match ($this->resolvedAiMode($message)) {
            'rag' => __('loops.dossiers_mode_label'),
            'llm_rag' => __('loops.hybrid_mode_label'),
            default => __('loops.ia_mode_label'),
        };
    }

    public function updatedPhoto(): void
    {
        if (! $this->photo) {
            return;
        }
        $validator = validator(['photo' => $this->photo], ['photo' => 'image|max:10240']);
        if ($validator->fails()) {
            $this->photo = null;
            $this->addError('photo', __('messages.invalid_file'));
        }
    }

    /**
     * Whether this person may add to the conversation right now.
     *
     * Was six copies of the same three conditions. The fourth — an archived Loop
     * is read-only — would have had to be added to all six, and the next
     * contributor would have had five chances to forget it.
     *
     * Reading is unaffected: `$isMember` still gates what the panel shows, so an
     * archived Loop keeps its history visible to the people who could see it.
     */
    private function canContribute(?User $user): bool
    {
        return $user !== null
            && ! $user->isDeactivated()
            && $this->isMember
            && app(LoopLifecycleService::class)->isWritable($this->loop);
    }

    public function sendMessage(LoopMessageService $service): void
    {
        $this->validate([
            'body' => 'required_without:photo|string|max:5000',
            'photo' => 'nullable|image|max:10240',
        ], [
            'body.required_without' => __('messages.body_or_image_required'),
        ]);

        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        // TASK-1308 : le moteur du tour est le mode EXPLICITE du composeur,
        // jamais un texte special dans le corps (`/ia` retire) ni une
        // auto-detection du parent (`continuationParent()` retiree). Une
        // Boucle agent exclut les deux moteurs : son agent (T-2) repond deja
        // a chaque message — une seconde IA serait une seconde depense.
        $mode = $this->loop->isAiAgent() ? 'normal' : $this->composerMode;

        if ($mode !== 'normal' && trim($this->body) === '') {
            // Une image seule ne pose pas de question : les modes IA et
            // Dossiers exigent un texte, la meme regle que l'ancien modal
            // Dossiers (`loops.knowledge_question_required`).
            $this->addError('body', __('loops.knowledge_question_required'));

            return;
        }

        // TASK-1311 : publication du message humain PUIS reponse du moteur —
        // les deux moities d'un meme tour, qui doivent tenir sous un seul
        // verrou. Rendu ici sous forme de fermeture pour cette seule raison ;
        // le corps n'a pas change.
        $tour = function () use ($service, $mode, $user): bool {
            try {
                $imagePath = null;

                if ($this->photo) {
                    $imagePath = $this->storeImage($this->photo, 'loop-messages');
                    $this->photo = null;
                }

                $url = UrlPreviewService::extractFirstUrl($this->body);
                $preview = $url ? app(UrlPreviewService::class)->fetchPreview($url) : null;

                $metadata = $preview !== null ? ['url_preview' => $preview] : null;

                if ($mode !== 'normal') {
                    $metadata = ($metadata ?? []) + ['requested_mode' => $mode];
                }

                $question = trim($this->body);

                $message = $service->sendUserMessage($this->loop, $user, $this->body, $metadata, $this->replyToMessageId, $imagePath);
                $this->body = '';
                $this->cancelReply();
            } catch (\RuntimeException) {
                $this->addError('body', 'Impossible d\'envoyer le message.');

                return false;
            }

            if ($question !== '') {
                match ($mode) {
                    'ia' => $this->respondWithAi($message, $question, $user),
                    'dossiers' => $this->respondWithDossiers($message, $question, $user),
                    // TASK-1309 : un SEUL tour, un seul appel de generation —
                    // « IA + Dossiers » n'est pas « IA puis Dossiers », ce serait
                    // deux reponses et deux depenses.
                    'ia_dossiers' => $this->respondWithHybrid($message, $question, $user),
                    default => null,
                };
            }

            return true;
        };

        if ($mode === 'normal') {
            // Aucun moteur, aucune depense : rien a verrouiller. Un tour NORMAL
            // doit rester exactement ce qu'il etait.
            if (! $tour()) {
                return;
            }
        } else {
            // TASK-1311 : le verrou est pris ICI, AVANT que le message humain
            // n'existe.
            //
            // Le poser seulement dans le service bloquerait bien la seconde
            // generation, mais les DEUX messages humains auraient deja ete
            // publies : le fil mentirait sur ce que l'utilisateur a fait, et le
            // contrat produit dit « double clic -> UN message humain ».
            //
            // `AiTurnLock` est reentrant dans une meme requete : le service
            // reprendra ce meme verrou sans echouer sur lui-meme. Et il garde
            // SA prise — c'est elle, et non celle-ci, qui protege le FAB, le
            // chemin herite et tout appel forge. L'UI n'est jamais la garantie.
            try {
                if (! AiTurnLock::run($this->loop, $user, $tour)) {
                    return;
                }
            } catch (\RuntimeException $exception) {
                // Un tour est deja en cours pour ce membre dans cette Boucle,
                // ou ce declencheur a deja sa reponse. Le message humain n'a PAS
                // ete cree : c'est tout l'interet de verrouiller si tot.
                $this->addError('body', $exception->getMessage());

                return;
            }
        }

        $this->syncNewerMessages();
        $this->dispatch('message-sent');
    }

    /**
     * Mode IA du composeur unifie (TASK-1308) : reponse LLM directe au
     * message HUMAIN deja persiste. Le contexte de fil (reply_to_id, s'il
     * existe) est construit par `ChatLoopAiService::respondInThread()` via
     * `AiConversationContextBuilder` — jamais reconstruit ici.
     */
    private function respondWithAi(LoopMessage $message, string $question, User $user): void
    {
        try {
            app(ChatLoopAiService::class)->respondInThread($this->loop, $user, $question, $message);
        } catch (\RuntimeException $exception) {
            // AiRefusedException comprise : son message est le message
            // produit (credit epuise, budget atteint, IA non configuree).
            $this->addError('body', $exception->getMessage());
        }
    }

    /**
     * Mode Dossiers du composeur unifie (TASK-1308, ex-« /ia » T-1299/T-1300).
     *
     * La chaine est celle de « Consulter les Dossiers » (T-1, TASK-1297) :
     * RAG Loop-scoped (TASK-1294), garde economique existante, sources
     * publiques filtrees par `KnowledgeAnswer::publicSource()`. Le message
     * humain etant deja dans le fil, tout echec ici — refus economique,
     * panne provider, aucune source — le CONSERVE et ne publie aucune
     * fausse reponse : l'auteur seul est prevenu, dans le composeur.
     */
    private function respondWithDossiers(LoopMessage $message, string $question, User $user): void
    {
        try {
            $answer = app(LoopKnowledgeAnswerService::class)
                ->answer($this->loop, $user, $question, inThreadTrigger: $message);

            if ($answer->interactionId === null) {
                // Zero source pertinente : rien n'a coute, rien n'est publie
                // (principe T-1) — mais l'auteur doit savoir pourquoi le fil
                // reste muet.
                $this->addError('body', __('loops.knowledge_no_sources'));
            }
        } catch (\RuntimeException $exception) {
            $this->addError('body', $exception->getMessage());
        }
    }

    /**
     * Mode « IA + Dossiers » du composeur unifie (TASK-1309).
     *
     * Meme chaine que le mode Dossiers — meme service, meme RAG loop-scoped,
     * meme garde economique, meme publication — avec la capability
     * `loop_hybrid_answer` et son prompt dedie. Une seule difference visible
     * ici : le mode peut repondre sans aucune source documentaire, donc
     * `interactionId === null` (le refus « zero source ») ne s'y produit
     * jamais ; seuls les refus economiques et les pannes provider restent, et
     * ils conservent le message humain exactement comme les deux autres modes.
     */
    private function respondWithHybrid(LoopMessage $message, string $question, User $user): void
    {
        try {
            app(LoopKnowledgeAnswerService::class)
                ->answerHybrid($this->loop, $user, $question, inThreadTrigger: $message);
        } catch (\RuntimeException $exception) {
            $this->addError('body', $exception->getMessage());
        }
    }

    /**
     * TASK-1310 : ouvre le brouillon « Ajouter au Dossier » pour une bulle IA.
     *
     * Rien n'est ecrit : on prepare un titre et un contenu PRE-REMPLIS, que
     * l'humain relit et modifie avant d'enregistrer. Le message est relu DANS
     * cette Boucle — un identifiant venu du front n'ouvre jamais une bulle
     * d'ailleurs — et l'eligibilite est celle du service, jamais une seconde
     * regle locale.
     */
    public function startCapitalization(string $messageId, LoopAnswerCapitalizationService $service): void
    {
        $user = auth()->user();

        if (! $this->canContribute($user)) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->first();

        if ($message === null || ! $service->isCapitalizable($this->loop, $message)) {
            return;
        }

        $dossier = $service->defaultDossier($this->loop, $user);

        if ($dossier === null) {
            $this->addError('capitalizeDossierId', __('loops.capitalize_no_dossier'));

            return;
        }

        $this->resetErrorBag();
        $this->capitalizeFlash = '';
        $this->capitalizingMessageId = $message->id;
        $this->capitalizeDossierId = (string) $dossier->id;
        $this->capitalizeTitle = $service->suggestedTitle($message);
        $this->capitalizeContent = (string) $message->body;
    }

    public function cancelCapitalization(): void
    {
        $this->capitalizingMessageId = null;
        $this->capitalizeTitle = '';
        $this->capitalizeContent = '';
        $this->capitalizeDossierId = '';
        $this->resetErrorBag();
    }

    /**
     * Enregistre le brouillon relu comme Article du Dossier.
     *
     * Le composant ne decide RIEN : il transmet ce que l'humain a valide et
     * traduit un refus. Toutes les gardes — tenant, Boucle, eligibilite,
     * permission d'ecriture, appartenance du Dossier au perimetre ecrivable —
     * vivent dans le service, qui est aussi ce qu'atteint une requete forgee.
     */
    public function saveCapitalization(LoopAnswerCapitalizationService $service): void
    {
        $user = auth()->user();

        if (! $this->canContribute($user) || $this->capitalizingMessageId === null) {
            return;
        }

        $message = LoopMessage::where('id', $this->capitalizingMessageId)
            ->where('loop_id', $this->loop->id)
            ->first();

        if ($message === null) {
            $this->cancelCapitalization();

            return;
        }

        try {
            $post = $service->capitalize(
                $this->loop,
                $user,
                $message,
                $this->capitalizeDossierId,
                $this->capitalizeTitle,
                $this->capitalizeContent,
            );
        } catch (\RuntimeException $exception) {
            $this->addError('capitalizeTitle', $exception->getMessage());

            return;
        }

        $dossierName = (string) Dossier::query()->whereKey($this->capitalizeDossierId)->value('name');

        // Fermer AVANT d'annoncer : le brouillon disparait, donc une seconde
        // soumission triviale n'a plus d'etat a renvoyer.
        $this->cancelCapitalization();

        $this->dispatch('loop-article-created', articleId: $post->id, title: $post->title);
        $this->capitalizeFlash = __('loops.capitalize_saved', ['dossier' => $dossierName]);
    }

    public function removePhoto(): void
    {
        $this->photo = null;
    }

    public function pinnedMessage(): ?LoopMessage
    {
        return $this->loop->messages()
            ->pinned()
            ->notDeleted()
            ->with('sender')
            ->first();
    }

    public function pinMessage(string $messageId): void
    {
        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->first();

        if (! $message) {
            return;
        }

        if ($message->isDeleted()) {
            return;
        }

        LoopMessage::where('loop_id', $this->loop->id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);

        $message->pin($user);
    }

    public function loadOlderMessages(): void
    {
        if ($this->loadedMessageIds === []) {
            $this->loadInitialMessages();
            $this->dispatch('older-messages-loaded');

            return;
        }

        $oldest = $this->oldestLoadedMessage();

        if (! $oldest) {
            $this->hasOlderMessages = false;
            $this->dispatch('older-messages-loaded');

            return;
        }

        $olderIds = $this->olderThan($oldest)
            ->limit($this->messagePageSize)
            ->pluck('id')
            ->reverse()
            ->values()
            ->all();

        $this->loadedMessageIds = array_values(array_unique(array_merge($olderIds, $this->loadedMessageIds)));
        $this->sortLoadedMessageIds();
        $this->updateHasOlderMessages();
        $this->dispatch('older-messages-loaded');
    }

    public function showMessageInThread(string $messageId): void
    {
        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();

        if (! $message) {
            return;
        }

        if (! in_array($message->id, $this->loadedMessageIds, true)) {
            $nearbyIds = $this->messagesAround($message)
                ->pluck('id')
                ->all();

            $this->loadedMessageIds = array_values(array_unique(array_merge($this->loadedMessageIds, $nearbyIds, [$message->id])));
            $this->sortLoadedMessageIds();
            $this->updateHasOlderMessages();
        }

        $this->dispatch('scroll-to-message', messageId: $message->id);
    }

    public function editMessage(string $messageId): void
    {
        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();

        if (! $message || ! $message->isEditableBy($user)) {
            return;
        }

        $this->editingMessageId = $message->id;
        $this->editingBody = $message->body;
    }

    public function cancelEdit(): void
    {
        $this->editingMessageId = null;
        $this->editingBody = '';
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editingBody' => 'required|string|max:5000',
        ]);

        $user = auth()->user();
        if (! $this->canContribute($user) || $this->editingMessageId === null) {
            return;
        }

        $message = LoopMessage::where('id', $this->editingMessageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();

        if (! $message) {
            $this->cancelEdit();

            return;
        }

        try {
            $url = UrlPreviewService::extractFirstUrl($this->editingBody);
            $preview = $url ? app(UrlPreviewService::class)->fetchPreview($url) : null;
            $metadata = $preview !== null ? ['url_preview' => $preview] : null;

            app(LoopMessageService::class)->updateUserMessage($this->loop, $message, $user, $this->editingBody, $metadata);
            $this->cancelEdit();
        } catch (\RuntimeException) {
            $this->addError('editingBody', __('messages.edit_failed'));
        }
    }

    public function deleteMessage(string $messageId): void
    {
        $user = auth()->user();
        if (! $user || $user->isDeactivated()) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();

        if (! $message) {
            return;
        }

        try {
            app(LoopMessageService::class)->deleteMessage($this->loop, $message, $user);

            if ($this->editingMessageId === $message->id) {
                $this->cancelEdit();
            }
        } catch (\RuntimeException) {
            return;
        }
    }

    public function unpinMessage(): void
    {
        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        LoopMessage::where('loop_id', $this->loop->id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);
    }

    public function toggleReaction(string $messageId, string $reactionType): void
    {
        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        if (! in_array($reactionType, Reaction::REACTION_TYPES, true)) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->first();

        if (! $message) {
            return;
        }

        if ($message->isDeleted()) {
            return;
        }

        $existing = Reaction::where('user_id', $user->id)
            ->where('reactionable_id', $message->id)
            ->where('reactionable_type', LoopMessage::class)
            ->first();

        if ($existing) {
            if ($existing->reaction_type === $reactionType) {
                $existing->delete();
            } else {
                $existing->update(['reaction_type' => $reactionType]);
            }
        } else {
            Reaction::create([
                'organization_id' => $message->organization_id,
                'user_id' => $user->id,
                'reactionable_id' => $message->id,
                'reactionable_type' => LoopMessage::class,
                'reaction_type' => $reactionType,
            ]);
        }
    }

    private function storeImage($file, string $subdirectory): string
    {
        $img = Image::decode($file);
        $img->scaleDown(1200, 800);

        $filename = Str::uuid()->toString().'.webp';
        $relativePath = 'message-images/'.$this->loop->organization_id.'/'.$subdirectory.'/'.$filename;

        Storage::disk('public')->put($relativePath, (string) $img->encode(new WebpEncoder(quality: 80)));

        return $relativePath;
    }

    /**
     * Une Card voisine vient de publier une activite dans ce fil.
     *
     * La methode ne fait **rien** : le seul fait d'avoir ete appelee provoque un
     * nouveau rendu, et `render()` appelle deja `syncNewerMessages()`, qui
     * ramene ce qui est plus recent et dedoublonne par identifiant. Y ajouter
     * une requete reviendrait a ecrire deux fois la meme regle.
     *
     * ChatLoop rattrapait deja ces messages au battement suivant de son
     * `wire:poll.3s`. Ce que l'evenement supprime, c'est l'attente — pas une
     * absence. Le sondage periodique reste : il sert les messages des autres
     * personnes, qu'aucune Card locale ne peut annoncer.
     *
     * L'identifiant de Boucle est verifie : plusieurs Boucles peuvent vivre
     * dans un meme onglet, et un fil ne se rafraichit que pour la sienne.
     */
    #[On('loop-activity-published')]
    public function onLoopActivityPublished(?string $loopId = null): void
    {
        if ($loopId !== null && $loopId !== $this->loop->id) {
            $this->skipRender();
        }
    }

    public function render()
    {
        // **Sans adhesion, rien ne se lit.** `$loadedMessageIds` voyage dans le
        // snapshot : une personne retiree de la Boucle gardait sa liste, et
        // `syncNewerMessages()` y ajoutait consciencieusement les messages
        // postes apres son depart. Recalculer l'adhesion ne suffisait pas — il
        // fallait que la lecture en depende.
        if (! $this->isMember) {
            $this->loadedMessageIds = [];
        }

        $this->syncNewerMessages();

        $messages = $this->loop->messages()
            ->with('sender')
            ->with('replyTo.sender')
            ->with('reactions')
            ->whereIn('id', $this->loadedMessageIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $pinnedMessage = $this->pinnedMessage();

        $reactionData = [];
        $myReactions = [];
        $userId = auth()->id();

        foreach ($messages as $msg) {
            $counts = [];
            $myReaction = null;

            foreach ($msg->reactions as $reaction) {
                $type = $reaction->reaction_type;
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                if ($reaction->user_id === $userId) {
                    $myReaction = $type;
                }
            }

            $reactionData[$msg->id] = $counts;
            $myReactions[$msg->id] = $myReaction;
        }

        $requestedByNames = $this->requestedByNames($messages);
        $projectedRequests = $this->projectedRequests($messages);
        $projectedRequestUrls = $this->projectedRequestUrls($projectedRequests);
        $aiRoute = $this->aiRoute();
        $canDeleteMessages = $this->canDeleteDisplayedMessages();
        // La vue retire le compositeur plutot que d'accepter un message que
        // sendMessage() refusera : un refus silencieux passe pour une panne.
        $canContribute = $this->canContribute(auth()->user());
        // TASK-1308 : passe explicitement sous un nom DISTINCT de la
        // propriete publique `loop` — Livewire partage deja ses propres
        // proprietes publiques avec la vue par son propre mecanisme
        // (Utils::getPublicPropertiesDefinedOnSubclass + View::with()), qui
        // s'applique APRES le tableau rendu ici et en ecraserait toute
        // entree nommee `loop` (array_merge, la derniere valeur gagne).
        $viewLoop = $this->loop;

        // TASK-1310 : la vue lit une decision deja prise ici par le service —
        // jamais une regle d'eligibilite reimplantee en Blade, qui pourrait
        // diverger de celle que le serveur applique.
        //
        // TASK-1313 : deux questions DISTINCTES, la ou il n'y en avait qu'une.
        //
        //   `$capitalizableMessageIds` — cette BULLE se prete-t-elle au geste ?
        //   `$canCapitalize`           — cette PERSONNE a-t-elle le droit ?
        //
        // Les confondre revenait a cacher la fonctionnalite a qui n'y a pas
        // droit : un membre ordinaire ne pouvait pas meme SAVOIR qu'elle
        // existe. Elle doit etre decouvrable par tous, et refusee clairement a
        // qui ne peut pas — ce qui est une information, pas une frustration.
        //
        // L'affichage ne fait bien sur autorite sur rien :
        // `startCapitalization()` et `saveCapitalization()` reposent sur le
        // service, qui revalide tout.
        $capitalization = app(LoopAnswerCapitalizationService::class);
        $capitalizableMessageIds = [];
        $writableDossiers = collect();
        $canCapitalize = false;

        if ($canContribute && auth()->user()) {
            $writableDossiers = $capitalization->writableDossiers($this->loop, auth()->user());
            $canCapitalize = $writableDossiers->isNotEmpty();

            foreach ($messages as $msg) {
                if ($capitalization->isCapitalizable($this->loop, $msg)) {
                    $capitalizableMessageIds[] = $msg->id;
                }
            }
        }

        return view('livewire.loop-chat', compact(
            'messages',
            'viewLoop',
            'pinnedMessage',
            'reactionData',
            'myReactions',
            'requestedByNames',
            'projectedRequests',
            'projectedRequestUrls',
            'aiRoute',
            'canDeleteMessages',
            'canCapitalize',
            'canContribute',
            'capitalizableMessageIds',
            'writableDossiers',
        ));
    }

    /**
     * Charge toutes les demandes projetees en une requete, explicitement dans
     * le tenant de la Boucle. Une metadata malformee n'atteint jamais la
     * colonne UUID PostgreSQL et aucune requete ne part depuis Blade.
     *
     * @return Collection<string, ServiceRequest>
     */
    private function projectedRequests(Collection $messages): Collection
    {
        $ids = $messages
            ->filter(fn (LoopMessage $message) => $message->isServiceRequestProjection())
            ->map(fn (LoopMessage $message) => $message->metadata['service_request_id'])
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('organization_id', $this->loop->organization_id)
            ->whereIn('id', $ids)
            ->with('user')
            ->get()
            ->reject(fn (ServiceRequest $request) => $request->user?->isDeactivated() ?? true)
            ->keyBy('id');
    }

    /** @param Collection<string, ServiceRequest> $requests */
    private function projectedRequestUrls(Collection $requests): array
    {
        $slug = $this->loop->organization?->slug;

        return $requests->mapWithKeys(function (ServiceRequest $request) use ($slug): array {
            $url = $slug && Route::has('organization.requests.show')
                ? route('organization.requests.show', ['organization' => $slug, 'request' => $request])
                : route('requests.show', $request);

            return [$request->id => $url];
        })->all();
    }

    private function loadInitialMessages(): void
    {
        if (! $this->isMember) {
            $this->loadedMessageIds = [];

            return;
        }

        $this->loadedMessageIds = $this->loop->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->messagePageSize)
            ->pluck('id')
            ->reverse()
            ->values()
            ->all();

        $this->updateHasOlderMessages();
    }

    private function syncNewerMessages(): void
    {
        if (! $this->isMember) {
            return;
        }

        if ($this->loadedMessageIds === []) {
            $this->loadInitialMessages();

            return;
        }

        $newest = $this->newestLoadedMessage();

        if (! $newest) {
            $this->loadInitialMessages();

            return;
        }

        $newerIds = $this->newerThan($newest)
            ->pluck('id')
            ->all();

        if ($newerIds !== []) {
            $this->loadedMessageIds = array_values(array_unique(array_merge($this->loadedMessageIds, $newerIds)));
            $this->sortLoadedMessageIds();
        }
    }

    private function oldestLoadedMessage(): ?LoopMessage
    {
        return $this->loadedCursorMessage($this->loadedMessageIds[0] ?? null);
    }

    private function newestLoadedMessage(): ?LoopMessage
    {
        return $this->loadedCursorMessage($this->loadedMessageIds[array_key_last($this->loadedMessageIds)] ?? null);
    }

    private function loadedCursorMessage(?string $messageId): ?LoopMessage
    {
        if ($messageId === null) {
            return null;
        }

        return LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();
    }

    private function olderThan(LoopMessage $message)
    {
        return $this->loop->messages()
            ->where(function ($query) use ($message) {
                $query->where('created_at', '<', $message->created_at)
                    ->orWhere(function ($query) use ($message) {
                        $query->where('created_at', $message->created_at)
                            ->where('id', '<', $message->id);
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function newerThan(LoopMessage $message)
    {
        return $this->loop->messages()
            ->where(function ($query) use ($message) {
                $query->where('created_at', '>', $message->created_at)
                    ->orWhere(function ($query) use ($message) {
                        $query->where('created_at', $message->created_at)
                            ->where('id', '>', $message->id);
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function messagesAround(LoopMessage $message): Collection
    {
        $older = $this->olderThan($message)
            ->limit(15)
            ->get();

        $newer = $this->newerThan($message)
            ->limit(15)
            ->get();

        return $older
            ->push($message)
            ->merge($newer)
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    private function updateHasOlderMessages(): void
    {
        $oldest = $this->oldestLoadedMessage();
        $this->hasOlderMessages = $oldest !== null && $this->olderThan($oldest)->exists();
    }

    private function sortLoadedMessageIds(): void
    {
        $this->loadedMessageIds = LoopMessage::whereIn('id', $this->loadedMessageIds)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function canDeleteDisplayedMessages(): bool
    {
        $user = auth()->user();

        if (! $user || $user->isDeactivated()) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        if ($this->loop->organization_id !== $user->organization_id) {
            return false;
        }

        $role = LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');

        // Resolved centrally (CP5ter).
        $user = auth()->user();

        return $user !== null
            && app(LoopPermissionResolver::class)->can($user, $this->loop, 'chatloop.manage');
    }

    private function requestedByNames(Collection $messages): array
    {
        $requesterIds = $messages
            ->where('type', 'ai')
            ->map(fn (LoopMessage $message) => $message->metadata['requested_by'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($requesterIds->isEmpty()) {
            return [];
        }

        $names = User::query()
            ->whereIn('id', $requesterIds)
            ->get()
            ->keyBy('id')
            ->map(fn (User $user) => $user->publicDisplayName());

        $map = [];

        foreach ($messages as $message) {
            if ($message->type !== 'ai') {
                continue;
            }

            $requesterId = $message->metadata['requested_by'] ?? null;

            if ($requesterId !== null && $names->has($requesterId)) {
                $map[$message->id] = (string) $names->get($requesterId);
            }
        }

        return $map;
    }
}
