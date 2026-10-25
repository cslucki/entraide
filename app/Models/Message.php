<?php

namespace App\Models;

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
        'file_path', 'file_disk', 'file_name', 'file_mime', 'file_size'];

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
        ];
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
        return in_array($this->file_mime, [
            'application/pdf',
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'text/plain', 'text/markdown', 'text/csv',
        ], true);
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
