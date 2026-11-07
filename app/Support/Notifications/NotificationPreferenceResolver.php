<?php

namespace App\Support\Notifications;

use App\Models\LoopMember;
use App\Models\LoopNotificationPreference;
use App\Models\MemberNotificationPreference;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;

/**
 * TASK-1375 — l'autorite qui dit si un canal est actif pour quelqu'un.
 *
 * ## La regle, en une phrase
 *
 * Le catalogue decide ; une preference stockee ne peut que **deplacer un defaut
 * qui accepte de l'etre**.
 *
 * ```
 * canal absent du catalogue        -> false        (fail-closed)
 * canal NON configurable           -> le defaut, TOUJOURS
 * canal configurable, sans ligne   -> le defaut
 * canal configurable, avec ligne   -> la ligne
 * ```
 *
 * ## Pourquoi la ligne 2 est la raison d'etre de cette classe
 *
 * Le CDC ne laisse aucune ambiguite :
 *
 * > Pour `configurable: false`, toute preference stockee contradictoire est
 * > IGNOREE par le resolver : la securite ne depend pas seulement de l'absence
 * > de toggle UI.
 *
 * `NotificationPreferenceInvariants` refuse deja d'ECRIRE un tel ecart. Mais
 * une garde d'ecriture ne protege que le chemin applicatif : elle ne dit rien
 * d'une ligne arrivee par un import, une migration, une base heritee, ou une
 * cle devenue obligatoire APRES que des membres l'aient reglee.
 *
 * C'est ce dernier cas qui rend la garde de lecture indispensable, et il n'a
 * rien de theorique : le jour ou un canal passe de configurable a obligatoire,
 * toutes les lignes existantes deviennent contradictoires d'un coup. Elles
 * doivent cesser d'avoir un effet **le jour meme**, sans migration de donnees.
 *
 * ## Aucune ecriture ici
 *
 * Ce resolver lit. Il ne cree pas la ligne manquante « pour normaliser », ce qui
 * figerait le defaut au premier appel et ferait diverger les membres selon la
 * date a laquelle ils ont ete lus pour la premiere fois.
 */
class NotificationPreferenceResolver
{
    /**
     * Ce canal doit-il etre delivre a cette personne ?
     *
     * TASK-1775 — `$loopId`, OPTIONNEL et retro-compatible : chaque appelant
     * historique continue de rendre exactement ce qu'il rendait avant cette
     * TASK. Quand il est fourni, un reglage PAR BOUCLE — s'il existe — prend
     * le pas sur l'ecart global historique : c'est la couche la PLUS
     * SPECIFIQUE, elle gouverne en premier, et un reglage global deja posé
     * continue de s'appliquer a toute Boucle que la personne n'a pas encore
     * reglee individuellement — rien n'est perdu en silence.
     *
     * La garde porte sur la CLE, jamais sur le seul fait qu'un `loopId` ait
     * ete fourni : `loop.member_added` partage le meme `object_type`
     * (`OBJECT_LOOP`) que `loop.help_request.created` et designe pourtant un
     * fait PERSONNEL (« on vous a ajoute ») — un maitre de Boucle coupe ne
     * doit jamais le faire taire. Seule la cle collective explicite se laisse
     * gouverner par ce reglage.
     */
    public function allows(User $user, string $notificationKey, string $channel, ?string $loopId = null): bool
    {
        $userId = (string) $user->id;

        return $this->decider(
            $notificationKey,
            $channel,
            $loopId,
            // Chargement UNITAIRE : les deux lectures de Boucle ne partent que
            // si la decision en a besoin — exactement comme avant T1778.
            fn (): array => $this->lireLeReglageDeBoucle($userId, $loopId),
            fn (): ?MemberNotificationPreference => MemberNotificationPreference::query()
                ->forOwner($userId)
                ->where('notification_key', $notificationKey)
                ->where('channel', $channel)
                ->first(),
        );
    }

