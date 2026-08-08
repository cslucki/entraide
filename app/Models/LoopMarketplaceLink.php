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

    /**
     * Ce que « vivant » veut dire, **au meme endroit pour tout le monde**.
     *
     * Les deux moities du produit ne parlaient pas la meme langue : le
     * selecteur ne proposait qu'`open`, la garde acceptait aussi
     * `in_progress`, et `isLive()` la disait vivante. Trois verites sur le meme
     * etat.
     */
    public const LIVE_OFFER_STATUSES = ['active'];

    public const LIVE_REQUEST_STATUSES = ['open', 'in_progress'];

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

    /**
     * `offer` ou `request` — une seule lecture, pour que les vues s'accordent.
     *
     * Une ligne **sans aucune cible** n'est pas une Demande : le ternaire
     * d'origine en faisait une par defaut, et une ligne incoherente se lisait
     * alors comme une Demande vide. Elle n'a plus de nature du tout.
     */
    public function kind(): ?string
    {
        if ($this->service_id !== null) {
            return self::KIND_OFFER;
        }

        return $this->service_request_id !== null ? self::KIND_REQUEST : null;
    }

    /** L'Offre ou la Demande, selon le cas. */
    public function target(): Service|ServiceRequest|null
    {
        return $this->kind() === self::KIND_OFFER ? $this->service : $this->serviceRequest;
    }

    /**
     * Le titre a afficher.
     *
     * **Un compte desactive retire son texte aussi.** `ServiceController::show()`
     * rend 404 pour son Offre et `scopeActive()` l'exclut du catalogue ; la
     * masquer par le nom seulement laissait son titre et sa description en
     * clair, et la personne concernee, ne pouvant plus se connecter, n'a aucun
     * recours.
     */
    public function displayTitle(): string
    {
        if ($this->authorIsGone()) {
            return (string) __('loops.cards.marketplace.author_gone_title');
        }

        return (string) ($this->target()?->title ?? '');
    }

    public function displayDescription(): string
    {
        if ($this->authorIsGone()) {
            return '';
        }

        return (string) ($this->target()?->description ?? '');
    }

    public function authorIsGone(): bool
    {
        return (bool) $this->author()?->isDeactivated();
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

        // **Un compte desactive retire ses Offres du catalogue.**
        // `Service::scopeActive()` les exclut par `activeAccount()`, et
        // `services.show` rend 404. Sans ce controle, la Card les annonçait
        // vivantes et contactables — la seule surface du produit a le faire.
        if ($cible->user?->isDeactivated()) {
            return false;
        }

        // **Les deux n'ont pas le meme vocabulaire.** Une Offre est vivante
        // quand elle est `active` (parmi `active|paused|deleted`). Une Demande
        // l'est tant qu'elle n'est pas `closed` : `in_progress` veut dire
        // qu'on s'en occupe, pas qu'elle a quitte le catalogue — la badger
        // « retiree » etait un enonce faux.
        return in_array(
            $cible->status,
            $this->kind() === self::KIND_OFFER ? self::LIVE_OFFER_STATUSES : self::LIVE_REQUEST_STATUSES,
            true,
        );
    }

    /**
     * Le nom a afficher pour la personne a contacter.
     *
     * `publicDisplayName()` et non `first_name` : un compte desactive ne
     * s'affiche nulle part ailleurs sous son vrai nom — Manifeste, Dossiers,
     * Roadmap et ChatLoop passent tous par la. Cette Card etait la seule a y
     * echapper.
     */
    public function authorName(): string
    {
        return $this->author()?->publicDisplayName() ?? '';
    }

    /** Idem pour la personne qui a mis en avant. */
    public function addedByName(): string
    {
        return $this->addedBy?->publicDisplayName() ?? '';
    }
}
