<?php

namespace App\Livewire;

use App\Jobs\SendNewMessageReceivedEmail;
use App\Models\FeedPost;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\UrlPreviewService;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Files\TypesDeFichiersAcceptes;
use App\Support\Messaging\ConversationBroadcaster;
use App\Support\Messaging\UnreadCountBroadcaster;
use App\Support\ScopedReadCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class MessageThread extends Component
{
    use WithFileUploads;

    /**
     * TASK-1691 (P0) — ces trois proprietes decidaient du TENANT, et le client
     * les ecrivait.
     *
     * Mesure faite en T1690 : un membre de A, depuis un snapshot legitime de SA
     * conversation, envoyait `{"updates":{"organizationId":"<B>",
     * "transactionId":"<transaction de B>"}}`. `ensureOrganizationContext()`
     * re-liait alors `current_organization` sur B ; le scope tenant de
     * `Transaction` — qui TIENT par ailleurs — ne protegeait plus rien, et
     * `render()` servait le corps d'une conversation privee de B ainsi que le
     * nom de son interlocuteur. Pire, `render()` appelle `markRead()`, qui
     * ECRIT : `messages.read_at` d'un tenant etranger passait a `now()`.
     *
     * Le discriminant l'a prouve : en ne falsifiant QUE `transactionId`, sans
     * deplacer le tenant, la reponse etait **404**. La garde n'etait pas
     * absente — elle etait desarmee de l'interieur.
     */
    #[Locked]
    public string $transactionId;

    #[Locked]
    public string $organizationId;

    #[Locked]
    public string $organizationSlug;

    protected ?Transaction $transaction = null;

    public string $newMessage = '';

    public ?string $replyToMessageId = null;

    public ?array $replyingTo = null;

    /** TASK-1704 correctif 2 — le message en cours de modification, s'il y en a un. */
    public ?string $editingMessageId = null;

    public string $editingBody = '';

    /**
     * TASK-1704 — deux plafonds, et c'est VOULU.
     *
     * Une image est re-encodee en WebP et reduite a 1200x800 : son poids final
     * ne depend pas de celui du depot, et le plafond ne protege que la memoire
     * de l'etape de decodage. 10 Mo y suffisaient, et le changer modifierait un
     * comportement que personne n'a demande de modifier.
     *
     * Un fichier, lui, est conserve tel quel. Son plafond est celui des
     * Dossiers — 50 Mo — parce que c'est deja la reponse que le produit donne
     * a la meme question, et qu'en inventer une seconde creerait deux regles
     * contradictoires a expliquer aux membres.
     */
    public const TAILLE_MAX_IMAGE_KO = 10240;

    public const TAILLE_MAX_FICHIER_KO = 51200;

    /**
     * TASK-1772 (arbitrage Cyril, 06/11) — LE PLAFOND D'UNE VIDEO.
     *
     * 25 Mo, soit environ trente secondes de video de telephone. Plus bas que
     * les 50 Mo d'un fichier ordinaire, et deliberement : la PROD tourne sur
     * UNE instance de 1 vCPU / 512 Mo (mesure du 05/11). Un envoi de 50 Mo
     * occupe cette instance unique pendant toute sa montee — pour tout le
     * monde, pas seulement pour celui qui envoie.
     *
     * Le plafond est une decision de SURFACE : `TypesDeFichiersAcceptes` dit
     * de quoi le produit sait faire quelque chose, jamais combien ca pese.
     */
    public const TAILLE_MAX_VIDEO_KO = 25600;

    public $photo = null;

    /**
     * TASK-1886 — LE VOCAL, sur une propriete A PART de `$photo`.
     *
     * C'est le chemin d'arrivee, et lui seul, qui fait d'un fichier un vocal
     * ephemere : un `.mp3` joint par `$photo` reste une piece jointe durable,
     * meme si son type est identique. Le serveur ne devine donc rien sur le
     * contenu pour decider de supprimer — une erreur de devinette detruirait
     * un fichier que son auteur voulait garder.
     */
    public $voice = null;

    /**
     * TASK-1886 — la duree annoncee par l'enregistreur, en secondes.
     *
     * Valeur CLIENT, donc d'affichage seulement : elle est bornee a l'envoi et
     * ne decide d'aucun droit. Le plafond qui protege le serveur est le POIDS
     * (`TAILLE_MAX_VOCAL_KO`), verifie sur le fichier reel.
     */
    public ?int $voiceDuration = null;

    /**
     * TASK-1886 — la forme de la voix, mesuree a l'enregistrement : jusqu'a
     * 40 valeurs de 0 a 100. Valeur CLIENT, purement graphique : elle est
     * bornee et convertie en entiers a l'envoi, jamais interpretee.
     *
     * @var array<int, mixed>
     */
    public array $voiceWaveform = [];

    /**
     * TASK-1886 — 5 Mo : cinq minutes d'Opus a 32-64 kbit/s pesent 1,2 a
     * 2,4 Mo, et l'AAC de Safari un peu plus. Le double de marge, pas plus :
     * l'instance PROD unique (1 vCPU / 512 Mo) sert tout le monde pendant
     * chaque envoi.
     */
    public const TAILLE_MAX_VOCAL_KO = 5120;

    /**
     * TASK-1886 — le prefixe des vocaux, EN TETE de cle.
     *
     * Une regle de cycle de vie R2/S3 ne filtre que par DEBUT de cle : sous
     * `{org}/{transaction}/voice/`, aucune regle ne pourrait viser les vocaux
     * sans viser aussi les pieces jointes durables. Ici, le filet R2 (« supprimer
     * sous `message-voice/` apres 7 jours ») ne peut toucher QUE des vocaux.
     */
    public const PREFIXE_VOCAL = 'message-voice';

    /**
     * TASK-1886 — les types qu'un enregistreur de navigateur produit, tels que
     * finfo les DEVINE sur le contenu, et le type audio honnete a servir.
     *
     * Chrome/Android enregistrent du WebM/Opus, Safari du MP4/AAC, Firefox de
     * l'Ogg/Opus. Un conteneur sans piste video est pourtant souvent devine
     * `video/webm` ou `video/mp4` par libmagic, qui ne lit que l'en-tete du
     * conteneur : le refuser ferait echouer tout vocal Chrome. Il est accepte
     * ICI seulement — sur ce chemin vocal, borne a 5 Mo — et servi sous son
     * nom audio, pour que le navigateur le joue dans une balise `<audio>`.
     *
     * @var array<string, string>
     */
    public const TYPES_VOCAUX = [
        'audio/webm' => 'audio/webm',
        'video/webm' => 'audio/webm',
        'audio/ogg' => 'audio/ogg',
        'video/ogg' => 'audio/ogg',
        'application/ogg' => 'audio/ogg',
        'audio/mp4' => 'audio/mp4',
        'audio/x-m4a' => 'audio/mp4',
        'video/mp4' => 'audio/mp4',
        'audio/aac' => 'audio/aac',
        'audio/mpeg' => 'audio/mpeg',
        'audio/wav' => 'audio/wav',
        'audio/x-wav' => 'audio/wav',
    ];

    /**
     * TASK-1808 §4 — la pluie de coeurs est-elle due MAINTENANT, a CE lecteur ?
     *
     * Decidee une seule fois, au montage, et jamais recalculee au rendu : le
     * marqueur durable est pose dans le meme geste, donc une relecture
     * repondrait toujours « non » et l'animation ne partirait jamais.
     */
    public bool $pluieDeCoeurs = false;

    /**
     * TASK-1808 §7 — l'entraide est-elle deja dans le Flux ?
     *
     * Reproposer le geste apres coup laisserait croire qu'il n'a pas pris.
     */
    public bool $fluxDejaPartage = false;

    /**
     * TASK-1836 — meme principe que `LoopChat::$pollFingerprint`/
     * `$lastFullRenderAt` (TASK-1835) : deux scalaires, jamais une collection
     * de messages. `#[Locked]` : aucune des deux n'est liee a un `wire:model`
     * ni censee etre modifiable depuis le JS de la page.
     */
    #[Locked]
    public ?string $pollFingerprint = null;

    #[Locked]
    public int $lastFullRenderAt = 0;

    /**
     * TASK-1836 — filet de securite generique pour les conversations
     * DIRECTES uniquement (voir {@see pollThread()}) : un echange d'entraide
     * avec elements metier externes (evaluation, Flux, progression) n'entre
     * jamais dans cette soupape, il rend integralement a CHAQUE cycle.
     *
     * 15s (mandat MASTER du 15/11) : role unique, maintenir le badge global
     * (`unreadCount`, qui reflete TOUTES les conversations de l'utilisateur,
     * pas seulement celle-ci) a jour meme si rien ne change ICI.
     */
    private const INTERVALLE_SOUPAPE_SECONDES = 15;

    public function mount(Transaction $transaction): void
    {
        $this->transactionId = $transaction->id;
        $this->organizationId = $transaction->organization_id;
        $this->organizationSlug = currentOrganization()?->slug
            ?? Organization::findOrFail($transaction->organization_id)->slug;
        $this->markRead();
        $this->celebrerSiCEstDu($transaction);
        $this->fluxDejaPartage = $this->dejaDansLeFlux($transaction);

        // TASK-1836 — amorce l'empreinte au rendu initial (deja complet),
        // seulement pour les conversations DIRECTES (voir `pollThread()`).
        if ($transaction->isDirectConversation()) {
            $this->pollFingerprint = $this->computePollFingerprint($transaction);
            $this->lastFullRenderAt = now()->getTimestamp();
        }
    }

    /**
     * TASK-1808 §4 — « a sa PREMIERE ouverture de la conversation terminee ».
     *
     * Le marqueur s'ecrit ICI, cote serveur, et non dans le navigateur : le
     * drapeau de l'animation vit en memoire de page et ne survit ni a un
     * rechargement ni a un autre appareil. « Une seule fois par entraide ET
     * par aidant » ne peut se tenir qu'en base.
     *
     * `update()` conditionne par `whereNull` : deux onglets ouverts en meme
     * temps ne peuvent pas celebrer deux fois, c'est la base qui arbitre et
     * non l'ordre des requetes. Le nombre de lignes touchees dit lequel des
     * deux a gagne.
     *
     * TASK-1808 (recette navigateur, 11/11) — ET DEUX INSTANCES DANS LA MEME
     * REQUETE NON PLUS. Mesure en conditions reelles : `messages/index.blade.php`
     * monte CE MEME composant DEUX FOIS sur une seule vue — une copie
     * `md:hidden` pour mobile, une copie `hidden md:flex` pour desktop — et
     * chacune appelle `mount()`, donc `celebrerSiCEstDu()`, independamment.
     * Sans garde, le `whereNull` ci-dessous ne laisse gagner QU'UNE des deux,
     * au hasard de l'ordre d'execution — et c'etait systematiquement la copie
     * mobile, TOUJOURS la premiere dans le Blade, meme a un viewport desktop :
     * la pluie etait donc pose a chaque fois dans la copie INVISIBLE, et
     * jamais vue. `ScopedReadCache` (T1778) memorise ici la decision pour la
     * duree de LA REQUETE : la deuxieme instance lit le verdict de la
     * premiere au lieu de retenter sa propre ecriture, et les deux copies
     * s'accordent — celle que la CSS montre a l'ecran comme celle qu'elle
     * cache recoivent la MEME reponse.
     *
     * Aucune evaluation n'intervient dans cette decision (§4 : « aucune
     * dependance a une future evaluation »), et l'aide n'est jamais celebre —
     * la pluie remercie celui qui a AIDE.
     */
    private function celebrerSiCEstDu(Transaction $transaction): void
    {
        if (! $transaction->celebrationDueALAidant()) {
            return;
        }

        if (! $transaction->estLAidant(auth()->user())) {
            return;
        }

        $this->pluieDeCoeurs = app(ScopedReadCache::class)->souviens(
            "entraide_celebration:{$transaction->id}",
            function () use ($transaction) {
                $pose = Transaction::whereKey($transaction->id)
                    ->whereNull('seller_celebrated_at')
                    ->update(['seller_celebrated_at' => now()]);

                return $pose === 1;
            },
        );
    }

    /** Une ligne de Flux existe-t-elle deja pour cette entraide terminee ? */
    private function dejaDansLeFlux(Transaction $transaction): bool
    {
        if ($transaction->status !== 'completed' || $transaction->isDirectConversation()) {
            return false;
        }

        return FeedPost::withTrashed()
            ->where('organization_id', $transaction->organization_id)
            ->where('type', FeedEventCatalogue::ENTRAIDE_TERMINEE)
            ->where('object_type', Transaction::class)
            ->where('object_id', $transaction->id)
            ->exists();
    }

    /**
     * TASK-1869 — « Repondre a » et « Epingler » n'appartiennent pas a une
     * transaction d'entraide.
     *
     * Ces deux gestes sont ceux d'une CONVERSATION : le ChatLoop et la
     * messagerie privee. Le fil d'une entraide, lui, sert a mener un echange
     * de services jusqu'a sa cloture — ses actions sont metier.
     *
     * La vue ne montre plus les boutons ; cette methode est ce qui le REND
     * VRAI. Une requete Livewire forgee vise directement `replyTo` ou
     * `pinMessage` sans passer par aucun bouton : l'affichage n'a jamais ete
     * une permission.
     *
     * Ce que cette garde ne touche pas, volontairement :
     * - `unpinMessage()`, laisse ouvert pour detacher un epinglage HISTORIQUE
     *   (arbitrage MASTER 2) — il ne cree rien et n'expose rien ;
     * - les reponses et epinglages deja enregistres, qui restent affiches tels
     *   qu'ils ont ete ecrits ;
     * - `toggleReaction()`, la copie, la modification et la suppression.
     */
    private function gestesDeConversationAutorises(Transaction $transaction): bool
    {
        return $transaction->isDirectConversation();
    }

    public function replyTo(string $messageId): void
    {
        $transaction = $this->transaction();

        // TASK-1869 — refus silencieux, le meme motif que les gardes
        // d'appartenance de ce composant : rien n'est ecrit, rien n'est
        // revele, et le fil se re-rend inchange.
        if (! $this->gestesDeConversationAutorises($transaction)) {
            return;
        }

        $message = Message::where('id', $messageId)
            ->where('transaction_id', $transaction->id)
            ->with('sender')
            ->first();

        if (! $message) {
            return;
        }

        $this->replyToMessageId = $message->id;
        $this->replyingTo = [
            // TASK-1886 — un vocal n'a pas de texte : sans ce libelle, la
            // citation s'affichait VIDE (vu en recette).
            'body' => $message->voiceSummary() ?? mb_substr($message->body, 0, 120),
            'sender_name' => $message->sender?->name ?? 'BouclePro',
        ];
    }

    public function cancelReply(): void
    {
        $this->replyToMessageId = null;
        $this->replyingTo = null;
    }

    public function updatedPhoto(): void
    {
        if (! $this->photo) {
            return;
        }
        $validator = validator(['photo' => $this->photo], ['photo' => $this->reglesDePieceJointe($this->photo)]);
        if ($validator->fails()) {
            $this->photo = null;
            $this->addError('photo', __('messages.invalid_attachment'));
        }
    }

    /**
     * TASK-1886 — meme garde que `updatedPhoto()` : un fichier refuse est
     * retire tout de suite, plutot que de rester en attente d'un envoi qui
     * echouerait.
     */
    public function updatedVoice(): void
    {
        if (! $this->voice) {
            return;
        }

        $validator = validator(['voice' => $this->voice], ['voice' => $this->reglesDeVocal()]);

        if ($validator->fails()) {
            $this->voice = null;
            $this->addError('voice', __('messages.voice_invalid'));

            return;
        }

        // TASK-1887 — une nouvelle tentative valide efface l'ancien refus.
        $this->resetErrorBag('voice');
    }

    /**
     * TASK-1887 — la regle d'un vocal : poids d'abord, puis type LU dans le
     * contenu par `typeVocal()`. `mimetypes:` est abandonne ici : sur Laravel
     * Cloud, le depot temporaire Livewire est sur S3, et `getMimeType()` y rend
     * le Content-Type ANNONCE par le navigateur (`audio/webm;codecs=opus`),
     * jamais le type du contenu — tous les vocaux Chrome etaient refuses.
     *
     * @return list<mixed>
     */
    private function reglesDeVocal(): array
    {
        return [
            'bail',
            'file',
            'max:'.self::TAILLE_MAX_VOCAL_KO,
            function (string $attribut, mixed $fichier, \Closure $echec): void {
                if ($this->typeVocal($fichier) === null) {
                    $echec(__('messages.voice_invalid'));
                }
            },
        ];
    }

    /** TASK-1887 — octets lus pour reconnaitre un conteneur audio. */
    private const OCTETS_DE_TETE = 4096;

    /**
     * TASK-1887 — le type audio honnete d'un vocal, devine par finfo sur ses
     * PREMIERS octets, ou null s'il doit etre refuse.
     *
     * Ni le type annonce par le navigateur, ni les metadonnees S3 ne sont lus.
     * Le poids est verifie AVANT toute lecture, et seul un extrait borne est
     * lu. Un fichier illisible ou non reconnu est refuse, jamais devine.
     */
    private function typeVocal(mixed $fichier): ?string
    {
        if (! $fichier instanceof UploadedFile) {
            return null;
        }

        try {
            $taille = (int) $fichier->getSize();

            if ($taille <= 0 || $taille > self::TAILLE_MAX_VOCAL_KO * 1024) {
                return null;
            }

            $flux = $fichier instanceof TemporaryUploadedFile
                ? $fichier->readStream()
                : fopen($fichier->getRealPath(), 'rb');

            if (! is_resource($flux)) {
                return null;
            }

            try {
                $tete = stream_get_contents($flux, self::OCTETS_DE_TETE);
            } finally {
                fclose($flux);
            }
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($tete) || $tete === '') {
            return null;
        }

        $devine = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($tete);

        return self::TYPES_VOCAUX[$devine] ?? null;
    }

    /**
     * TASK-1704 — ce qu'on accepte, et jusqu'a quel poids.
     *
     * La liste des types vient de `TypesDeFichiersAcceptes`, partagee avec les
     * Dossiers : le produit ne doit pas avoir deux reponses a « de quoi sais-je
     * faire quelque chose ? ».
     *
     * Le plafond, lui, depend du type devine SUR LE CONTENU — jamais de
     * l'extension ni de ce que le navigateur annonce, qui sont tous deux sous
     * le controle de celui qui depose.
     *
     * @return list<string>
     */
    private function reglesDePieceJointe($file): array
    {
        return [
            'file',
            TypesDeFichiersAcceptes::regleMimetypes(),
            'max:'.$this->plafondEnKo($file),
        ];
    }

    /**
     * TASK-1772 — le plafond qui s'applique a CE depot.
     *
     * Trois reponses et non deux : une image passe par le pipeline WebP et
     * n'a aucune raison de peser 50 Mo ; une video est bornee plus bas que le
     * reste (voir `TAILLE_MAX_VIDEO_KO`) ; tout le reste suit les Dossiers.
     */
    private function plafondEnKo($file): int
    {
        if ($this->estUneImage($file)) {
            return self::TAILLE_MAX_IMAGE_KO;
        }

        return $this->estUneVideo($file) ? self::TAILLE_MAX_VIDEO_KO : self::TAILLE_MAX_FICHIER_KO;
    }

    /**
     * TASK-1772 — le depot est-il une video ?
     *
     * Meme discipline que `estUneImage()` : le type est devine sur le
     * CONTENU, jamais sur l'extension. Un `.mp4` renomme en `.pdf` reste une
     * video et garde le plafond d'une video.
     */
    private function estUneVideo($file): bool
    {
        try {
            $mime = $file?->getMimeType();
        } catch (\Throwable) {
            return false;
        }

        return is_string($mime) && str_starts_with($mime, 'video/');
    }

    /**
     * Le depot est-il une image, au sens du pipeline WebP ?
     *
     * `getMimeType()` devine a partir du CONTENU. Un `.pdf` renomme en `.jpg`
     * repond donc « application/pdf » et part sur le chemin fichier, au lieu
     * d'etre confie a `Image::decode()` qui echouerait.
     *
     * Une absence de type est traitee comme « pas une image » : le chemin
     * fichier conserve l'octet tel quel, la ou le chemin image le detruirait.
     */
    private function estUneImage($file): bool
    {
        try {
            $mime = $file?->getMimeType();
        } catch (\Throwable) {
            return false;
        }

        return $mime !== null && in_array($mime, TypesDeFichiersAcceptes::IMAGES, true);
    }

    /**
     * TASK-1704 correctif 2 — modifier un message texte.
     *
     * Cyril : « il faudrait aussi pouvoir modifier les messages textes, on
     * peut le faire sur la chatloop. »
     *
     * Le mecanisme est celui du ChatLoop, repris sans etre reinvente : memes
     * noms de methodes (la bulle partagee appelle deja `editMessage(...)`),
     * meme regle d'editabilite, meme recalcul de l'apercu de lien. Ecrire un
     * second mecanisme aurait donne deux comportements a maintenir pour un
     * seul geste.
     *
     * ## Les gardes, et pourquoi chacune
     *
     * - **Participation et statut de l'echange** : on ne modifie pas un
     *   message dans une conversation ou l'on ne peut plus ecrire. C'est la
     *   meme borne que `sendMessage()`, et l'omettre laisserait reecrire
     *   l'historique d'un echange cloture.
     * - **Le message appartient a CET echange** — et a cette Organization.
     *   Sans elle, un identifiant suffirait a modifier le message d'autrui.
     * - **`isEditableBy()`** : l'auteur seul, et jamais un message systeme.
     */
    public function editMessage(string $messageId): void
    {
        $message = $this->messageModifiable($messageId);

        if (! $message) {
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

        if ($this->editingMessageId === null) {
            return;
        }

        $message = $this->messageModifiable($this->editingMessageId);

        if (! $message) {
            $this->cancelEdit();

            return;
        }

        // `required` trime deja les chaines : une saisie d'espaces seuls est
        // rejetee par la validation ci-dessus, et ne parvient jamais ici. Une
        // seconde verification de vacuite serait du code mort — un sabotage
        // qui la supprimait est reste VERT, et c'est ce qui l'a montre.
        $corps = trim($this->editingBody);

        // L'apercu de lien est RECALCULE : un membre qui corrige l'URL de son
        // message doit en voir la vignette changer. Le ChatLoop fait de meme
        // dans `saveEdit()`.
        $url = UrlPreviewService::extractFirstUrl($corps);
        $preview = $url ? app(UrlPreviewService::class)->fetchPreview($url) : null;

        $message->forceFill([
            'body' => $corps,
            'metadata' => $preview !== null ? ['url_preview' => $preview] : null,
            'edited_at' => now(),
            'edited_by' => auth()->id(),
        ])->save();

        // TASK-1846 — une edition change `pollFingerprint` (qui hache
        // `edited_at`) au meme titre qu'un nouveau message : sans ce signal,
        // l'autre partie ne la verrait qu'a la prochaine soupape de securite
        // (20s en conversation DIRECTE des que la diffusion est active),
        // une degradation de fraicheur que le mandat interdit explicitement.
        ConversationBroadcaster::diffuserApresCommit($message);

        $this->cancelEdit();
    }

    /**
     * Le message designe, s'il est bien modifiable par le demandeur ICI.
     *
     * Les quatre conditions sont reunies en un seul endroit pour qu'`editMessage()`
     * et `saveEdit()` ne puissent pas diverger : ouvrir le formulaire et
     * enregistrer doivent repondre a la MEME question. Une garde posee a
     * l'ouverture seule laisserait enregistrer ce qu'on n'a plus le droit de
     * modifier.
     */
    private function messageModifiable(string $messageId): ?Message
    {
        $user = auth()->user();
        $transaction = $this->transaction();

        if (! $user || ! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return null;
        }

        if (in_array($transaction->status, ['completed', 'refused', 'cancelled'])) {
            return null;
        }

        // `messages.id` est un UUID. Comparer une chaine quelconque a une
        // colonne uuid leve `22P02` en PostgreSQL — et reste INVISIBLE en
        // SQLite, ou la colonne est du texte. Le piege a deja coute une
        // correction en TASK-1665.
        if (! Str::isUuid($messageId)) {
            return null;
        }

        $message = Message::where('id', $messageId)
            ->where('transaction_id', $transaction->id)
            ->where('organization_id', $transaction->organization_id)
            ->first();

        if (! $message || ! $message->isEditableBy($user)) {
            return null;
        }

        return $message;
    }

    public function sendMessage(): void
    {
        $this->validate([
            // TASK-1886 — un vocal seul est un envoi valide, comme une image
            // seule : `required_without_all` remplace `required_without`.
            'newMessage' => 'required_without_all:photo,voice|string|max:5000',
            'photo' => $this->photo ? $this->reglesDePieceJointe($this->photo) : ['nullable'],
            'voice' => $this->voice ? $this->reglesDeVocal() : ['nullable'],
        ], [
            'newMessage.required_without_all' => __('messages.body_or_image_required'),
        ]);

        $user = auth()->user();

        // TASK-1844 — `fresh: true`, le MEME motif que `pollThread()`
        // (T1836) : l'autorisation (appartenance) et le statut de la
        // conversation sont revalides au moment EXACT ou l'on ecrit, jamais
        // sur une copie qui aurait pu etre mise en cache plus tot dans la
        // requete. `$transaction` etant une propriete PROTEGEE (non suivie
        // par Livewire), elle ne survit deja pas entre deux requetes — mais
        // rendre la revalidation EXPLICITE evite de faire reposer cette
        // garantie sur un detail de visibilite qui pourrait changer sans que
        // quiconque fasse le lien avec cette garde.
        //
        // CE controle reste un premier filtre BON MARCHE — il evite de
        // lancer un re-encodage d'image ou une requete reseau (l'apercu de
        // lien) pour une conversation deja manifestement fermee. Il n'est
        // PAS la revalidation qui protege l'ecriture elle-meme : voir le
        // second controle, verrouille, juste avant `Message::create()`.
        $transaction = $this->transaction(fresh: true);

        if (! $this->autorisationEtStatutValides($transaction, $user)) {
            return;
        }

        // TASK-1869 — `replyToMessageId` est une propriete PUBLIQUE Livewire :
        // elle voyage avec l'etat du composant et peut etre posee par le
        // client sans jamais appeler `replyTo()`. Fermer la methode ne suffit
        // donc pas — c'est ICI, au moment ou la citation deviendrait une
        // ligne en base, que l'entraide la laisse tomber.
        //
        // Rien n'est refuse a l'utilisateur : le message part normalement,
        // simplement sans `reply_to_id`.
        $replyToId = $this->gestesDeConversationAutorises($transaction)
            ? $this->replyToMessageId
            : null;

        if ($replyToId !== null) {
            $parent = Message::where('id', $replyToId)
                ->where('transaction_id', $transaction->id)
                ->exists();

            if (! $parent) {
                $replyToId = null;
            }
        }

        $imagePath = null;

        $piece = [];

        // TASK-1844 — traitement de fichier et appel reseau INCHANGES, et
        // toujours AVANT la transaction SQL ci-dessous (mandat MASTER :
        // aucun des deux ne doit jamais s'y trouver). Un fichier depose ICI
        // alors que la transaction echoue plus loin devient orphelin sur le
        // disque — risque connu, non corrige dans cette TASK (documente au
        // TASK file : le corriger exigerait soit une ecriture differee du
        // fichier, soit un nettoyeur dedie, les deux hors perimetre minimal).
        if ($this->photo) {
            // Une image suit son chemin d'origine, INCHANGE : re-encodage WebP
            // et disque public. Tout autre type part sur le disque prive.
            if ($this->estUneImage($this->photo)) {
                $imagePath = $this->storeImage($this->photo);
            } else {
                $piece = $this->storeFile($this->photo);
            }
            $this->photo = null;
        }

        // TASK-1886 — le vocal. Son fichier part dans les MEMES colonnes
        // `file_*` qu'une piece jointe (meme route gardee, meme disque), et
        // seule l'echeance en fait un vocal. Un message porte l'un OU
        // l'autre : un vocal arrive toujours seul du composeur, qui masque
        // le champ et les pieces jointes pendant l'enregistrement.
        $vocal = [];

        if ($this->voice && $piece === [] && $imagePath === null) {
            $piece = $this->storeVoice($this->voice);
            $vocal = [
                'voice_expires_at' => now()->addDays(Message::VOICE_TTL_DAYS),
                'voice_duration' => max(1, min(Message::VOICE_MAX_SECONDS, (int) $this->voiceDuration)),
                'waveform' => $this->formeDeVoixBornee(),
            ];
        }

        $this->voice = null;
        $this->voiceDuration = null;
        $this->voiceWaveform = [];

        $url = UrlPreviewService::extractFirstUrl($this->newMessage);
        $preview = $url ? app(UrlPreviewService::class)->fetchPreview($url) : null;

        $metadonnees = array_filter([
            'url_preview' => $preview,
            'voice_waveform' => $vocal['waveform'] ?? null,
        ], fn ($valeur) => $valeur !== null && $valeur !== []);

        $newMessageBody = $this->newMessage;

        /*
         * TASK-1844 (revue MASTER, controle de synchronisation #2) —
         * SEULES les ecritures SQL liees a l'envoi vivent ici : creation du
         * message, `touch()` de la conversation, marquage lu. Aucun
         * fichier, aucune requete reseau, aucune notification — motif deja
         * etabli par `LoopMessageService::sendUserMessage()` (T1839) et
         * `NotificationEmitter::emit()` (T1843).
         *
         * ## Revalidation FINALE, verrouillee, juste avant l'ecriture
         *
         * Le controle fait plus haut date d'AVANT le traitement du fichier
         * et l'appel reseau de l'apercu de lien — deux operations dont le
         * mandat interdit explicitement qu'elles vivent dans cette
         * transaction, et qui peuvent donc laisser passer plusieurs
         * centaines de millisecondes pendant lesquelles la conversation a
         * pu etre cloturee par un autre acteur. `lockForUpdate()` ferme
         * cette fenetre residuelle : la ligne est relue sous verrou, PUIS
         * revalidee — et le verrou n'est jamais tenu pendant un fichier ou
         * un reseau, puisqu'il n'est pose qu'ICI, a l'interieur du bloc.
         *
         * Portee reelle du verrou, mesuree et pas supposee : un verrou de
         * ligne PostgreSQL bloque toute AUTRE ecriture sur cette meme ligne
         * (`UPDATE transactions SET status = ...`, quelle que soit la
         * methode qui cloturerait la conversation), pas seulement les
         * lecteurs qui demanderaient eux-memes `FOR UPDATE`. **Sous
         * SQLite, `lockForUpdate()` se compile en AUCUNE clause**
         * (`SQLiteGrammar::compileLock()` rend une chaine vide) : la
         * protection contre une clôture VRAIMENT concurrente n'existe
         * donc que sous PostgreSQL — SQLite reste couvert par la
         * revalidation applicative seule (suffisante en pratique : SQLite
         * ne sert jamais de vrais acces concurrents dans ce depot,
         * uniquement les tests). Document plutot que suppose : aucun test
         * ne doit pretendre prouver un verrou que SQLite n'applique pas.
         *
         * Si la revalidation refuse ICI, la transaction n'a RIEN ecrit : le
         * `SELECT ... FOR UPDATE` seul se relache au commit, sans effet.
         *
         * ## La notification part apres le commit REEL
         *
         * `DB::afterCommit()`, jamais un appel direct : si cette transaction
         * est un jour appelee depuis celle d'un appelant externe (aucun
         * aujourd'hui, le mandat l'exige en prevision), elle ne doit partir
         * qu'au commit REEL, jamais a la fin de ce bloc imbrique. Rien ne
         * differe le NOMBRE d'envois — un seul, comme avant — seulement SON
         * INSTANT.
         */
        $msg = DB::transaction(function () use ($transaction, $user, $replyToId, $imagePath, $piece, $metadonnees, $vocal, $newMessageBody) {
            $transactionVerrouillee = Transaction::where('organization_id', $this->organizationId)
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->autorisationEtStatutValides($transactionVerrouillee, $user)) {
                return null;
            }

            $msg = Message::create([
                'transaction_id' => $transactionVerrouillee->id,
                'sender_id' => $user->id,
                'reply_to_id' => $replyToId,
                'body' => $newMessageBody,
                'image_path' => $imagePath,
                'file_path' => $piece['path'] ?? null,
                'file_disk' => $piece['disk'] ?? null,
                'file_name' => $piece['name'] ?? null,
                'file_mime' => $piece['mime'] ?? null,
                'file_size' => $piece['size'] ?? null,
                'voice_expires_at' => $vocal['voice_expires_at'] ?? null,
                'voice_duration' => $vocal['voice_duration'] ?? null,
                'metadata' => $metadonnees !== [] ? $metadonnees : null,
                'type' => 'user',
                'organization_id' => $transactionVerrouillee->organization_id,
            ]);

            $transactionVerrouillee->touch();
            $this->markRead();

            $this->notifierApresCommit($transactionVerrouillee, $user, $msg);

            // TASK-1845 — signal Reverb, OFF par defaut, differe apres le
            // commit reel au meme titre que la notification email ci-dessus.
            ConversationBroadcaster::diffuserApresCommit($msg);

            // TASK-1847 — compteur GLOBAL de messages non lus du
            // DESTINATAIRE (jamais l'expediteur, voir `UnreadCountBroadcaster`).
            UnreadCountBroadcaster::diffuserApresCreationMessage($msg, $transactionVerrouillee);

            return $msg;
        });

        // TASK-1844 — la revalidation FINALE (verrouillee) a pu refuser
        // l'ecriture alors que le controle initial, lui, l'avait laisse
        // passer (cloture survenue PENDANT le fichier/l'apercu de lien).
        // Dans ce cas, rien n'a ete ecrit : le comportement attendu cote
        // utilisateur est le MEME que pour le refus initial — aucun champ
        // vide, aucun evenement annonce, comme si l'envoi n'avait jamais ete
        // tente.
        if ($msg === null) {
            return;
        }

        $this->newMessage = '';
        $this->cancelReply();

        /*
         * TASK-1704 correctif 4 — annoncer l'envoi au composeur.
         *
         * Cyril : « apres avoir partage un lien, le composer chat reste
         * agrandi. »
         *
         * Le composeur partage ecoute `message-sent.window` pour recalculer sa
         * hauteur et son etat `hasText`. Le ChatLoop emet cet evenement
         * (`LoopChat::send()`) ; la Messagerie ne l'a JAMAIS emis. D'ou un
         * defaut visible ici et nulle part ailleurs.
         *
         * Une seule cause, trois symptomes — c'est ce qui rend cette ligne
         * suffisante :
         *
         * 1. La hauteur reste celle du texte envoye. Sur une URL longue, elle
         *    occupe la moitie de l'ecran d'un telephone.
         * 2. `hasText` reste VRAI, donc le bouton d'envoi garde son apparence
         *    active alors que le champ est vide.
         * 3. Ce bouton invite alors a un second appui, qui declenche
         *    « Ajoutez un message ou une image. » — l'erreur visible sous un
         *    champ vide sur la capture de Cyril. Elle n'etait pas un defaut
         *    de validation : la validation avait raison, c'est le bouton qui
         *    mentait sur son etat.
         *
         * `wire:model` (sans `.live`) ne reecrit pas le champ tant que rien ne
         * le declenche : vider la propriete cote serveur ne suffit donc pas.
         */
        $this->dispatch('message-sent');
    }

    /**
     * TASK-1844 — le MEME predicat pour les DEUX revalidations de
     * `sendMessage()` (initiale, et finale sous verrou) : appartenance au
     * couple buyer/seller, puis statut encore ouvert. Factorise pour que
     * les deux controles ne puissent pas un jour diverger en silence.
     */
    private function autorisationEtStatutValides(Transaction $transaction, User $user): bool
    {
        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return false;
        }

        return ! in_array($transaction->status, ['completed', 'refused', 'cancelled']);
    }

    /**
     * TASK-1844 — enregistre l'envoi de l'email APRES le commit REEL,
     * jamais depuis l'interieur de la transaction qui vient de creer le
     * message. `DB::afterCommit()` gere seul l'imbrication eventuelle
     * (savepoint vs commit racine) : rien de plus a faire ici pour honorer
     * « transaction externe ».
     *
     * ## La RESOLUTION du destinataire est DANS `rescue()`, pas avant
     * (revue MASTER, controle de synchronisation #2)
     *
     * Avant ce correctif, `$transaction->seller`/`->buyer` (chargement
     * paresseux de la relation) s'executait AVANT le `rescue()` — hors de
     * sa protection. Une panne a CET endroit precis (connexion perdue entre
     * le commit et ce callback, ligne supprimee entre-temps...) aurait
     * leve une exception NON rattrapee a l'interieur d'un callback
     * `afterCommit` — exactement le defaut deja documente pour
     * `LoopMessageCreated`/`NotificationEmitter` (TASK-1839/1843) :
     * `executeCallbacks()` n'isole PAS les callbacks entre eux, et une
     * exception ici aurait pu se faire passer pour un echec de l'envoi
     * LUI-MEME aupres de l'appelant, alors que le message est deja commis.
     *
     * Desormais, resolution ET envoi sont DANS LE MEME `rescue()` : l'un
     * ou l'autre peut echouer, le resultat est identique — journalise,
     * jamais remonte.
     *
     * `$destinataireId` est calcule AVANT, a partir des seules colonnes
     * scalaires (`buyer_id`/`seller_id`, deja chargees, jamais une requete)
     * : le contexte de log reste complet MEME si la resolution de la
     * relation est precisement ce qui a echoue.
     *
     * Le `rescue()` et son contexte de log restent, pour le reste,
     * IDENTIQUES a l'ancien emplacement (avant cette TASK) — aucune cle,
     * aucun message ne change, et le nombre d'envois (un seul) non plus.
     */
    private function notifierApresCommit(Transaction $transaction, User $user, Message $msg): void
    {
        $destinataireId = $transaction->buyer_id === $user->id
            ? $transaction->seller_id
            : $transaction->buyer_id;

        DB::afterCommit(function () use ($transaction, $msg, $destinataireId) {
            // TASK-1858 — deportee vers la Managed Queue `notifications`,
            // meme job-famille que TASK-1855/1857 (`SendNewMessageReceivedEmail`).
            // `Bus::dispatch()`, PAS `SendNewMessageReceivedEmail::dispatch()`
            // (piege `PendingDispatch` deja corrige en TASK-1854/1855/1857) :
            // retourne depuis la fermeture `rescue()`, sa destruction serait
            // differee hors du `try/catch`.
            rescue(
                fn () => Bus::dispatch(new SendNewMessageReceivedEmail((string) $transaction->id, (string) $msg->id, (string) $destinataireId)),
                fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                    'event' => 'message_created_notification',
                    'message_id' => $msg->id,
                    'organization_id' => $transaction->organization_id,
                    'notifiable_id' => $destinataireId,
                    'exception_class' => $e::class,
                ]),
                false,
            );
        });
    }

    public function removePhoto(): void
    {
        $this->photo = null;
    }

    public function pinnedMessage(): ?Message
    {
        return $this->transaction()->messages()
            ->pinned()
            ->with('sender')
            ->first();
    }

    public function pinMessage(string $messageId): void
    {
        $user = auth()->user();
        $transaction = $this->transaction();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return;
        }

        // TASK-1869 — aucun NOUVEL epinglage sur une entraide. Le controle
        // est ici, apres l'appartenance et avant toute ecriture : un epinglage
        // ecrase celui en place (`update()` en masse juste en dessous), donc
        // un refus tardif aurait deja detache le message historique que
        // l'arbitrage MASTER 2 demande de preserver.
        if (! $this->gestesDeConversationAutorises($transaction)) {
            return;
        }

        $message = Message::where('id', $messageId)
            ->where('transaction_id', $transaction->id)
            ->first();

        if (! $message) {
            return;
        }

        Message::where('transaction_id', $transaction->id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);

        $message->pin($user);

        // TASK-1846 — meme motif que `saveEdit()` : `pinned_at` entre dans
        // `pollFingerprint`, mais la FREQUENCE a laquelle il est relu cote
        // client ne doit jamais degrader a cause d'un epinglage.
        ConversationBroadcaster::diffuserApresCommit($message);
    }

    public function unpinMessage(): void
    {
        $user = auth()->user();
        $transaction = $this->transaction();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return;
        }

        // TASK-1846 — l'instance est lue AVANT la mise a jour (et non un
        // `update()` en masse comme avant cette TASK) : il faut un `Message`
        // concret a transmettre a `ConversationBroadcaster`, et ce chemin
        // reste un no-op identique a avant si rien n'etait epingle.
        $message = Message::where('transaction_id', $transaction->id)
            ->whereNotNull('pinned_at')
            ->first();

        if (! $message) {
            return;
        }

        $message->update(['pinned_at' => null, 'pinned_by_id' => null]);

        ConversationBroadcaster::diffuserApresCommit($message);
    }

    public function toggleReaction(string $messageId, string $reactionType): void
    {
        $user = auth()->user();
        $transaction = $this->transaction();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return;
        }

        if (! in_array($reactionType, Reaction::REACTION_TYPES, true)) {
            return;
        }

        $message = Message::where('id', $messageId)
            ->where('transaction_id', $transaction->id)
            ->first();

        if (! $message) {
            return;
        }

        $existing = Reaction::where('user_id', $user->id)
            ->where('reactionable_id', $message->id)
            ->where('reactionable_type', Message::class)
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
                'reactionable_type' => Message::class,
                'reaction_type' => $reactionType,
            ]);
        }

        // TASK-1846 — les trois branches ci-dessus changent toutes le
        // hachage de reactions de `pollFingerprint` : un seul point d'appel,
        // apres la decision, couvre ajout/suppression/changement de type.
        ConversationBroadcaster::diffuserApresCommit($message);
    }

    private function storeImage($file): string
    {
        // TASK-1623 — le CONTENU, jamais le chemin (cf. LoopChat::storeImage).
        $img = Image::decode($file->get());
        $img->scaleDown(1200, 800);

        $filename = Str::uuid()->toString().'.webp';
        $relativePath = 'message-images/'.$this->organizationId.'/messages/'.$filename;

        Storage::disk('public')->put($relativePath, (string) $img->encode(new WebpEncoder(quality: 80)));

        return $relativePath;
    }

    /**
     * TASK-1704 — deposer un fichier sur le disque PRIVE.
     *
     * ## Le chemin ne porte PAS le nom d'origine
     *
     * `dossier_files` accole le nom au UUID ; ici le chemin est un UUID nu, et
     * le nom vit en base. Deux raisons :
     *
     * 1. Le nom vient de celui qui depose. Le faire entrer dans un chemin de
     *    systeme de fichiers, c'est devoir prouver qu'aucun `..`, aucun `/`,
     *    aucun octet nul, aucun nom reserve ne passe — une preuve qu'il faut
     *    refaire a chaque evolution. Un UUID n'a pas ce probleme.
     * 2. Le nom affiche doit pouvoir changer sans deplacer le fichier.
     *
     * ## Le type est DEVINE, pas annonce
     *
     * `getMimeType()` lit le contenu ; `getClientMimeType()` repete ce que le
     * navigateur a dit. Seul le premier a une valeur : c'est lui qu'on
     * enregistre, et c'est lui que la route gardee renverra en en-tete.
     *
     * @return array{path: string, disk: string, name: string, mime: string, size: int}
     */
    private function storeFile($file): array
    {
        $disque = 'message_files';

        $chemin = $this->organizationId.'/'.$this->transactionId.'/'.Str::uuid()->toString();

        Storage::disk($disque)->put($chemin, $file->get());

        return [
            'path' => $chemin,
            'disk' => $disque,
            'name' => $this->nomDeDepot($file),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * TASK-1886 — deposer un vocal, sous son prefixe DEDIE en tete de cle.
     *
     * `message-voice/{org}/{transaction}/{uuid}` : le purgeur n'efface que sous
     * ce prefixe, et une regle de cycle de vie R2 peut le viser sans jamais
     * atteindre une piece jointe durable.
     *
     * Le type enregistre est le type AUDIO honnete (`TYPES_VOCAUX`) : un WebM
     * sans image devine `video/webm` doit etre servi en `audio/webm`.
     *
     * @return array{path: string, disk: string, name: string, mime: string, size: int}
     */
    private function storeVoice($file): array
    {
        $disque = 'message_files';
        $chemin = self::PREFIXE_VOCAL.'/'.$this->organizationId.'/'.$this->transactionId.'/'.Str::uuid()->toString();

        // TASK-1887 — la MEME detection que la validation, jamais le type annonce.
        $type = $this->typeVocal($file) ?? 'audio/webm';

        Storage::disk($disque)->put($chemin, $file->get());

        return [
            'path' => $chemin,
            'disk' => $disque,
            'name' => __('messages.voice_file_name'),
            'mime' => $type,
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * TASK-1886 — la forme de la voix, reduite a ce qu'elle doit etre : au plus
     * 40 entiers de 0 a 100. Tout le reste est ecarte sans erreur — c'est un
     * dessin, pas une donnee dont depend un droit.
     *
     * @return list<int>
     */
    private function formeDeVoixBornee(): array
    {
        return collect(array_slice($this->voiceWaveform, 0, 40))
            ->filter(fn ($valeur) => is_numeric($valeur))
            ->map(fn ($valeur) => max(0, min(100, (int) round((float) $valeur))))
            ->values()
            ->all();
    }

    /**
     * Le nom d'origine, rendu inoffensif pour un en-tete HTTP.
     *
     * Il ne sert qu'a l'affichage et au `Content-Disposition`. `basename()`
     * ecarte tout segment de chemin ; les caracteres de controle sont retires
     * parce qu'un retour a la ligne dans un en-tete le COUPE, ce qui permet
     * d'en injecter un autre derriere. La longueur est bornee parce que la
     * colonne l'est.
     */
    private function nomDeDepot($file): string
    {
        $nom = basename((string) $file->getClientOriginalName());
        $nom = preg_replace('/[\x00-\x1F\x7F]/u', '', $nom) ?? '';
        $nom = trim($nom);

        if ($nom === '' || $nom === '.' || $nom === '..') {
            return __('messages.attachment_fallback_name');
        }

        return mb_substr($nom, 0, 200);
    }

    /**
     * TASK-1693 bug V — marquer lu, y compris les messages SYSTEME.
     *
     * `sender_id != $userId` les ecartait : ils portent `sender_id = NULL`, et
     * `NULL != 'x'` vaut NULL en SQL, donc jamais VRAI. Aucun message systeme
     * n'etait donc JAMAIS marque comme lu — toute la base de recette le
     * confirme.
     *
     * Tant qu'ils ne comptaient pas dans le badge, cela ne se voyait pas.
     * Depuis qu'ils y comptent (`User::unreadMessagesCount()`), un badge sans
     * ce correctif resterait allume DEFINITIVEMENT.
     */
    /**
     * TASK-1847 (correctif MASTER) — `messages.read_at` est un champ
     * PARTAGE, pas un modele de lecture par utilisateur : marquer un
     * message SYSTEME (`sender_id` NULL, compte comme non lu pour LES DEUX
     * participants — TASK-1693) comme lu le rend lu POUR TOUT LE MONDE en
     * une seule ecriture. Le compteur de l'AUTRE participant baisse donc
     * AUSSI en base des que CE lecteur ouvre le fil — sans ce correctif,
     * personne ne le lui signalait jamais en temps reel.
     *
     * L'horodatage `$maintenant`, pose UNE SEULE FOIS et reutilise pour
     * l'ecriture ET la verification qui suit, identifie EXACTEMENT les
     * lignes que CET appel vient de marquer — sans nouveau modele de
     * lecture, sans nouvelle migration. La verification ne part QUE si
     * `$affected > 0` : aucune requete supplementaire au rendu le plus
     * frequent (conversation deja lue, TASK-1836).
     */
    public function markRead(): void
    {
        $userId = auth()->id();
        $transactionId = $this->transaction()->id;
        $maintenant = now();

        $affected = Message::where('transaction_id', $transactionId)
            ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $userId))
            ->whereNull('read_at')
            ->update(['read_at' => $maintenant]);

        // TASK-1847 — un appel qui ne change AUCUNE ligne (conversation
        // deja lue, cas le plus frequent : `render()` appelle `markRead()`
        // a CHAQUE cycle) ne doit declencher aucun evenement.
        if ($affected === 0) {
            return;
        }

        $destinataires = [$userId];

        $aMarqueUnMessageSysteme = Message::where('transaction_id', $transactionId)
            ->whereNull('sender_id')
            ->where('read_at', $maintenant)
            ->exists();

        if ($aMarqueUnMessageSysteme) {
            $transaction = $this->transaction();
            $destinataires[] = $transaction->buyer_id === $userId ? $transaction->seller_id : $transaction->buyer_id;
        }

        foreach (array_unique($destinataires) as $destinataireId) {
            UnreadCountBroadcaster::diffuserApresLecture($destinataireId);
        }
    }

    /**
     * TASK-1836 — action appelee par `wire:poll.3000ms`, a la place du
     * defaut implicite qui rendait integralement a CHAQUE cycle (mesure :
     * 8 requetes dont UNE ecriture `UPDATE messages SET read_at = ...`
     * systematique, meme a 0 ligne affectee — conversation deja lue).
     *
     * `transaction(fresh: true)` est appelee ICI, INCONDITIONNELLEMENT, EN
     * PREMIER — avant toute decision de court-circuit. C'est la MEME
     * instruction qui s'execute deja a chaque `render()` aujourd'hui :
     * revalide l'appartenance (`buyer_id`/`seller_id`) et le tenant
     * (`ensureOrganizationContext()`), et leve un 404 si l'un des deux a
     * change. Une revocation d'acces n'est donc JAMAIS masquee par
     * `skipRender()` — le meme principe que `LoopChat::pollChat()` /
     * `booted()` (TASK-1835/T1772), applique ici a l'identique.
     *
     * Les echanges d'entraide avec elements metier externes (evaluation,
     * Flux, progression — tout ce qui n'est PAS `isDirectConversation()`)
     * restent VOLONTAIREMENT hors de l'optimisation : rendu complet a chaque
     * cycle, comme avant cette TASK. Perimetre du mandat MASTER du 15/11.
     */
    public function pollThread(): void
    {
        $transaction = $this->transaction(fresh: true);

        if (! $transaction->isDirectConversation()) {
            return;
        }

        $empreinte = $this->computePollFingerprint($transaction);
        $soupapeEcoulee = (now()->getTimestamp() - $this->lastFullRenderAt) >= self::INTERVALLE_SOUPAPE_SECONDES;

        if ($empreinte === $this->pollFingerprint && ! $soupapeEcoulee) {
            $this->skipRender();

            return;
        }

        $this->pollFingerprint = $empreinte;
        $this->lastFullRenderAt = now()->getTimestamp();
    }

    /**
     * TASK-1836 — une empreinte bon marche, pour les conversations DIRECTES
     * uniquement (voir {@see pollThread()}) :
     *
     * - messages : un hachage du contenu REEL (`body`/`pinned_at`/
     *   `edited_at`) de TOUS les messages de la transaction — PAS seulement
     *   `COUNT`/`MAX(updated_at)` : `Message` n'a pas d'`updated_at`
     *   (`$timestamps = false`), et un comptage de transition seul ne
     *   distingue pas une DEUXIEME edition d'un message de la premiere dans
     *   la meme seconde (meme piege que T1835, verifie identique ici : le
     *   format de date par defaut d'Eloquent tronque les microsecondes avant
     *   l'ecriture). `pinned_at` dans le hachage couvre aussi l'epinglage, le
     *   depinglage ET le changement de message epingle en un seul signal.
     *
     *   Mesure AVANT implementation (demandee par le mandat) : ce hachage
     *   charge l'historique ENTIER de la conversation (pas de fenetre, cette
     *   surface n'en a pas) — 0.5-0.8 ms a 15 messages, ~2-3 ms a 100,
     *   ~18-23 ms a 1000 (SQLite et PostgreSQL reels). A comparer au cout
     *   d'un rendu COMPLET a cette meme echelle, mesure dans la meme
     *   campagne : ~250 ms a 100 messages, **~2,7 a 3,4 SECONDES** a 1000 —
     *   le hachage reste donc de 100x a 150x moins cher que le rendu qu'il
     *   evite, a toutes les echelles mesurees. Pas de fenetre bornee
     *   introduite : la mesure ne le justifie pas, et en ajouter une
     *   complexifierait sans necessite demontree.
     * - reactions : un hachage de (id:reaction_type) de toutes les reactions
     *   portees par les messages de cette transaction — PAS `COUNT` +
     *   `MAX(id)` seuls (technique T1835) : ceux-ci ne distinguent pas un
     *   CHANGEMENT DE TYPE sur une reaction existante (meme ligne, meme id,
     *   `reaction_type` modifie par `toggleReaction()`), explicitement
     *   demande par le mandat. Le couple (id, type) couvre ajout, suppression
     *   (DELETE dur, aucune colonne de suppression douce) ET changement de
     *   type, sans dependre d'un horodatage.
     * - statut de la transaction : deja charge par `transaction(fresh: true)`
     *   ci-dessus, cout marginal NUL — couvre les etats reellement affiches
     *   qui changent la vue (ex. composeur masque si l'echange est clos).
     */
    private function computePollFingerprint(Transaction $transaction): string
    {
        // Revue MASTER du 15/11 (durcissement avant fusion) — `transaction_id`
        // ET `organization_id` filtres EXPLICITEMENT, jamais l'un sans
        // l'autre : meme doctrine que T1835 (`LoopChat::loadedWindowMessages()`),
        // ici en defense en profondeur plutot qu'en correctif d'une faille
        // reelle — `transactionId`/`organizationId` sont deja `#[Locked]`
        // dans ce composant, donc deja non falsifiables depuis le JS de la
        // page.
        $empreinteMessages = md5(
            Message::query()
                ->where('transaction_id', $transaction->id)
                ->where('organization_id', $transaction->organization_id)
                ->orderBy('id')
                ->get(['id', 'body', 'pinned_at', 'edited_at'])
                ->map(fn (Message $m) => "{$m->id}:{$m->body}:{$m->pinned_at}:{$m->edited_at}")
                ->implode('|')
        );

        $empreinteReactions = md5(
            DB::table('reactions')
                ->join('messages', function ($jointure) {
                    $jointure->on('messages.id', '=', 'reactions.reactionable_id')
                        ->where('reactions.reactionable_type', Message::class);
                })
                ->where('messages.transaction_id', $transaction->id)
                ->where('messages.organization_id', $transaction->organization_id)
                ->where('reactions.organization_id', $transaction->organization_id)
                ->orderBy('reactions.id')
                ->get(['reactions.id', 'reactions.reaction_type'])
                ->map(fn ($r) => "{$r->id}:{$r->reaction_type}")
                ->implode('|')
        );

        return implode('|', [$empreinteMessages, $empreinteReactions, $transaction->status]);
    }

    public function render()
    {
        $transaction = $this->transaction(fresh: true);
        $this->markRead();

        $messages = $transaction->messages()
            ->with('sender')
            ->with('replyTo.sender')
            ->with('reactions')
            ->orderBy('created_at')
            ->get();

        $pinnedMessage = $this->pinnedMessage();
        $unreadCount = auth()->user()->unreadMessagesCount();

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

        $organizationSlug = $this->organizationSlug;

        return view('livewire.message-thread', compact('transaction', 'messages', 'pinnedMessage', 'unreadCount', 'reactionData', 'myReactions', 'organizationSlug'));
    }

    /**
     * TASK-1691 — la PREUVE DE PARTICIPATION passe ici, donc une seule fois.
     *
     * `sendMessage()`, `pinMessage()`, `unpinMessage()` et `toggleReaction()`
     * la portaient chacune de leur cote — et elles tenaient. Les deux methodes
     * qui ne la portaient PAS, `render()` et `markRead()`, sont justement
     * celles qui fuyaient et celle qui ecrivait. Une garde repetee quatre fois
     * est une garde qu'une cinquieme methode oubliera.
     *
     * `TransactionPolicy::view` exige deja `buyer_id` ou `seller_id` sur la
     * PAGE : aucun fil legitimement monte ne perd quoi que ce soit. Et le refus
     * est un **404** indistinguable d'une transaction inexistante — l'oracle
     * d'existence mesure en T1690 (200 pour un UUID reel, 404 pour un faux) se
     * referme, ce qui compte d'autant plus que `Transaction` emet des UUIDv7
     * dont les 48 premiers bits sont un horodatage.
     */
    private function transaction(bool $fresh = false): Transaction
    {
        $this->ensureOrganizationContext();

        if ($fresh || ! $this->transaction) {
            $userId = auth()->id();

            $this->transaction = Transaction::where('organization_id', $this->organizationId)
                ->where(fn ($query) => $query->where('buyer_id', $userId)->orWhere('seller_id', $userId))
                ->findOrFail($this->transactionId);
        }

        return $this->transaction;
    }

    private function ensureOrganizationContext(): void
    {
        if (currentOrganization()?->id === $this->organizationId) {
            return;
        }

        $organization = Organization::findOrFail($this->organizationId);
        $this->organizationSlug = $organization->slug;
        app()->instance('current_organization', $organization);
    }
}
