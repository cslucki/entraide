<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une Offre ou une Demande mise en avant dans une Boucle.
 *
 * **C'est un lien, jamais une copie.** Le titre, la description et le statut
 * sont lus sur l'Offre ou la Demande a chaque affichage : la corriger la
 * corrige partout.
 *
 * `service_id` **ou** `service_request_id`, jamais les deux.
 */
class LoopMarketplaceLink extends Model
{
    public const KIND_OFFER = 'offer';

    public const KIND_REQUEST = 'request';

    use HasUuids;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'added_by',
        'service_id',
        'service_request_id',
        'note',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    /** Qui l'a mise en avant — distinct de qui l'a ecrite. */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /** `offer` ou `request` — une seule lecture, pour que les vues s'accordent. */
    public function kind(): string
    {
        return $this->service_id !== null ? self::KIND_OFFER : self::KIND_REQUEST;
    }

    /** L'Offre ou la Demande, selon le cas. */
    public function target(): Service|ServiceRequest|null
    {
        return $this->kind() === self::KIND_OFFER ? $this->service : $this->serviceRequest;
    }

    public function displayTitle(): string
    {
        return (string) ($this->target()?->title ?? '');
    }

    public function displayDescription(): string
    {
        return (string) ($this->target()?->description ?? '');
    }

    /**
     * L'auteur de l'Offre ou de la Demande — la personne a contacter.
     *
     * Ce n'est **pas** `addedBy` : quelqu'un peut mettre en avant l'Offre d'une
     * autre personne, et confondre les deux ferait ecrire au mauvais.
     */
    public function author(): ?User
    {
        return $this->target()?->user;
    }

    /**
     * Une Offre retiree du catalogue cesse d'etre montree ici.
     *
     * Le `status` fait foi, lu sur l'objet et non recopie : sans cela, une
     * Offre desactivee restait visible dans la Boucle, et quelqu'un
     * l'aurait sollicitee pour rien.
     */
    public function isLive(): bool
    {
        $cible = $this->target();

        if (! $cible) {
            return false;
        }

        // **Les deux n'ont pas le meme mot pour « vivant »** : une Offre est
        // `active`, une Demande est `open`. Les confondre aurait montre toutes
        // les Demandes comme eteintes, ou l'inverse.
        return $cible->status === ($this->kind() === self::KIND_OFFER ? 'active' : 'open');
    }
}