    /**
     * TASK-1778 — la MEME decision, pour N personnes, en 3 requetes au total.
     *
     * ## Le defaut que cela corrige
     *
     * `allows()` coute jusqu'a 3 requetes par appel, et le fan-out d'une
     * demande d'aide l'appelle une fois par destinataire et par canal. Mesure
     * du banc T1778 avant cette methode : **8 requetes de preference par
     * destinataire**, soit 800 pour une Boucle de 100 membres — pour lire
     * trois tables dont le contenu utile tient en trois requetes.
     *
     * ## Une seule fonction de decision, deux strategies de chargement
     *
     * Ce qui rend cette methode sure n'est pas sa prudence, c'est qu'elle ne
     * REIMPLEMENTE rien : elle charge en masse, puis appelle `decider()`, le
     * meme arbitre que `allows()`. Une methode « rapide » qui aurait recopie
     * la cascade du catalogue aurait fini par diverger de la lente — et c'est
     * la lente qui garde les tests.
     *
     * ## Aucune memoire entre deux appels
     *
     * Rien n'est retenu d'un appel sur l'autre. Les livreurs doivent relire la
     * preference COURANTE au moment de livrer : un resolveur qui se souviendrait
     * de ce qu'il a lu pendant la planification rendrait un verdict perime et
     * casserait la garantie « la preference courante, pas celle de l'emission ».
     *
     * @param  iterable<User>  $users
     * @param  list<string>  $channels
     * @return array<string, array<string, bool>>  `[userId => [canal => autorise]]`
     */
    public function allowsMany(iterable $users, string $notificationKey, array $channels, ?string $loopId = null): array
    {
        /** @var Collection<int, User> $personnes */
        $personnes = $users instanceof Collection ? $users : collect($users);

        if ($personnes->isEmpty() || $channels === []) {
            return [];
        }

        $ids = $personnes->map(fn (User $u): string => (string) $u->id)->unique()->values()->all();

        $porteeBoucle = $loopId !== null
            && NotificationCatalogue::loopScopeFor($notificationKey, $loopId) !== null;

        // 1 — les appartenances ACTIVES a la Boucle, en une requete.
        $membresActifs = $porteeBoucle
            ? LoopMember::query()
                ->where('loop_id', $loopId)
                ->whereIn('user_id', $ids)
                ->where('status', 'active')
                ->pluck('user_id')
                ->map(fn ($id): string => (string) $id)
                ->all()
            : [];

        $membresActifs = array_fill_keys($membresActifs, true);

        // 2 — les reglages PAR BOUCLE, en une requete.
        $reglagesBoucle = $porteeBoucle
            ? LoopNotificationPreference::query()
                ->where('loop_id', $loopId)
                ->whereIn('user_id', $ids)
                ->get()
                ->keyBy(fn (LoopNotificationPreference $r): string => (string) $r->user_id)
            : collect();

        // 3 — les ecarts GLOBAUX, en une requete, et seulement pour les canaux
        // que le catalogue laisse reglables : pour un canal obligatoire, la
        // ligne serait lue puis ignoree.
        $canauxReglables = array_values(array_filter(
            $channels,
            fn (string $canal): bool => NotificationCatalogue::channelIsConfigurable($notificationKey, $canal),
        ));

        $ecarts = [];

        if ($canauxReglables !== []) {
            foreach (MemberNotificationPreference::query()
                ->whereIn('user_id', $ids)
                ->where('notification_key', $notificationKey)
                ->whereIn('channel', $canauxReglables)
                ->get() as $ecart) {
                $ecarts[(string) $ecart->user_id][(string) $ecart->channel] = $ecart;
            }
        }

        $decisions = [];

        foreach ($personnes as $personne) {
            $userId = (string) $personne->id;

            foreach ($channels as $canal) {
                $decisions[$userId][$canal] = $this->decider(
                    $notificationKey,
                    $canal,
                    $loopId,
                    fn (): array => [
                        isset($membresActifs[$userId]),
                        $reglagesBoucle->get($userId),
                    ],
                    fn (): ?MemberNotificationPreference => $ecarts[$userId][$canal] ?? null,
                );
            }
        }

        return $decisions;
    }

