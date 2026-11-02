<?php

namespace App\Models;

use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use RuntimeException;

/**
 * TASK-1377 — l'etat courant d'une livraison.
 *
 * ## L'identite est FIGEE, et c'est structurel
 *
 * `notification_id` et `channel` forment la contrainte d'unicite. Les laisser
 * muter offrirait par `update()` ce que la contrainte refuse a l'insertion : il
 * suffirait de deplacer une livraison `sent` vers une autre notification pour
 * contourner l'unicite. Meme doctrine qu'en T1375 sur les preferences.
 *
 * ## `status` n'est PAS affectable en masse
 *
 * C'est la garde la plus importante de ce modele. Un `update($request->…)` un
 * peu rapide, ou un `fill()` sur des donnees venues d'ailleurs, pourrait sinon
 * faire passer une livraison a `sent` sans qu'aucun email ne parte. L'etat ne se
 * pose que par les methodes de transition ci-dessous.
 *
 * ## Les transitions sont appliquees par le modele, pas par convention
 *
 * `updating` refuse toute transition non permise. Une regle qui vit dans un
 * service se contourne en appelant le modele directement ; un hook, non. C'est
 * ce qui rend `sent` REELLEMENT irreversible.
 */
class MemberNotificationDelivery extends Model
{
    use HasUuids;

    /**
     * `status`, `attempts`, `claimed_at`, `sent_at` et `diagnostic` sont ABSENTS
     * a dessein — voir le docblock de la classe.
     */
    protected $fillable = [
        'notification_id',
        'channel',
    ];

    /** Les colonnes qui ne peuvent JAMAIS changer apres creation. */
    private const IDENTITE = ['notification_id', 'channel'];

    /**
     * TASK-1744 — l'UNIQUE porte par laquelle un etat terminal se rouvre.
     *
     * Transitoire, jamais persistee, remise a `false` dans un `finally`. Elle
     * n'est levee que par {@see rouvrirPourRejeu()}, et le hook `updating`
     * ne la consulte que pour un retour vers `pending`.
     */
    private bool $rejeuAdministratifEnCours = false;

    protected static function booted(): void
    {
        static::creating(function (self $livraison): void {
            $livraison->status ??= NotificationDeliveryStatus::PENDING;

            self::garantirCanal((string) $livraison->channel);

            if (! NotificationDeliveryStatus::existe((string) $livraison->status)) {
                throw new InvalidArgumentException(
                    "Unknown notification delivery status [{$livraison->status}]."
                );
            }
        });

        static::updating(function (self $livraison): void {
            $figees = array_intersect(array_keys($livraison->getDirty()), self::IDENTITE);

            if ($figees !== []) {
                throw new RuntimeException(
                    'A notification delivery identity is frozen: ['
                    .implode(', ', $figees).'] cannot change.'
                );
            }

            if (! $livraison->isDirty('status')) {
                return;
            }

            $depuis = (string) $livraison->getOriginal('status');
            $vers = (string) $livraison->status;

            // TASK-1744 — la reouverture ADMINISTRATIVE, et elle seule.
            //
            // Le message ci-dessous dit « a delivery is never replayed
            // AUTOMATICALLY », et c'est exactement la frontiere : aucun
            // worker, aucun producteur, aucun rejeu de job ne peut repasser
            // par ici. Seul `rouvrirPourRejeu()`, appele depuis un ecran
            // d'administration sur un compte de recette, leve le drapeau.
            //
            // Trois conditions cumulatives, parce qu'une seule se contourne :
            // le drapeau transitoire, un etat de depart TERMINAL, et une
            // arrivee sur `pending` — jamais sur `sent`, qui reste
            // irreversible dans tous les cas.
            if ($livraison->rejeuAdministratifEnCours
                && NotificationDeliveryStatus::estTerminal($depuis)
                && $vers === NotificationDeliveryStatus::PENDING) {
                return;
            }

            if (! NotificationDeliveryStatus::transitionPermise($depuis, $vers)) {
                throw new RuntimeException(
                    "Forbidden notification delivery transition [{$depuis}] -> [{$vers}]. "
                    .'Terminal states are final: a delivery is never replayed automatically.'
                );
            }
        });
    }

