<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TASK-1372 — les invariants d'une notification, en UN SEUL endroit.
 *
 * ## Pourquoi cette classe existe
 *
 * La premiere version placait ces gardes dans `NotificationEmitter`. La revue
 * adversariale a montre que cela ne protegeait rien : `$fillable` porte les
 * colonnes, donc un `MemberNotification::create()` direct creait sans
 * difficulte une ligne cross-tenant, avec une cle inconnue et un `object_type`
 * incoherent. **« La seule porte d'ecriture » etait une convention, pas une
 * garde** — et une convention se contourne par distraction.
 *
 * Les invariants vivent donc ici, et `MemberNotification::booted()` les applique
 * sur `creating` **et** sur `updating`. C'est la doctrine de T1370 : quand une
 * regle ne tient pas, on ne la reecrit pas — on retire l'autorite de faire
 * autrement.
 *
 * ## La normalisation appartient a la garde, pas a l'appelant
 *
 * `canonicalize()` est appelee par le modele, avant `assert()`. La premiere
 * correction l'avait laissee dans l'emetteur : un `create()` direct stockait
 * donc encore un UUID en majuscules, et la deduplication redevenait dependante
 * du moteur. Une normalisation qui ne couvre pas toutes les portes ne normalise
 * rien.
 *
 * ## Le catalogue reste la seule autorite metier
 *
 * Cette classe ne redecide rien. Elle demande au `NotificationCatalogue` quelles
 * cles existent, quel `object_type` chacune peut referencer et quels canaux elle
 * autorise. Deux autorites qui divergent valent moins qu'une seule.
 */
final class NotificationInvariants
{
    /** Longueur de la colonne `collapse_key` au schema. */
    public const MAX_COLLAPSE_KEY = 120;

    /**
     * TOUTES les colonnes `uuid` de la table.
     *
     * La premiere version n'en normalisait que deux — `event_id` et
     * `object_id`. Les trois autres sont pourtant des `uuid` natifs sur
     * PostgreSQL et de simples `varchar` sur SQLite : un `recipient_id` en
     * majuscules y trouvait donc son membre d'un cote et pas de l'autre, ce qui
     * transformait une difference de casse en **erreur de frontiere de tenant**
     * sur un seul moteur. Une liste partielle valait ici moins que pas de liste
     * du tout, puisqu'elle donnait l'illusion du contraire.
     */
    private const UUID_COLUMNS = [
        'organization_id',
        'recipient_id',
        'event_id',
        'object_id',
        'actor_id',
    ];

    /**
     * Colonnes qu'une notification ne peut JAMAIS changer apres coup.
     *
     * Une notification est un fait date : a qui, dans quel tenant, a propos de
     * quoi. Rien de tout cela ne se corrige — cela se remplace. Laisser
     * `organization_id` ou `recipient_id` mutables offrirait par `update()` la
     * traversee de tenant que `creating` refuse, et `$fillable` les porte.
     */
    public const IMMUTABLE = [
        'organization_id',
        'recipient_id',
        'notification_key',
        'event_id',
        'object_type',
        'object_id',
        'actor_id',
    ];

    /**
     * Ramene les colonnes a leur forme canonique AVANT verification.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    /**
     * Les colonnes que `canonicalize()` peut modifier.
     *
     * @return list<string>
     */
    public static function normalizableColumns(): array
    {
        return self::UUID_COLUMNS;
    }

    public static function canonicalize(array $attributes): array
    {
        foreach (self::UUID_COLUMNS as $colonne) {
            if (array_key_exists($colonne, $attributes)) {
                $attributes[$colonne] = self::canonicalUuid($attributes[$colonne]);
            }
        }

        return $attributes;
    }

    /**
     * Forme canonique d'un UUID : minuscules, et la chaine vide vaut absence.
     *
     * Sans le repli de casse, l'idempotence dependrait du moteur : PostgreSQL a
     * un type `uuid` natif qui replie tout seul, donc deux emissions du meme
     * UUID ecrit differemment se dedupliquent ; SQLite stocke deux chaines
     * distinctes et cree DEUX lignes. Le meme code, deux comportements — et le
     * moteur le plus permissif serait celui des tests.
     *
     * La chaine vide, elle, n'est pas un UUID mais se glissait entre les
     * mailles : acceptee comme « absente » a la validation, puis ecrite telle
     * quelle. SQLite l'aurait stockee, PostgreSQL aurait leve `22P02`. Elle
     * devient `null`, qui est ce qu'elle voulait dire.
     */
    public static function canonicalUuid(mixed $value): mixed
    {
        // Le type d'entree est volontairement `mixed`. Une signature `?string`
        // transformait une valeur d'un autre type en `TypeError` — qui echappe
        // aux deux `catch` de l'emetteur — la ou `assert()` sait en faire un
        // refus propre. La normalisation ne doit pas decider a la place de la
        // validation.
        if (! is_string($value) || $value === '') {
            return $value === '' ? null : $value;
        }

        return Str::lower($value);
    }

