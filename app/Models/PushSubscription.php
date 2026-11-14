<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * TASK-1739 — un abonnement Web Push, c'est-a-dire UN APPAREIL.
 *
 * ## Pourquoi `endpoint_hash` est calcule ICI et nulle part ailleurs
 *
 * L'empreinte porte la contrainte d'unicite qui garantit l'invariant central
 * du CDC 5.2 : « un endpoint n'est attribue qu'a UN SEUL `user_id` a un
 * instant donne ». Si un appelant pouvait l'ecrire lui-meme, il pourrait la
 * desynchroniser de l'`endpoint` — deux lignes pour le meme appareil, et au
 * chantier d'envoi un Push du compte frere precedent sur l'ecran verrouille.
 *
 * Elle est donc DERIVEE a l'ecriture, hors `$fillable`, et recalculee a chaque
 * fois que `endpoint` bouge. Le seul moyen de la changer est de changer
 * l'endpoint.
 *
 * ## `user_id` hors `$fillable`
 *
 * Meme doctrine que `MemberNotificationPreference` : le proprietaire vient de
 * la session, jamais de la requete. Un `create($request->validated())` un peu
 * rapide laisserait quelqu'un abonner l'appareil de quelqu'un d'autre.
 *
 * ## Ce que ce modele ne fait PAS
 *
 * Il n'envoie rien, ne chiffre rien, ne connait aucun canal. `web_push`
 * n'entrera au `NotificationCatalogue` qu'au chantier d'envoi, apres
 * correction du defaut de `NotificationDeliveryPlanner::plan()`.
 *
 * @property string $id
 * @property string $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $p256dh
 * @property string $auth
 * @property string|null $device_label
 * @property string|null $created_env
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
class PushSubscription extends Model
{
    use HasFactory, HasUuids;