    /**
     * Rouvrir cette livraison pour un rejeu DECIDE PAR UN HUMAIN.
     *
     * TASK-1744, demande Cyril du 02/11 : l'administration doit pouvoir
     * renvoyer un Push dont on vient de corriger la cause — une preference
     * coupee, un appareil reabonne — sans fabriquer un evenement metier qui
     * n'a pas eu lieu.
     *
     * ## Pourquoi cette methode existe plutot qu'un assouplissement
     *
     * La machine d'etats de T1377 est volontairement sans retour : « rien ne
     * revient en arriere », pour qu'un rejeu ACCIDENTEL ne reprenne jamais
     * une livraison deja tranchee. Ajouter `terminal -> pending` a la table
     * des transitions aurait ouvert cette porte a tout le monde, worker
     * compris, et c'est precisement ce que la table existe pour interdire.
     *
     * Une methode nommee, au contraire, se cherche : `grep rouvrirPourRejeu`
     * rend la liste complete de ses appelants. Aujourd'hui il y en a un, et
     * il est derriere `['auth','admin']` plus la politique des comptes de
     * recette.
     *
     * `sent` reste atteignable comme etat de depart — on rejoue aussi une
     * livraison « reussie » dont le telephone n'a rien montre, c'est meme le
     * cas d'usage qui a motive l'outil. Ce qui reste interdit, c'est
     * d'ARRIVER sur `sent` autrement que par le livreur.
     */
    public function rouvrirPourRejeu(): void
    {
        $this->rejeuAdministratifEnCours = true;

        try {
            $this->forceFill([
                'status' => NotificationDeliveryStatus::PENDING,
                'diagnostic' => null,
                'sent_at' => null,
                'claimed_at' => null,
            ])->save();
        } finally {
            // Dans un `finally` : une exception de sauvegarde ne doit pas
            // laisser l'instance avec le drapeau leve, ou l'ecriture suivante
            // passerait pour un rejeu.
            $this->rejeuAdministratifEnCours = false;
        }
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(MemberNotification::class, 'notification_id');
    }

    /**
     * Le canal existe-t-il ?
     *
     * On refuse ce que le catalogue ne connait pas comme canal — sinon une
     * chaine libre creerait des livraisons qu'aucun worker ne ramassera jamais,
     * et qui resteraient `pending` sans que personne ne sache pourquoi.
     */
    private static function garantirCanal(string $channel): void
    {
        // TASK-1740 — `web_push` rejoint les deux autres.
        //
        // Cette liste est une SECONDE enumeration, non derivee du catalogue, et
        // c'est delibere : elle defend la table, pas le produit. Un canal que
        // le catalogue declarerait sans qu'aucun worker ne le ramasse
        // produirait des livraisons `pending` eternelles — le refus vient donc
        // d'ici, a l'insertion, et non d'une decouverte plus tard.
        //
        // Le prix est qu'elle doit etre tenue a jour en meme temps que
        // `NotificationCatalogue::CHANNEL_*`. Le test
        // `TASK1740WebPushChannelTest::test_tout_canal_du_catalogue_est_accepte_en_livraison`
        // mesure cet accord plutot que de compter sur la vigilance.
        $connus = [
            NotificationCatalogue::CHANNEL_IN_APP,
            NotificationCatalogue::CHANNEL_EMAIL,
            NotificationCatalogue::CHANNEL_WEB_PUSH,
        ];

        if (! in_array($channel, $connus, true)) {
            throw new InvalidArgumentException("Unknown notification delivery channel [{$channel}].");
        }
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', NotificationDeliveryStatus::PENDING);
    }
}