    /**
     * Verifie une ligne SUR SES COLONNES, telle qu'elle va etre inseree.
     *
     * On travaille sur les attributs bruts et non sur des objets metier :
     * c'est ce que la base va reellement recevoir, quel que soit l'appelant.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function assert(array $attributes): void
    {
        $notificationKey = (string) ($attributes['notification_key'] ?? '');
        $objectType = (string) ($attributes['object_type'] ?? '');

        if (! NotificationCatalogue::has($notificationKey)) {
            throw new InvalidArgumentException(
                "Notification key [{$notificationKey}] is not declared in NotificationCatalogue."
            );
        }

        // Cette tranche n'ecrit que des lignes IN_APP : une cle qui n'autorise
        // pas ce canal ne peut pas en produire une. La garde compte surtout pour
        // la suite — le jour ou une cle sera EMAIL seulement.
        if (! NotificationCatalogue::allowsChannel($notificationKey, NotificationCatalogue::CHANNEL_IN_APP)) {
            throw new InvalidArgumentException(
                "Notification key [{$notificationKey}] does not allow the in_app channel."
            );
        }

        $declared = NotificationCatalogue::objectTypeFor($notificationKey);

        if ($objectType !== $declared) {
            throw new InvalidArgumentException(
                "Notification key [{$notificationKey}] references object type [{$declared}], got [{$objectType}]."
            );
        }

        // `recipient_id` etait la seule partie OBLIGATOIRE qu'aucune validation
        // ne couvrait : une valeur malformee filait droit dans le `where('id')`
        // de la verification d'appartenance. SQLite n'y trouvait rien et rendait
        // une erreur de tenant trompeuse ; PostgreSQL levait `22P02`, une classe
        // d'exception entierement differente. Le meme appel, deux verdicts.
        self::assertUuid($attributes['organization_id'] ?? null, 'organization_id', required: true);
        self::assertUuid($attributes['recipient_id'] ?? null, 'recipient_id', required: true);
        self::assertUuid($attributes['event_id'] ?? null, 'event_id', required: true);
        self::assertUuid($attributes['object_id'] ?? null, 'object_id', required: false);
        self::assertUuid($attributes['actor_id'] ?? null, 'actor_id', required: false);
        self::assertCollapseKey($attributes['collapse_key'] ?? null);

        $organizationId = $attributes['organization_id'] ?? null;

        self::assertBelongsToOrganization($attributes['recipient_id'] ?? null, $organizationId, 'recipient', required: true);
        self::assertBelongsToOrganization($attributes['actor_id'] ?? null, $organizationId, 'actor', required: false);
    }

    private static function assertUuid(mixed $value, string $field, bool $required): void
    {
        if ($value === null) {
            if ($required) {
                throw new InvalidArgumentException("Notification {$field} must be a UUID.");
            }

            return;
        }

        if (! is_string($value) || ! Str::isUuid($value)) {
            throw new InvalidArgumentException("Notification {$field} must be a UUID.");
        }
    }

    /**
     * `collapse_key` vient de l'appelant : il doit tenir dans la colonne.
     *
     * PostgreSQL leve `22001` sur depassement, **SQLite ignore purement et
     * simplement les longueurs de varchar**. Sans cette garde, une cle de
     * regroupement un peu bavarde — `loop.invitation:{org}:{loop}:{user}` fait
     * deja 126 caracteres — passerait en local et casserait en CI. On ne
     * decouvre pas un depassement par le moteur.
     */
    /**
     * La seule colonne metier encore mutable — donc la seule a revalider.
     *
     * `assertCollapseKey` ne valait qu'a la creation. Or `collapse_key` est
     * volontairement hors de `IMMUTABLE`, et c'est precisement la colonne qui
     * porte un contrat de longueur : un `update()` la faisait deborder en
     * silence sur SQLite et lever `22001` sur PostgreSQL.
     */
    public static function assertCollapseKeyValue(mixed $value): void
    {
        self::assertCollapseKey($value);
    }

    private static function assertCollapseKey(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Notification collapse_key must be a string or null.');
        }

        if (mb_strlen($value) > self::MAX_COLLAPSE_KEY) {
            throw new InvalidArgumentException(
                'Notification collapse_key exceeds '.self::MAX_COLLAPSE_KEY.' characters.'
            );
        }
    }

    /**
     * La frontiere de tenant, verifiee sur la DONNEE — pour les DEUX personnes.
     *
     * Le destinataire, evidemment. Mais l'acteur aussi : une ligne d'Organization
     * A ne doit pas porter l'identifiant d'un membre de B, sans quoi un rendu qui
     * resout l'acteur divulguerait un nom d'un autre tenant. La garde ne valait
     * que pour une des deux references — elle vaut maintenant pour les deux.
     *
     * Un acteur exterieur au tenant n'est pas « ignore » : il est refuse. Une
     * notification peut parfaitement n'avoir aucun acteur (`null`), et c'est la
     * forme a utiliser quand le geste ne vient pas d'un membre.
     *
     * La lecture passe par le query builder et non par `User`, deliberement :
     * une garde d'integrite ne doit dependre d'aucun scope global, d'aucun
     * `app('current_organization')`, d'aucune session. Elle doit rendre le meme
     * verdict dans une requete HTTP, dans un job de file d'attente et dans une
     * commande artisan.
     *
     * Une personne introuvable est refusee : l'absence de preuve d'appartenance
     * n'est pas une preuve d'appartenance.
     */
    private static function assertBelongsToOrganization(mixed $userId, mixed $organizationId, string $role, bool $required): void
    {
        if ($userId === null) {
            if ($required) {
                throw new InvalidArgumentException("Notification requires a {$role}.");
            }

            return;
        }

        if (! is_string($userId) || ! is_string($organizationId) || $organizationId === '') {
            throw new InvalidArgumentException(
                "Notification requires both a {$role} and an Organization."
            );
        }

        $membership = DB::table('users')->where('id', $userId)->value('organization_id');

        if ($membership === null || $membership !== $organizationId) {
            throw new InvalidArgumentException(
                "Notification {$role} does not belong to the target Organization."
            );
        }
    }
}