    /**
     * `user_id` et `endpoint_hash` sont ABSENTS a dessein : le premier vient
     * de la session, le second est derive de `endpoint`.
     */
    protected $fillable = [
        'endpoint',
        'p256dh',
        'auth',
        'device_label',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /**
     * L'empreinte qui porte l'unicite.
     *
     * Publique parce que la REVENDICATION d'un endpoint doit pouvoir la
     * calculer avant d'ecrire, pour viser la bonne ligne en une seule
     * operation atomique.
     */
    /**
     * L'empreinte de la clef VAPID en vigueur, ou `null` si aucune n'est posee.
     *
     * TASK-1740 — un abonnement porte A VIE la clef avec laquelle le navigateur
     * l'a cree. Si celle du serveur change, l'abonnement survit, le serveur le
     * croit valide, le fournisseur repond `201` et le navigateur jette le
     * message EN SILENCE.
     */
    public static function empreinteCleVapid(?string $cle = null): ?string
    {
        $cle = trim((string) ($cle ?? config('webpush.vapid.public_key')));

        return $cle === '' ? null : hash('sha256', $cle);
    }

    /**
     * Cet abonnement a-t-il ete cree avec une AUTRE clef que celle en vigueur ?
     *
     * Un `null` stocke ne vaut PAS « divergent » : les lignes anterieures a
     * cette colonne ont ete creees avec une clef dont rien n'indique qu'elle
     * differe. On ne traite comme divergent que ce qu'on a MESURE divergent —
     * refuser l'inconnu casserait une installation qui fonctionne.
     */
    public function cleVapidDiverge(): bool
    {
        $enVigueur = self::empreinteCleVapid();

        return $this->vapid_key_hash !== null
            && $enVigueur !== null
            && $this->vapid_key_hash !== $enVigueur;
    }

    /** TASK-1820 — « empreinte stockee concordante » et « reception reellement demontree » sont DEUX choses distinctes. Celui-ci ne dit QUE la premiere. */
    public const VAPID_CONCORDANTE = 'concordante';

    public const VAPID_DIVERGENTE = 'divergente';

    public const VAPID_INCONNUE = 'inconnue';

    /**
     * Les TROIS etats de la clef VAPID de cet abonnement, pour un ecran qui
     * doit les distinguer plutot que resumer en « OK »/« KO ».
     *
     * `cleVapidDiverge()` ne renseigne qu'un `bool` : vrai seulement quand une
     * divergence est MESUREE. Cette methode nomme aussi les deux etats
     * complementaires — concordance mesuree, et absence de mesure — qu'un
     * simple `bool` confondrait dans un unique `false`. Elle ne mesure rien de
     * nouveau : elle NOMME un etat deja entierement determine par
     * `vapid_key_hash` et `empreinteCleVapid()`.
     *
     * Aucun des trois etats ne constitue une preuve de reception : une
     * concordance stockee dit seulement que le fournisseur ACCEPTERAIT
     * d'envoyer avec cette clef — jamais que l'appareil a reellement recu
     * quoi que ce soit (MASTER, T1820 — un `2xx` fournisseur n'est jamais une
     * preuve Android).
     */
    public function etatCleVapid(): string
    {
        if ($this->cleVapidDiverge()) {
            return self::VAPID_DIVERGENTE;
        }

        $enVigueur = self::empreinteCleVapid();

        if ($this->vapid_key_hash === null || $enVigueur === null) {
            return self::VAPID_INCONNUE;
        }

        return self::VAPID_CONCORDANTE;
    }

    /**
     * TASK-1821 — un quatrieme etat : NON COMPARABLE.
     *
     * `etatCleVapid()` compare `vapid_key_hash` a la clef du serveur COURANT.
     * Cette comparaison n'a de sens que si les deux viennent du MEME
     * environnement : chaque environnement a sa propre paire VAPID (regle
     * posee dans `config/webpush.php`). Un abonnement stampe en `production`,
     * relu depuis une base locale, sera donc TOUJOURS « divergent » — ce qui
     * ne dit rien de son etat reel en production.
     *
     * C'est exactement le faux signal lu sur la console le 14/11 : cinq
     * lignes `production` affichees DIVERGENTE dans un environnement local.
     * Elles ne sont pas defectueuses ; elles sont INCOMPARABLES d'ici.
     */
    public const VAPID_NON_COMPARABLE = 'non_comparable';

    /**
     * L'etat de la clef VAPID, replace dans son ENVIRONNEMENT d'origine.
     *
     * Rend `VAPID_NON_COMPARABLE` des que la ligne a ete stampee ailleurs
     * qu'ici — y compris quand `created_env` est `null` (origine inconnue :
     * on ne sait pas contre quelle clef comparer, donc on ne conclut pas).
     * Sinon, delegue a `etatCleVapid()`, qui reste la seule autorite sur la
     * comparaison elle-meme.
     */
    public function etatCleVapidContextualise(): string
    {
        if ((string) $this->created_env !== (string) config('app.env')) {
            return self::VAPID_NON_COMPARABLE;
        }

        return $this->etatCleVapid();
    }

    /**
     * TASK-1821 — la condition d'ELIGIBILITE VAPID, en UN SEUL exemplaire.
     *
     * Extraite telle quelle de `NotificationWebPushDeliverer::livrer()`, qui
     * l'employait en ligne. Elle est desormais appelee par le livreur ET par
     * la console d'administration : un ecran qui annoncerait « eligible » ce
     * que l'envoi ecarterait serait precisement le genre de mensonge que
     * cette TASK existe pour supprimer. Une regle, un endroit.
     *
     * Un `vapid_key_hash` a `null` reste ELIGIBLE : il signifie « cree avant
     * que cette colonne existe », pas « suspect » (T1740).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<PushSubscription>  $requete
     */
    public function scopeCleVapidUtilisable($requete): void
    {
        $requete->where(function ($q): void {
            $q->whereNull('vapid_key_hash')
                ->orWhere('vapid_key_hash', self::empreinteCleVapid());
        });
    }

    public static function empreinteDe(string $endpoint): string
    {
        return hash('sha256', trim($endpoint));
    }

    protected static function booted(): void
    {
        // `saving` et non `creating` : l'empreinte doit suivre l'endpoint a la
        // creation ET a la mise a jour. Sans cela, un changement d'endpoint
        // laisserait l'empreinte de l'ancien et la contrainte d'unicite
        // defendrait le mauvais appareil.
        static::saving(function (self $abonnement): void {
            $endpoint = trim((string) $abonnement->endpoint);

            if ($endpoint === '') {
                throw new RuntimeException('A push subscription cannot have an empty endpoint.');
            }

            $abonnement->endpoint = $endpoint;
            $abonnement->endpoint_hash = self::empreinteDe($endpoint);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
