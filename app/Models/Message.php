<?php

namespace App\Models;

use App\Support\Files\TypesDeFichiersAcceptes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;

class Message extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['transaction_id', 'sender_id', 'reply_to_id', 'body', 'image_path', 'metadata', 'type', 'read_at', 'organization_id', 'pinned_at', 'pinned_by_id', 'edited_at', 'edited_by',
        // TASK-1704 — une piece jointe qui n'est pas une image.
        'file_path', 'file_disk', 'file_name', 'file_mime', 'file_size',
        // TASK-1886 — un message vocal ephemere.
        'voice_expires_at', 'voice_duration', 'voice_purged_at'];

    /**
     * TASK-1886 — duree de vie d'un vocal, a compter de l'ENVOI (arbitrage
     * Cyril, 22/11/2026).
     */
    public const VOICE_TTL_DAYS = 5;

    /** TASK-1886 — duree maximale d'un enregistrement, en secondes (5 min). */
    public const VOICE_MAX_SECONDS = 300;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'read_at' => 'datetime',
            'metadata' => 'array',
            'pinned_at' => 'datetime',
            // TASK-1693 bug Q — ce modele n'a pas d'`updated_at`
            // (`$timestamps = false`) : sans cette colonne, une edition serait
            // invisible.
            'edited_at' => 'datetime',
            'voice_expires_at' => 'datetime',
            'voice_duration' => 'integer',
            'voice_purged_at' => 'datetime',
        ];
    }

    /**
     * TASK-1886 — ce message est-il un VOCAL enregistre dans le composeur ?
     *
     * Le marqueur est l'echeance, jamais le type MIME : un `.mp3` JOINT est
     * aussi de l'audio, et il ne doit ni expirer ni s'afficher comme un vocal.
     * Seul le chemin d'envoi vocal (`MessageThread::$voice`) pose l'echeance.
     */
    public function isVoice(): bool
    {
        return $this->voice_expires_at !== null;
    }

    /**
     * TASK-1886 — le vocal est-il ECHU ?
     *
     * Vrai des l'echeance, que le purgeur soit deja passe ou non : l'acces se
     * ferme a l'heure dite, la suppression du fichier peut suivre quelques
     * minutes plus tard sans rien rouvrir.
     */
    public function voiceIsExpired(): bool
    {
        return $this->isVoice()
            && ($this->voice_purged_at !== null || ! $this->voice_expires_at->isFuture());
    }

    /**
     * TASK-1886 — « Disparaît dans 3 j », « … dans 5 h » : la mention
     * discrete demandee par Cyril sous chaque vocal.
     */
    public function voiceExpiryForHumans(): ?string
    {
        if (! $this->isVoice() || $this->voiceIsExpired()) {
            return null;
        }

        $minutes = (int) ceil(now()->diffInMinutes($this->voice_expires_at, false));

        if ($minutes >= 24 * 60) {
            return __('messages.voice_expires_days', ['n' => (int) ceil($minutes / (24 * 60))]);
        }

        if ($minutes >= 60) {
            return __('messages.voice_expires_hours', ['n' => (int) ceil($minutes / 60)]);
        }

        return __('messages.voice_expires_soon');
    }

    /** TASK-1886 — la forme de la voix enregistree avec le vocal. */
    public function voiceWaveform(): ?array
    {
        $forme = is_array($this->metadata) ? ($this->metadata['voice_waveform'] ?? null) : null;

        return is_array($forme) && $forme !== [] ? array_values($forme) : null;
    }

    /** TASK-1886 — « 0:07 », « 4:59 » : la notation des lecteurs vocaux. */
    public function voiceDurationForHumans(): ?string
    {
        if ($this->voice_duration === null) {
            return null;
        }

        $secondes = max(0, (int) $this->voice_duration);

        return intdiv($secondes, 60).':'.str_pad((string) ($secondes % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * TASK-1886 — le libelle d'un vocal la ou s'affiche d'ordinaire le TEXTE
     * du message : citation, message epingle, liste des conversations,
     * notification. Un vocal n'a pas de texte — sans ce libelle, ces quatre
     * endroits afficheraient un vide.
     *
     * `null` pour tout message qui n'est pas un vocal : l'appelant garde alors
     * son propre rendu, inchange.
     */
    public function voiceSummary(): ?string
    {
        if (! $this->isVoice()) {
            return null;
        }

        $duree = $this->voiceDurationForHumans();

        return $duree !== null
            ? __('messages.voice_summary_with_duration', ['duree' => $duree])
            : __('messages.voice_summary');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
    }

    /** TASK-1693 bug Q — qui a modifie ce message, s'il l'a ete. */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactionable');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /**
     * TASK-1704 — ce message porte-t-il un fichier (hors image) ?
     *
     * La question porte sur le CHEMIN, pas sur le nom : un message dont le nom
     * aurait ete perdu porte quand meme son fichier, et doit rester
     * telechargeable.
     */
    public function hasFile(): bool
    {
        return $this->file_path !== null && $this->file_path !== '';
    }

    /**
     * Le nom a AFFICHER, qui n'est jamais celui du chemin.
     *
     * Le fichier est stocke sous un UUID nu (voir `MessageThread::storeFile`).
     * Si `file_name` manquait — une ligne ecrite a la main, un import — mieux
     * vaut un libelle generique qu'un UUID brut presente comme un nom de
     * fichier.
     */
    public function fileDisplayName(): string
    {
        $nom = trim((string) $this->file_name);

        return $nom !== '' ? $nom : __('messages.attachment_fallback_name');
    }

    /**
     * Le poids, en unites lisibles.
     *
     * Il s'affiche AVANT le telechargement, parce que sur un telephone en
     * donnees mobiles, savoir qu'une piece pese 40 Mo change la decision de
     * l'ouvrir.
     */
    public function fileSizeForHumans(): ?string
    {
        $octets = (int) $this->file_size;

        if ($octets <= 0) {
            return null;
        }

        foreach ([['Go', 1073741824], ['Mo', 1048576], ['Ko', 1024]] as [$unite, $seuil]) {
            if ($octets >= $seuil) {
                return round($octets / $seuil, $octets / $seuil >= 10 ? 0 : 1).' '.$unite;
            }
        }

        return $octets.' o';
    }

    /**
     * Le navigateur sait-il AFFICHER cette piece, plutot que la telecharger ?
     *
     * La liste est volontairement courte : les types dont tout navigateur
     * courant embarque un moteur de rendu. Proposer « ouvrir » pour un `.docx`
     * declencherait un telechargement malgre l'intitule — un bouton qui ne
     * fait pas ce qu'il annonce.
     *
     * Le Markdown y figure parce que `DossierFileTransport::typeAffichable()`
     * le sert en `text/plain`, et devient donc lisible dans l'onglet.
     */
    public function fileIsPreviewable(): bool
    {
        // TASK-1772 — les videos entrent ici : c'est cette URL que la balise
        // `<video>` de la bulle consomme. Elle reste GARDEE (meme policy que
        // le fil) — « apercevable » ne veut pas dire « public ».
        // TASK-1886 — un vocal se LIT dans la bulle : la balise `<audio>`
        // consomme cette URL, gardee comme celle d'une video. Un audio JOINT
        // classique n'entre pas ici : son comportement reste celui de T1704.
        if ($this->isVoice()) {
            return ! $this->voiceIsExpired();
        }

        return in_array($this->file_mime, [
            'application/pdf',
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'text/plain', 'text/markdown', 'text/csv',
            ...TypesDeFichiersAcceptes::VIDEO,
        ], true);
    }

    /**
     * TASK-1704 correctif 2 — qui peut modifier ce message ?
     *
     * Cyril : « il faudrait aussi pouvoir modifier les messages textes, on peut
     * le faire sur la chatloop. »
     *
     * La regle est reprise MOT POUR MOT de `LoopMessage::isEditableBy()` :
     * seul l'auteur, seulement un message de membre, et jamais un message
     * supprime. Elle n'est pas reinventee — deux surfaces qui repondent
     * differemment a « puis-je corriger ma faute de frappe ? » seraient
     * impossibles a expliquer.
     *
     * Les messages SYSTEME en sont exclus par le `type` : ils relatent un fait
     * (un echange accepte, des points verses) et ne sont la parole de
     * personne. Les reecrire falsifierait l'historique de l'echange.
     *
     * Aucune fenetre de temps : le ChatLoop n'en a pas, et en introduire une
     * ici serait un RETRAIT de capacite par rapport a ce que Cyril demande.
     */
    public function isEditableBy(User $user): bool
    {
        // TASK-1886 — un vocal ne se « corrige » pas : il n'a pas de texte, et
        // `saveEdit()` reecrit `metadata`, ou vit sa forme d'onde. WhatsApp
        // ne propose pas non plus de modifier une note vocale.
        return $this->type === 'user'
            && ! $this->isVoice()
            && ! $this->isDeleted()
            && $this->sender_id === $user->id;
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }

    public function scopePinned($query)
    {
        return $query->whereNotNull('pinned_at');
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    /**
     * Contrat attendu par le composant partage
     * `components/conversation/pinned-message-banner` (rendu aussi avec
     * LoopMessage). Ce modele n'a aucune notion de suppression : ni colonne
     * `deleted_at`, ni SoftDeletes. Un message de transaction n'est donc
     * jamais considere comme supprime.
     */
    public function isDeleted(): bool
    {
        return false;
    }

    public function pin(User $user): void
    {
        $this->pinned_at = now();
        $this->pinned_by_id = $user->id;
        $this->save();
    }

    public function unpin(): void
    {
        $this->pinned_at = null;
        $this->pinned_by_id = null;
        $this->save();
    }
}