    /**
     * L'ARBITRE — la cascade du CDC, et rien d'autre.
     *
     * Il ne sait pas lire : ses deux fermetures le lui apportent. C'est ce qui
     * permet a `allows()` (une personne, lecture a la demande) et a
     * `allowsMany()` (N personnes, lecture en masse) de rendre exactement le
     * meme verdict sans partager une ligne de SQL.
     *
     * Les fermetures sont PARESSEUSES a dessein : en mode unitaire, un reglage
     * de Boucle qui tranche doit eviter la requete de l'ecart global, et un
     * canal hors portee de Boucle doit eviter les deux requetes de Boucle.
     *
     * @param  Closure(): array{0: bool, 1: ?LoopNotificationPreference}  $reglageDeBoucle
     * @param  Closure(): ?MemberNotificationPreference  $ecartGlobal
     */
    private function decider(
        string $notificationKey,
        string $channel,
        ?string $loopId,
        Closure $reglageDeBoucle,
        Closure $ecartGlobal,
    ): bool {
        // Ceinture et bretelles, assume comme tel : les accesseurs du catalogue
        // rendent deja `null` puis `false` pour un canal inconnu, donc retirer ce
        // retour anticipe ne change AUCUN comportement — un sabotage l'a
        // verifie. Il reste parce qu'il enonce l'intention en tete de methode et
        // protege d'un changement de valeur par defaut dans ces accesseurs.
        //
        // La propriete elle-meme, elle, est bien mesuree : deux tests couvrent
        // le canal inconnu et la cle inconnue.
        if (! NotificationCatalogue::allowsChannel($notificationKey, $channel)) {
            return false;
        }

        $defaut = (bool) NotificationCatalogue::channelDefault($notificationKey, $channel);

        // TASK-1775 (arbitrage Cyril, 07/11) — le maitre de Boucle est
        // verifie AVANT la garde de configurabilite du catalogue, et non
        // apres : l'in-app est redevenu OBLIGATOIRE au catalogue (plus
        // personne ne peut le couper globalement), mais le maitre de Boucle
        // doit continuer a pouvoir l'eteindre POUR CETTE BOUCLE precise —
        // c'est desormais le SEUL levier de calme qui reste. Le placer apres
        // le retour anticipe de la garde de configurabilite l'aurait rendu
        // inatteignable.
        //
        // TASK-1778 — la regle « quelle cle se laisse gouverner par une
        // Boucle » est passee au catalogue (`loopScopeFor`), ou elle n'est
        // plus ecrite qu'une fois pour ses quatre lecteurs.
        if ($loopId !== null && NotificationCatalogue::loopScopeFor($notificationKey, $loopId) !== null) {
            // Un reglage laisse par une appartenance qui n'est plus ACTIVE
            // reste en base (quitter une Boucle ne l'efface pas), mais cesse
            // de produire le moindre effet tant que la personne n'est pas
            // revenue : une preference oubliee ne doit jamais continuer a
            // gouverner une Boucle qu'on a quittee.
            [$estMembreActif, $reglageBoucle] = $reglageDeBoucle();

            if (! $estMembreActif) {
                $reglageBoucle = null;
            }

            if ($reglageBoucle !== null) {
                if (! $reglageBoucle->collective_enabled) {
                    return false;
                }

                if (NotificationCatalogue::channelIsConfigurable($notificationKey, $channel)) {
                    if ($channel === NotificationCatalogue::CHANNEL_EMAIL) {
                        return (bool) $reglageBoucle->email_enabled;
                    }

                    if ($channel === NotificationCatalogue::CHANNEL_WEB_PUSH) {
                        return (bool) $reglageBoucle->web_push_enabled;
                    }
                }

                // `CHANNEL_IN_APP`, obligatoire : la Boucle est active, et
                // l'in-app est livre par l'existence meme de la ligne —
                // aucune case dediee a lire, le defaut du catalogue (true)
                // gouverne (rejoint ci-dessous).
            }
        }

        if (! NotificationCatalogue::channelIsConfigurable($notificationKey, $channel)) {
            return $defaut;
        }

        $ecart = $ecartGlobal();

        return $ecart === null ? $defaut : (bool) $ecart->enabled;
    }

    /**
     * Le reglage de Boucle en mode unitaire, en UNE requete.
     *
     * ## Pourquoi une seule requete suffit
     *
     * `decider()` ne se sert de l'appartenance que pour une chose : annuler
     * le reglage de Boucle si la personne n'est plus membre ACTIF. Elle ne
     * decide de rien d'autre. « Le reglage, mais seulement si l'appartenance
     * est active » rend donc exactement le meme verdict que les deux lectures
     * separees — et c'est une propriete du code, pas une intuition : il n'y a
     * aucun chemin ou `$estMembreActif` est consulte sans `$reglageBoucle`.
     *
     * Le gain se paie sur le chemin le plus chaud : chaque livreur appelle
     * `allows()` une fois par notification, et le banc T1778 comptait 2
     * requetes par appel la ou une suffit.
     *
     * Le premier element rendu reste le verdict d'appartenance tel que
     * `decider()` l'attend : `true` des que la requete a pu rendre un
     * reglage, et sinon `false` — auquel cas le second est `null` et
     * l'appartenance ne gouverne plus rien.
     *
     * @return array{0: bool, 1: ?LoopNotificationPreference}
     */
    private function lireLeReglageDeBoucle(string $userId, ?string $loopId): array
    {
        $reglage = LoopNotificationPreference::query()
            ->forOwner($userId)
            ->where('loop_id', $loopId)
            ->whereExists(fn ($requete) => $requete
                ->selectRaw('1')
                ->from('loop_members')
                ->whereColumn('loop_members.loop_id', 'member_loop_notification_preferences.loop_id')
                ->whereColumn('loop_members.user_id', 'member_loop_notification_preferences.user_id')
                ->where('loop_members.status', 'active'))
            ->first();

        return [$reglage !== null, $reglage];
    }

    /**
     * L'etat de TOUS les canaux du catalogue pour cette personne.
     *
     * Ce que l'ecran de reglages affiche. Chaque entree dit si le canal est
     * actif et s'il peut etre change — un canal obligatoire se montre, mais sans
     * bouton, plutot que de disparaitre : le membre a le droit de savoir ce qui
     * lui sera envoye.
     *
     * @return array<string, array<string, array{enabled: bool, configurable: bool}>>
     */
    public function overview(User $user): array
    {
        $etat = [];

        foreach (NotificationCatalogue::keys() as $cle) {
            foreach (NotificationCatalogue::channelsFor($cle) as $canal) {
                $etat[$cle][$canal] = [
                    'enabled' => $this->allows($user, $cle, $canal),
                    'configurable' => NotificationCatalogue::channelIsConfigurable($cle, $canal),
                ];
            }
        }

        return $etat;
    }
}
