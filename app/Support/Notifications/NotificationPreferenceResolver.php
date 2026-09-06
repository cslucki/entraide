<?php

namespace App\Support\Notifications;

use App\Models\MemberNotificationPreference;
use App\Models\User;

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
     */
    public function allows(User $user, string $notificationKey, string $channel): bool
    {
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

        if (! NotificationCatalogue::channelIsConfigurable($notificationKey, $channel)) {
            return $defaut;
        }

        $ecart = MemberNotificationPreference::query()
            ->forOwner((string) $user->id)
            ->where('notification_key', $notificationKey)
            ->where('channel', $channel)
            ->first();

        return $ecart === null ? $defaut : (bool) $ecart->enabled;
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
