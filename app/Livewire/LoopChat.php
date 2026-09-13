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
use App\Services\ChatLoop\AiResponseExplanationService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Knowledge\ClaimPatch;
use App\Services\Knowledge\HumanClaimCorrection;
use App\Services\Knowledge\LoopMemoryDigest;
use App\Services\LoopMessageService;
use App\Services\Loops\LoopAnswerCapitalizationService;
use App\Services\Loops\LoopLifecycleService;
use App\Services\UrlPreviewService;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\LoopAiTurnSignal;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class LoopChat extends Component
{
    use WithFileUploads;

    public Loop $loop;

    public string $body = '';

    /**
     * L'adhésion ACTIVE de ce spectateur, conclue à chaque requête par
     * {@see self::booted()}.
     *
     * `#[Locked]` — REMÉDIATION R2 (audit Codex F5). Livewire applique les
     * mises à jour du client APRÈS `booted()` : une requête portant à la fois
     * `isMember: true` et un appel de méthode écrasait donc la conclusion
     * serveur pour toute la durée de cet appel. Aucune écriture n'en découlait
     * — `AiResponseExplanationService::canView()` et `HumanClaimCorrection`
     * relisent l'adhésion en base — mais la couche d'honnêteté d'affichage,
     * elle, s'ouvrait, et le refus final se présentait comme un conflit de
     * concurrence au lieu d'un refus de droit. Ce verrou ferme l'honnêteté
     * Livewire ; il ne déplace AUCUNE autorité métier vers la vue.
     */
    #[Locked]
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

    /**
     * TASK-1549 : les deux gestes de correction, et le seul endroit où cette
     * liste existe. `startCorrection()` comme `submitCorrection()` s'y
     * réfèrent — aucune des deux ne fait autorité toute seule.
     */
    public const MODE_RETRACT = 'retract';

    public const MODE_UPDATE = 'update';

    /** @var list<string> */
    private const CORRECTION_MODES = [self::MODE_RETRACT, self::MODE_UPDATE];

    /**
     * TASK-1550 : les deux ANCRES depuis lesquelles un énoncé mémoire peut être
     * corrigé — et il n'y en aura pas de troisième sans décision.
     *
     * L'ancre n'est pas un second chemin de correction : le moteur
     * (`HumanClaimCorrection`), les gardes, les trois issues et les clés de
     * langue sont les mêmes. Ce qui change, et seulement cela, c'est **comment
     * l'énoncé est adressé** :
     *
     *  - `why`    — une bulle IA et sa référence de citation (`S1`…), T1549 ;
     *  - `digest` — la carte « Depuis cet échange… » et un jeton d'énoncé,
     *               opaque et lié au sujet ({@see LoopMemoryDigest::jeton()}).
     *
     * Chaque ancre a sa propre carte de versions figées, et la soumission ne
     * lit QUE celle de l'ancre du formulaire ouvert.
     */
    public const ANCRE_WHY = 'why';

    public const ANCRE_DIGEST = 'digest';

    /** @var list<string> */
    private const ANCRES = [self::ANCRE_WHY, self::ANCRE_DIGEST];

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

    /**
     * TASK-1328 : panneau « Pourquoi cette réponse ? ». `null` = fermé. Un
     * seul panneau à la fois, état côté serveur — il survit donc au
     * `wire:poll` de la page (même motif que `$capitalizingMessageId`).
     * `$whyPanel` ne contient QUE ce que le service a jugé montrable à CE
     * spectateur : il voyage dans le snapshot, qui est lisible côté client.
     *
     * `#[Locked]` — REMÉDIATION R2 (audit Codex F1). Cette propriété désigne
     * la bulle dont les citations seront RE-RÉSOLUES à la soumission d'une
     * correction : c'est elle, et non la référence affichée, qui décide quel
     * énoncé est muté. Laissée réinscriptible, elle rendait le verrou de
     * `$correctingRef` inopérant — un formulaire ouvert sur la bulle X
     * rétractait l'énoncé cité par la bulle Y dès que les deux nommaient leur
     * source `S1` et partageaient un numéro de version, avec ACCUSÉ DE
     * RÉCEPTION POSITIF. Le verrou ne suffit pas à lui seul : voir
     * `$whyMemoryVersions`, dont la clé porte désormais le message.
     */
    #[Locked]
    public ?string $whyMessageId = null;

    public ?array $whyPanel = null;

    /**
     * TASK-1549 : le spectateur peut-il CONTRIBUER ici — calculé à l'ouverture
     * du panneau, côté serveur, pour que la vue n'offre jamais un geste
     * impossible. Les vraies gardes restent dans le service à la soumission.
     */
    public bool $whyCanCorrect = false;

    /**
     * TASK-1549 : la version de chaque énoncé mémoire AU MOMENT où le panneau
     * l'a affiché. C'est la version que la personne a LUE — l'idempotence de
     * T1548 corrige une VERSION, jamais un sujet. `#[Locked]` : le poll ne la
     * fait pas bouger, et le client ne peut pas la forger.
     *
     * REMÉDIATION R2 (audit Codex F1) — la clé porte le MESSAGE, pas seulement
     * la référence : `"<message_id>|S1" => version`.
     *
     * Indexée par la seule référence affichée, cette carte ne disait pas DE
     * QUELLE BULLE la version venait. Deux bulles qui nomment toutes deux leur
     * source `S1`, sur deux sujets différents en même version, satisfaisaient
     * donc trivialement la garde d'appariement : `S1 == S1`, `1 == 1`. La
     * garde comparait deux valeurs vraies pour conclure à un couple qu'elle
     * n'avait jamais vérifié.
     *
     * La clé composite fait porter au couple ce qui l'identifie réellement.
     * C'est la défense en profondeur du verrou ci-dessus : même si
     * `$whyMessageId` redevenait réinscriptible, `"<Y>|S1"` serait absent
     * d'une carte figée sur X, et rien ne s'écrirait.
     *
     * @var array<string, int>
     */
    #[Locked]
    public array $whyMemoryVersions = [];

    /**
     * TASK-1550 : la carte « Depuis cet échange, BouclePro a retenu… ».
     * `null` = rien à dire, et c'est le cas le plus fréquent.
     *
     * ÉTAT du composant, recalculé à chaque render — « l'état courant des
     * claims au rendu » est une exigence produit, pas un détail : la carte ne
     * doit jamais montrer une mémoire périmée.
     *
     * SAUF pendant qu'un formulaire de correction de la carte est ouvert. Le
     * `wire:poll.3s` ferait alors disparaître l'entrée sous la main de la
     * personne — avec le texte qu'elle est en train d'écrire. Gelée, la carte
     * reste celle qu'elle a LUE, et une version devenue périmée est refusée par
     * l'idempotence de T1548 **avant toute écriture** (`version_perimee` rend
     * `message_id = null`) : le conflit est donc « rien n'a été enregistré »,
     * jamais un message public orphelin.
     */
    public ?array $digestPanel = null;

    /**
     * La version de chaque énoncé de la carte, au moment où la carte l'a
     * affiché — même rôle que `$whyMemoryVersions`, même verrou.
     *
     * La clé est le jeton lui-même : il est déjà dérivé du sujet ET de la
     * Boucle, donc il identifie ce qu'il adresse. C'est ce qui rend ici
     * structurellement impossible la confusion que le triplet de T1549 doit
     * garder : deux entrées ne peuvent pas partager une adresse.
     *
     * @var array<string, int>
     */
    #[Locked]
    public array $digestMemoryVersions = [];

    /**
     * La carte a-t-elle été renvoyée pour CETTE session de composant ?
     *
     * Ce n'est PAS un accusé de lecture et cela ne prétend pas en être un :
     * rien n'est persisté, un rechargement la fait revenir. Le produit ne porte
     * aucune position de lecture de Boucle (mesure du Gate SPEC), et cette
     * TASK n'en crée pas.
     */
    public bool $digestDismissed = false;

    /**
     * Le spectateur peut-il CONTRIBUER ici — recalculé à chaque render, même
     * rôle et même limite que `$whyCanCorrect` : de l'honnêteté d'affichage,
     * jamais une garde.
     */
    public bool $digestCanCorrect = false;

    /**
     * TASK-1550 : l'ANCRE du formulaire ouvert — `why` ou `digest`.
     *
     * `#[Locked]` pour la même raison que l'adresse et la version : elle décide
     * quelle carte figée fait autorité et comment l'énoncé est re-résolu côté
     * serveur. Réinscriptible, elle aurait permis de soumettre une adresse de
     * carte contre la carte du panneau, ou l'inverse.
     */
    #[Locked]
    public ?string $correctingAnchor = null;

    /**
     * TASK-1549 : formulaire « Corriger ». `null` = fermé. L'adresse est la
     * référence de source AFFICHÉE (`S1`…) — jamais `subject_key`, qui est
     * une identité choisie par le modèle : le service la RETROUVE parmi les
     * énoncés actifs, elle n'entre pas dans le snapshot Livewire.
     *
     * `#[Locked]` — REMÉDIATION TASK-1549. Sans ce verrou, l'ADRESSE était
     * réécrivable par le client alors que la VERSION, elle, était figée : le
     * couple se décorrélait, et un formulaire ouvert sur `S1` rétractait `S2`
     * dès que les deux sujets partageaient un numéro de version — avec un
     * ACCUSÉ DE RÉCEPTION POSITIF.
     *
     * Le coût n'était pas seulement un mauvais sujet muté. La forge aurait
     * créé SUR S2 une frontière humaine AUTO-COHÉRENTE MAIS FAUSSE, fondée sur
     * un message qui parle de S1. Elle aurait ensuite influencé
     * l'apprentissage et les corrections FUTURS DE S2 — et potentiellement les
     * `ADD` Loop-wide soumis au compromis fail-closed.
     *
     * Rien dans les données ne permettrait de voir l'écart : la frontière est
     * cohérente avec elle-même, seul son rattachement est faux.
     */
    #[Locked]
    public ?string $correctingRef = null;

    /**
     * La bulle DEPUIS LAQUELLE le formulaire a été ouvert, figée elle aussi
     * (REMÉDIATION R2, audit Codex F1). La soumission exige que la bulle
     * affichée soit encore celle-ci : l'adresse, la version ET le message
     * forment un TRIPLET, capturé ensemble et revérifié ensemble.
     */
    #[Locked]
    public ?string $correctingMessageId = null;

    /** La version LUE, figée à l'ouverture du formulaire. */
    #[Locked]
    public int $correctingVersion = 0;

    /**
     * `retract` (« ce n'est plus vrai ») ou `update` (« c'est devenu… »).
     *
     * `#[Locked]` — REMÉDIATION R2 (audit Codex F2). Ce champ décide entre une
     * réécriture et une SUPPRESSION d'énoncé. `startCorrection()` contrôlait
     * son domaine, `submitCorrection()` ne le refaisait pas : une valeur hors
     * domaine retombait silencieusement sur `retract` — l'opération
     * destructrice — en sautant au passage la validation du nouvel énoncé, et
     * en rendant un ACCUSÉ DE RÉCEPTION POSITIF pour un geste que la personne
     * n'avait pas demandé. Le mode se change désormais par une ACTION
     * ({@see self::setCorrectionMode()}), jamais par liaison de propriété.
     */
    #[Locked]
    public string $correctingMode = self::MODE_RETRACT;

    /** La phrase humaine — obligatoire : c'est la PREUVE, visible dans la Boucle. */
    public string $correctionText = '';

    /** Le nouvel énoncé, mode `update` seulement. */
    public string $correctionNewText = '';

    /**
     * ACK de correction en propriété publique, jamais en flash de session —
     * même piège T1213 que `$capitalizeFlash` : le `wire:poll` consommerait
     * le flash avant que la personne ne le lise.
     */
    public string $correctionFlash = '';

    /**
     * L'un des DEUX états de conflit, en langage humain — jamais une raison
     * technique. Avant message : rien n'est enregistré. Après message : le
     * message humain reste, la mémoire n'est pas corrigée.
     */
    public ?string $correctionConflict = null;

    /**
     * TASK-1550 : l'ancre qui a PRODUIT l'ACK ou le conflit affiché.
     *
     * Deux surfaces peuvent désormais rendre le même message, et sans cette
     * marque elles le rendraient TOUTES LES DEUX : une correction faite depuis
     * le panneau « Pourquoi ? » aurait fait apparaître son accusé de réception
     * dans la carte aussi, comme si la carte avait été corrigée. Le résultat
     * d'un geste s'affiche là où le geste a été fait.
     */
    public ?string $correctionFeedbackAnchor = null;

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
        return $this->aiIdentity($this->resolvedAiMode($message));
    }

    /**
     * TASK-1316 : l'identite affichee d'un moteur — « Organization · Mode ».
     *
     * Extraite d'`aiBubbleLabel()` parce qu'elle sert desormais AUSSI a une
     * ligne qui n'a pas de message a lui : le signal « une reponse est en
     * cours ». Un tour annonce sous une identite puis publie sous une autre
     * serait une promesse trahie ; il n'y a donc qu'un seul endroit ou elle
     * s'ecrit.
     *
     * @param  string  $aiMode  le discriminant canonique T1312 ('llm'|'rag'|'llm_rag')
     */
    private function aiIdentity(string $aiMode): string
    {
        $orgName = $this->loop->organization?->name ?? config('app.name', 'BouclePro');

        return $orgName.' · '.match ($aiMode) {
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

    /**
     * TASK-1328 : ouvre le panneau « Pourquoi cette réponse ? » d'une bulle
     * IA. Le composant ne décide RIEN : le service refait toutes les gardes
     * (tenant, adhésion active, bulle vivante de CETTE Boucle) et rend
     * `null` à qui n'a pas à voir — y compris une requête forgée qui
     * atteindrait cette méthode directement. Ouvrir n'écrit rien, nulle
     * part : l'explication est une lecture.
     */
    public function showWhy(string $messageId, AiResponseExplanationService $service): void
    {
        $user = auth()->user();

        if (! $user || ! $this->isMember) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->first();

        if ($message === null) {
            return;
        }

        $panel = $service->explain($this->loop, $message, $user);

        if ($panel === null) {
            return;
        }

        // REMÉDIATION TASK-1549 : aucune correction ne survit à un changement
        // de contexte. `closeWhy()` fermait déjà le formulaire, `showWhy()` ne
        // le faisait PAS — ouvrir « Pourquoi ? » sur une autre bulle laissait
        // donc le formulaire ouvert, collé à un énoncé différent, en gardant
        // la version figée de l'ANCIEN. Sans la moindre forge : la personne
        // lisait une assertion et en corrigeait une autre, ou lisait « ce point
        // a changé entre-temps » alors que rien n'avait changé.
        $this->cancelCorrection();

        $this->whyMessageId = (string) $message->id;
        $this->applyWhyPanel((string) $message->id, $panel);
        $this->whyCanCorrect = $this->canContribute($user);
        $this->correctionFlash = '';
        $this->correctionConflict = null;
        $this->correctionFeedbackAnchor = null;
    }

    public function closeWhy(): void
    {
        $this->whyMessageId = null;
        $this->whyPanel = null;
        $this->whyCanCorrect = false;
        $this->whyMemoryVersions = [];
        $this->correctionFlash = '';
        $this->correctionConflict = null;
        $this->correctionFeedbackAnchor = null;
        $this->cancelCorrection();
    }

    /**
     * TASK-1549 : poser le panneau ET figer la version de chaque énoncé
     * mémoire que la personne est en train de LIRE — c'est cette version-là,
     * et aucune autre, qu'une correction visera.
     *
     * REMÉDIATION R2 (F1) : la carte est indexée par BULLE ET par référence.
     * Le message est passé explicitement plutôt que relu sur `$this` — ce que
     * la carte décrit, c'est le panneau qu'on est en train de poser, jamais
     * l'état courant du composant.
     *
     * @param  array<string, mixed>  $panel
     */
    private function applyWhyPanel(string $messageId, array $panel): void
    {
        $this->whyPanel = $panel;

        $versions = [];

        foreach ($panel['ledger']['memory']['entries'] ?? [] as $entry) {
            // Seules les entrées CORRIGEABLES entrent dans la carte : une
            // référence cross-Loop ou rétractée n'a pas de version à viser,
            // et `startCorrection` la refusera donc d'emblée — même forgée.
            if (is_array($entry)
                && is_string($entry['ref'] ?? null)
                && is_int($entry['subject_version'] ?? null)
                && ($entry['can_correct'] ?? false) === true) {
                $versions[$this->cleMemoire($messageId, $entry['ref'])] = $entry['subject_version'];
            }
        }

        $this->whyMemoryVersions = $versions;
    }

    /**
     * L'adresse d'un énoncé mémoire DANS LE PANNEAU D'UNE BULLE DONNÉE.
     *
     * Une référence (`S1`) n'est unique qu'à l'intérieur d'une réponse : c'est
     * un numéro d'ordre de citation, pas une identité. Le couple bulle +
     * référence, lui, désigne une seule chose.
     */
    private function cleMemoire(string $messageId, string $ref): string
    {
        return $messageId.'|'.$ref;
    }

    /**
     * TASK-1328 : verdict humain explicite (utile / à améliorer) sur la
     * réponse dont le panneau est ouvert — la primitive TASK-1256, un
     * jugement par personne, remplacé s'il est redonné. Toutes les gardes
     * vivent dans le service.
     */
    public function submitWhyFeedback(string $verdict, AiResponseExplanationService $service): void
    {
        $user = auth()->user();

        if (! $user || ! $this->isMember || $this->whyMessageId === null) {
            return;
        }

        $message = LoopMessage::where('id', $this->whyMessageId)
            ->where('loop_id', $this->loop->id)
            ->first();

        if ($message === null) {
            return;
        }

        if ($service->submitFeedback($this->loop, $message, $user, $verdict)) {
            $this->whyPanel = $service->explain($this->loop, $message, $user) ?? $this->whyPanel;
        }
    }

    /**
     * TASK-1549 : ouvrir le formulaire « Corriger » sur un énoncé mémoire du
     * panneau. La version visée est copiée depuis `$whyMemoryVersions` — la
     * version que la personne a LUE à l'ouverture du panneau, figée côté
     * serveur — jamais relue au render : le `wire:poll.3s` la ferait bouger
     * sous la main, et la personne corrigerait une version qu'elle n'a pas
     * lue.
     *
     * TASK-1550 : `$ancre` ouvre le MÊME formulaire depuis la carte « Depuis
     * cet échange… ». Le paramètre est en dernière position et vaut `why` par
     * défaut — la signature de T1549 est donc inchangée, et son contrat avec
     * elle. Un mode ou une ancre hors domaine n'ouvre rien.
     */
    public function startCorrection(string $ref, string $mode, string $ancre = self::ANCRE_WHY): void
    {
        $user = auth()->user();

        // REMÉDIATION TASK-1549 : un droit tombé se DIT. Un `return` muet
        // laissait la personne cliquer dans le vide — « un refus silencieux
        // passe pour une panne » (même motif que le composeur).
        if (! $this->canContribute($user)) {
            $this->cancelCorrection();
            $this->whyCanCorrect = false;
            $this->digestCanCorrect = false;
            $this->correctionConflict = __('loops.correct_refused_right');
            $this->correctionFeedbackAnchor = in_array($ancre, self::ANCRES, true) ? $ancre : self::ANCRE_WHY;

            return;
        }

        if (! in_array($mode, self::CORRECTION_MODES, true) || ! in_array($ancre, self::ANCRES, true)) {
            return;
        }

        if ($ancre === self::ANCRE_WHY && $this->whyMessageId === null) {
            return;
        }

        $cle = $ancre === self::ANCRE_DIGEST
            ? $ref
            : $this->cleMemoire((string) $this->whyMessageId, $ref);

        $carte = $ancre === self::ANCRE_DIGEST ? $this->digestMemoryVersions : $this->whyMemoryVersions;

        if (! array_key_exists($cle, $carte)) {
            return;
        }

        // Le QUADRUPLET est capturé ICI, ensemble : l'ancre lue, la bulle lue
        // (pour l'ancre `why`), la référence lue, la version lue. C'est lui, et
        // lui seul, que la soumission aura le droit de mettre à exécution.
        $this->correctingAnchor = $ancre;
        $this->correctingMessageId = $ancre === self::ANCRE_DIGEST ? null : $this->whyMessageId;
        $this->correctingRef = $ref;
        $this->correctingVersion = $carte[$cle];
        $this->correctingMode = $mode;
        $this->correctionText = '';
        $this->correctionNewText = '';
        $this->correctionConflict = null;
        $this->correctionFlash = '';
        $this->correctionFeedbackAnchor = null;
        $this->resetErrorBag(['correctionText', 'correctionNewText']);
    }

    /**
     * Changer de geste sans fermer le formulaire. C'est une ACTION et non une
     * liaison de propriété (REMÉDIATION R2, F2) : `$correctingMode` est
     * `#[Locked]`, donc le domaine se contrôle à l'entrée, une fois, au lieu
     * d'être supposé partout en aval.
     */
    public function setCorrectionMode(string $mode): void
    {
        if ($this->correctingRef === null || ! in_array($mode, self::CORRECTION_MODES, true)) {
            return;
        }

        $this->correctingMode = $mode;
        $this->resetErrorBag(['correctionText', 'correctionNewText']);
    }

    public function cancelCorrection(): void
    {
        $this->correctingAnchor = null;
        $this->correctingMessageId = null;
        $this->correctingRef = null;
        $this->correctingVersion = 0;
        $this->correctingMode = self::MODE_RETRACT;
        $this->correctionText = '';
        $this->correctionNewText = '';
        $this->resetErrorBag(['correctionText', 'correctionNewText']);
    }

    /**
     * TASK-1549 : soumettre la correction. Le composant ne décide RIEN —
     * la référence affichée est re-résolue MAINTENANT par le service
     * (`citedMemoryNote`), et `HumanClaimCorrection` refait toutes ses
     * gardes. Il rend `{ok, raison, message_id}` : `message_id === null`
     * signifie qu'AUCUNE trace n'existe (pas même un message) ;
     * `message_id !== null` avec `ok === false` signifie que le message
     * humain est bien dans la Boucle mais que la mémoire n'a pas bougé.
     * Aucune raison technique n'atteint l'écran : elle CHOISIT le message,
     * elle n'est pas le message.
     *
     * Fermer AVANT d'annoncer (motif T1310) : le formulaire disparaît, une
     * seconde soumission n'a plus d'état — et côté serveur, l'idempotence de
     * version de T1548 ferme la fenêtre restante.
     */
    public function submitCorrection(
        HumanClaimCorrection $correction,
        AiResponseExplanationService $service,
        // TASK-1550 : la dépendance est DÉCLARÉE — Livewire l'injecte comme les
        // deux autres — mais elle est optionnelle pour une raison précise :
        // trois tests de T1549 appellent cette méthode directement, en
        // contournant l'injection, afin de forcer un état hors domaine. Un
        // paramètre obligatoire aurait exigé de réécrire des appels dans le
        // filet de régression qui garde exactement le comportement qu'on
        // étend ici. Le filet reste donc à diff NUL.
        ?LoopMemoryDigest $digest = null,
    ): void {
        $digest ??= app(LoopMemoryDigest::class);
        $user = auth()->user();

        if (! $this->canContribute($user)) {
            // Le droit est tombé pendant que le panneau était ouvert. Le
            // service refuserait de toute façon — mais il ne doit même pas
            // être appelé, et surtout la personne doit LIRE le refus.
            $this->correctionFeedbackAnchor = in_array((string) $this->correctingAnchor, self::ANCRES, true)
                ? (string) $this->correctingAnchor
                : self::ANCRE_WHY;
            $this->cancelCorrection();
            $this->whyCanCorrect = false;
            $this->digestCanCorrect = false;
            $this->correctionConflict = __('loops.correct_refused_right');

            return;
        }

        if ($this->correctingRef === null || ! in_array((string) $this->correctingAnchor, self::ANCRES, true)) {
            return;
        }

        // L'ancre `why` exige en plus que la bulle du quadruplet soit encore
        // celle que le panneau affiche : c'est la garde R2 de T1549, intacte.
        if ($this->correctingAnchor === self::ANCRE_WHY
            && ($this->whyMessageId === null || $this->correctingMessageId === null)) {
            return;
        }

        // REMÉDIATION R2 (F2) — le domaine du mode se revérifie ICI, au point
        // d'écriture. `#[Locked]` empêche le client de l'écrire ; il n'empêche
        // pas ce code de lire une valeur qu'il n'a jamais contrôlée. Une
        // valeur hors domaine ne retombe PAS sur le geste destructeur : elle
        // ne fait rien, et se dit.
        if (! in_array($this->correctingMode, self::CORRECTION_MODES, true)) {
            $this->correctionFeedbackAnchor = (string) $this->correctingAnchor;
            $this->cancelCorrection();
            $this->correctionConflict = __('loops.correct_conflict_before');

            return;
        }

        // REMÉDIATION TASK-1549 — `MIN_TEXTE` validé ICI, AVANT tout appel.
        //
        // `ClaimPatch::valider()` refuse un énoncé de moins de 15 caractères,
        // mais ce refus arrive APRÈS que `HumanClaimCorrection` a déjà publié
        // le message humain : une faute de saisie laissait donc un message
        // PUBLIC et DÉFINITIF dans la Boucle, puis s'affichait en « conflit,
        // rechargez la version actuelle » — un incident inventé pour une
        // contrainte de saisie, et chaque nouvel essai ajoutait un doublon.
        //
        // La contrainte se nomme, elle ne se déguise pas.
        $this->validate([
            'correctionText' => 'required|string|max:2000',
            'correctionNewText' => $this->correctingMode === self::MODE_UPDATE
                ? 'required|string|min:'.ClaimPatch::MIN_TEXTE.'|max:2000'
                : 'nullable|string|max:2000',
        ], [
            'correctionText.required' => __('loops.correct_text_required'),
            'correctionNewText.required' => __('loops.correct_new_text_required'),
            'correctionNewText.min' => __('loops.correct_new_text_min', ['min' => ClaimPatch::MIN_TEXTE]),
        ]);

        $ancre = (string) $this->correctingAnchor;
        $messageId = $this->correctingMessageId;
        $ref = $this->correctingRef;
        $mode = $this->correctingMode;
        $version = $this->correctingVersion;
        $texte = trim($this->correctionText);
        $nouveau = trim($this->correctionNewText);

        // REMÉDIATION TASK-1549, DURCIE EN R2 (F1) — le TRIPLET se revalide,
        // pas seulement l'adresse, et AVANT toute résolution.
        //
        // `#[Locked]` protège une valeur ; il ne protège pas l'INVARIANT qui
        // en lie plusieurs. L'ancre, la bulle, l'adresse et la version ont été
        // capturées ENSEMBLE à l'ouverture : elles se revérifient ENSEMBLE ici,
        // contre la carte figée de CETTE ancre (elle-même `#[Locked]`).
        //
        // La version R1 comparait `S1` à `S1` et `1` à `1` : deux égalités
        // vraies qui ne prouvaient rien, puisque la carte ne disait pas de
        // quelle bulle la version venait. Un triplet rompu n'écrit rien,
        // plutôt que d'écrire au mauvais endroit.
        //
        // TASK-1550 : la carte de l'ancre `digest` est indexée par le JETON,
        // qui dérive du sujet et de la Boucle. Une adresse de carte ne peut donc
        // pas désigner deux énoncés — la classe de défaut de R2 n'a pas
        // d'équivalent de ce côté, et la garde reste néanmoins posée.
        $cle = $ancre === self::ANCRE_DIGEST ? $ref : $this->cleMemoire((string) $messageId, $ref);
        $carte = $ancre === self::ANCRE_DIGEST ? $this->digestMemoryVersions : $this->whyMemoryVersions;

        if (($ancre === self::ANCRE_WHY && $messageId !== $this->whyMessageId)
            || ! array_key_exists($cle, $carte)
            || $carte[$cle] !== $version) {
            $this->correctionFeedbackAnchor = $ancre;
            $this->cancelCorrection();
            $this->correctionConflict = __('loops.correct_conflict_before');

            return;
        }

        // La bulle dont on re-résout les citations est celle que le QUADRUPLET
        // désigne — jamais celle que le composant affiche au moment du clic.
        // L'ancre `digest` n'en a pas : son jeton se résout dans la mémoire.
        $message = $ancre === self::ANCRE_DIGEST
            ? null
            : LoopMessage::where('id', $messageId)->where('loop_id', $this->loop->id)->first();

        if ($ancre === self::ANCRE_WHY && $message === null) {
            $this->cancelCorrection();

            return;
        }

        // La résolution serveur, par ancre. Les deux rendent la MÊME chose — le
        // sujet à corriger — et aucune ne fait autorité sur le droit : le
        // lecteur standard est reposé de part et d'autre, puis
        // `HumanClaimCorrection` refait toutes ses gardes.
        if ($ancre === self::ANCRE_DIGEST) {
            $subjectKey = $digest->sujetCorrigeable($this->loop->organization, $this->loop, $user, $ref);
        } else {
            $note = $service->citedMemoryNote($this->loop, $message, $user, $ref);
            $subjectKey = $note === null || (string) $note->source_loop_id !== (string) $this->loop->id
                ? null
                : (string) $note->subject_key;
        }

        // Fermer d'abord : quoi qu'il arrive ensuite, une double soumission
        // triviale n'a plus d'état à rejouer.
        $this->cancelCorrection();
        $this->correctionFeedbackAnchor = $ancre;

        if ($subjectKey === null) {
            // La trace citée n'est plus résoluble (l'énoncé a évolué et son
            // chunk a été remplacé), le jeton ne désigne plus aucun énoncé
            // actif, ou le geste vise une autre Boucle — dans tous les cas
            // RIEN n'a été écrit, et on le dit.
            $this->correctionConflict = __('loops.correct_conflict_before');
            $this->refreshPanels($service, $message, $user);

            return;
        }

        $resultat = $mode === self::MODE_UPDATE
            ? $correction->mettreAJour($this->loop->organization, $this->loop, $user, $subjectKey, $version, $texte, $nouveau)
            : $correction->retracter($this->loop->organization, $this->loop, $user, $subjectKey, $version, $texte);

        if ($resultat['ok']) {
            $this->correctionFlash = __('loops.correct_ack');
        } elseif ($resultat['message_id'] === null) {
            $this->correctionConflict = __('loops.correct_conflict_before');
        } else {
            $this->correctionConflict = __('loops.correct_conflict_after');
        }

        $this->refreshPanels($service, $message, $user);
    }

    /**
     * TASK-1550 : renvoyer la carte « Depuis cet échange… » pour cette session
     * de composant. Rien n'est écrit, rien n'est promis : ce n'est pas un
     * accusé de lecture, et un rechargement la fait revenir. Le formulaire
     * ouvert depuis la carte se ferme avec elle — aucune correction ne survit à
     * la disparition de ce qu'elle visait (règle T1549).
     */
    public function dismissDigest(): void
    {
        $this->digestDismissed = true;
        $this->digestPanel = null;
        $this->digestMemoryVersions = [];

        if ($this->correctingAnchor === self::ANCRE_DIGEST) {
            $this->cancelCorrection();
        }

        if ($this->correctionFeedbackAnchor === self::ANCRE_DIGEST) {
            $this->correctionFlash = '';
            $this->correctionConflict = null;
            $this->correctionFeedbackAnchor = null;
        }
    }

    /**
     * TASK-1550 : poser la carte ET figer la version de chaque énoncé qu'elle
     * montre — même discipline que {@see self::applyWhyPanel()}, et pour la
     * même raison : c'est cette version-là, et aucune autre, qu'une correction
     * pourra viser.
     *
     * Seules les entrées CORRIGEABLES entrent dans la carte des versions : une
     * entrée rétractée n'a pas de version active à viser, et
     * `startCorrection()` la refusera donc d'emblée, même forgée.
     *
     * @param  array<string, mixed>  $panel
     */
    private function applyDigestPanel(array $panel): void
    {
        $this->digestPanel = $panel;

        $versions = [];

        foreach ($panel['entries'] ?? [] as $entry) {
            if (is_array($entry)
                && is_string($entry['ref'] ?? null)
                && is_int($entry['subject_version'] ?? null)
                && ($entry['can_correct'] ?? false) === true) {
                $versions[$entry['ref']] = $entry['subject_version'];
            }
        }

        $this->digestMemoryVersions = $versions;
    }

    /**
     * Recalculer la carte, sauf si elle est GELÉE.
     *
     * Deux raisons de geler, et une seule est un choix produit :
     *
     *  - un formulaire de correction ouvert DEPUIS la carte : le `wire:poll.3s`
     *    ferait disparaître l'entrée, et avec elle le texte en cours de saisie.
     *    Une version devenue périmée est de toute façon refusée AVANT toute
     *    écriture par l'idempotence de T1548 (`version_perimee` rend
     *    `message_id = null`), donc geler ne crée aucun risque de message
     *    public orphelin ;
     *  - la carte renvoyée pour cette session.
     *
     * Hors de ces deux cas, elle se recalcule à chaque render : « l'état
     * courant des claims au rendu » est une exigence, pas une optimisation.
     */
    private function syncDigestPanel(LoopMemoryDigest $digest): void
    {
        if ($this->digestDismissed) {
            $this->digestPanel = null;
            $this->digestMemoryVersions = [];

            return;
        }

        if ($this->correctingAnchor === self::ANCRE_DIGEST && $this->digestPanel !== null) {
            return;
        }

        if (! $this->isMember || $this->loop->organization === null) {
            $this->digestPanel = null;
            $this->digestMemoryVersions = [];

            return;
        }

        $panel = $digest->pour($this->loop->organization, $this->loop, auth()->user());

        if ($panel === null) {
            $this->digestPanel = null;
            $this->digestMemoryVersions = [];

            return;
        }

        $this->applyDigestPanel($panel);
    }

    /**
     * Remettre à l'état de la base ce qui était affiché, APRÈS avoir écrit.
     *
     * Le panneau « Pourquoi ? » est un état stocké : il se recalcule ici, comme
     * en T1549. La carte du digest, elle, se recalcule d'elle-même au render
     * suivant — le formulaire vient d'être fermé, donc plus rien ne la gèle.
     * Il suffit de la libérer.
     */
    private function refreshPanels(AiResponseExplanationService $service, ?LoopMessage $message, User $user): void
    {
        $this->digestPanel = null;
        $this->digestMemoryVersions = [];

        if ($message !== null) {
            $this->refreshWhyPanel($service, $message, $user);
        }
    }

    /**
     * Recalculer le panneau APRÈS avoir écrit depuis lui (motif T1328,
     * `submitWhyFeedback`) : l'état affiché — énoncé courant, historique de
     * correction, versions lisibles — redevient celui de la base.
     */
    private function refreshWhyPanel(AiResponseExplanationService $service, LoopMessage $message, User $user): void
    {
        $panel = $service->explain($this->loop, $message, $user);

        if ($panel !== null) {
            $this->applyWhyPanel((string) $message->id, $panel);
        }
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

        // REMÉDIATION TASK-1549 : `whyCanCorrect` était calculé UNE fois, à
        // l'ouverture du panneau. Un droit révoqué pendant que le panneau reste
        // ouvert laissait donc les boutons « Corriger » vivants jusqu'au
        // prochain `wire:poll`. Recalculé ici, l'affichage redevient honnête.
        //
        // C'est de l'HONNÊTETÉ D'AFFICHAGE, pas une garde : l'autorité reste
        // côté serveur — `startCorrection()` et `submitCorrection()` refusent
        // et le disent, et `HumanClaimCorrection` refait ses propres gardes.
        // Aucune de ces trois vérifications ne remplace les autres.
        if ($this->whyMessageId !== null) {
            $this->whyCanCorrect = $this->canContribute(auth()->user());
        }

        // TASK-1550 : la carte « Depuis cet échange… » se recalcule ici, à
        // l'état courant de la mémoire. Elle coûte deux requêtes indexées quand
        // il n'y a rien — le cas de loin le plus fréquent sous `wire:poll.3s` —
        // parce que `LoopMemoryDigest` court-circuite sur l'ancre puis sur la
        // date de la dernière compilation avant de lire le moindre lignage.
        $this->syncDigestPanel(app(LoopMemoryDigest::class));

        // Le geste n'est offert qu'à qui peut écrire ici, et c'est recalculé à
        // chaque render pour la même raison que `whyCanCorrect` : un droit
        // révoqué ne doit pas laisser des boutons vivants jusqu'au prochain
        // poll. Ce n'est pas une garde — `startCorrection()` et
        // `submitCorrection()` refusent et le disent.
        $this->digestCanCorrect = $this->digestPanel !== null && $this->canContribute(auth()->user());

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
        // TASK-1316 : les tours IA en cours, vus par les AUTRES membres. Derive
        // des messages deja charges ci-dessus — voir `LoopAiTurnSignal` pour
        // l'audit qui a ecarte `AiTurnLock` comme source d'etat d'interface.
        $pendingAiTurns = $this->pendingAiTurns($messages);
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
            'pendingAiTurns',
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

    /**
     * TASK-1316 : les tours IA en cours dans cette Boucle, prets a afficher.
     *
     * La REGLE vit dans `LoopAiTurnSignal` — messages persistes pour l'identite
     * et le mode, verrou T1311 pour la vivacite. Ce qui se decide ici est
     * uniquement l'habillage : l'identite « Organization · Mode », la meme que
     * portera la bulle de reponse.
     *
     * @param  Collection<int, LoopMessage>  $messages
     * @return array<int, array{message_id: string, requester_id: string, requester_name: string, ai_mode: string, identity: string}>
     */
    private function pendingAiTurns(Collection $messages): array
    {
        return array_map(
            fn (array $turn): array => $turn + ['identity' => $this->aiIdentity($turn['ai_mode'])],
            LoopAiTurnSignal::pendingTurns($this->loop, $messages),
        );
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
