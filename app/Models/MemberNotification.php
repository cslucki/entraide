<?php

namespace App\Models;

use App\Support\Notifications\NotificationInvariants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * TASK-1372 — une notification IN_APP adressee a un membre.
 *
 * ## Elle ne contient RIEN a lire
 *
 * Pas de titre, pas d'extrait, pas d'URL : `object_type` + `object_id`, et le
 * rendu resoudra l'objet en direct sous les permissions du moment. Une
 * notification vieille d'un mois n'ouvrira donc aucune porte fermee depuis.
 *
 * Cette tranche garantit **le stockage refs-only, et rien de plus**. La preuve
 * « apres revocation, aucune fuite au rendu » appartient au Centre et au premier
 * objet metier, puisqu'aucun renderer n'existe encore.
 *
 * ## La garde est STRUCTURELLE, pas conventionnelle
 *
 * `booted()` applique `NotificationInvariants` sur `creating` ET sur `updating`.
 * Une ligne cross-tenant, une cle absente du catalogue, un `object_type`
 * incoherent, un UUID malforme : refuses par l'emetteur comme par un `create()`
 * direct. Et une ligne creee ne peut plus changer de tenant ni de destinataire :
 * `creating` seul laissait la porte `update()` grande ouverte, puisque ces
 * colonnes sont affectables en masse.
 *
 * La premiere version faisait reposer cela sur la seule discipline d'appeler
 * `NotificationEmitter`. La revue adversariale a montre qu'un `create()` direct
 * suffisait a tout contourner. Une convention n'est pas une frontiere.
 *
 * **Ce que cette garde ne couvre pas, et il faut le dire :** les ecritures qui
 * n'emettent aucun evenement de modele — `insert()`, `DB::table()->insert()`,
 * `upsert()`, `insertOrIgnore()`, `withoutEvents()`, `saveQuietly()`. C'est la
 * limite d'Eloquent, la meme pour tous les modeles du depot. `saveQuietly()`
 * merite d'etre nomme parce qu'il se lit comme une sauvegarde ordinaire.
 *
 * ## `forRecipient()` est la seule porte de lecture
 *
 * Le couple `(organization_id, recipient_id)` est indissociable — meme idiome
 * qu'`AiShellMessage::forThread()`. **Jamais un `where('recipient_id')` nu
 * ailleurs** : l'identite de la personne ne suffit pas, la frontiere de tenant
 * doit etre dans la meme requete.
 *
 * Le scope global `BelongsToOrganizationScope` est volontairement ECARTE. Il
 * resout le tenant depuis le contexte de requete et retombe sur
 * `whereRaw('0 = 1')` quand aucune Organization n'est resolue : dans un job de
 * file d'attente — exactement la ou la tranche EMAIL asynchrone lira ces lignes
 * — il rendrait la table silencieusement vide. Une frontiere explicite vaut
 * mieux qu'une frontiere qui s'evapore hors requete HTTP.
 */
class MemberNotification extends Model
{
    use HasUuids;

    /**
     * `read_at` est ABSENT a dessein.
     *
     * L'etat de lecture n'est pas une donnee que l'appelant pose : c'est le
     * resultat d'un geste dont il faut prouver le proprietaire. Il se change par
     * `markAsReadFor()`, jamais par affectation de masse.
     */
    protected $fillable = [
        'organization_id',
        'recipient_id',
        'notification_key',
        'event_id',
        'object_type',
        'object_id',
        'actor_id',
        'collapse_key',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            // La normalisation appartient a la garde, pas a l'appelant : sinon
            // un `create()` direct stockerait un UUID en majuscules et la
            // deduplication redeviendrait dependante du moteur.
            //
            // On ne reinjecte QUE les colonnes que la normalisation peut
            // changer. Repasser tous les attributs par `setAttribute()`
            // fonctionne aujourd'hui, mais reencoderait une valeur deja encodee
            // le jour ou une cast `array`/`json` apparaitrait sur ce modele.
            $notification->forceFill(NotificationInvariants::canonicalize($notification->only(
                NotificationInvariants::normalizableColumns()
            )));

            NotificationInvariants::assert($notification->getAttributes());
        });

        // `creating` seul ne suffisait pas. `organization_id` et `recipient_id`
        // sont affectables en masse : un `update()` — celui qu'un futur
        // controleur du Centre ecrirait sans y penser — deplacait donc une ligne
        // d'un tenant a l'autre sans qu'aucune garde ne se declenche.
        //
        // Une notification est un fait date : a qui, dans quel tenant, a propos
        // de quoi. Cela ne se corrige pas, cela se remplace. Seul l'etat de
        // lecture evolue.
        static::updating(function (self $notification): void {
            $modifiees = array_keys($notification->getDirty());
            $figees = array_intersect($modifiees, NotificationInvariants::IMMUTABLE);

            if ($figees !== []) {
                throw new RuntimeException(
                    'A notification is an immutable fact: ['.implode(', ', $figees).'] cannot change after creation.'
                );
            }

            // `collapse_key` est la seule colonne metier encore mutable, et
            // c'est celle qui porte un contrat de longueur. Sans cette ligne,
            // un `update()` la faisait deborder en silence sur SQLite et lever
            // `22001` sur PostgreSQL.
            if (in_array('collapse_key', $modifiees, true)) {
                NotificationInvariants::assertCollapseKeyValue($notification->collapse_key);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** La seule porte de lecture : tenant ET destinataire, jamais l'un sans l'autre. */
    public function scopeForRecipient(Builder $query, string $organizationId, string $recipientId): Builder
    {
        return $query
            ->where('organization_id', $organizationId)
            ->where('recipient_id', $recipientId);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Marquer lu EXIGE de nommer le proprietaire.
     *
     * Un `markAsRead()` sans argument ferait dependre la securite de l'endroit
     * ou l'instance a ete chargee — et un futur controleur avec route model
     * binding chargerait n'importe quel UUID. Connaitre l'identifiant d'une
     * notification n'a jamais ete un droit sur elle : le couple attendu est
     * exige ici, et le refus est bruyant.
     *
     * Le geste reste idempotent : la premiere lecture fait foi, une seconde ne
     * fait pas glisser la date.
     */
    public function markAsReadFor(string $organizationId, string $recipientId): void
    {
        if ($this->organization_id !== $organizationId || $this->recipient_id !== $recipientId) {
            throw new RuntimeException('Cannot mark a notification read on behalf of another member.');
        }

        if ($this->read_at !== null) {
            return;
        }

        $this->forceFill(['read_at' => now()])->save();
    }
}
