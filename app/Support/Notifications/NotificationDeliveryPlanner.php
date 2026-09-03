<?php

namespace App\Support\Notifications;

use App\Jobs\SendNotificationEmail;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1377 — decider ce qu'il y a a livrer, et le mettre en file.
 *
 * ## Appele UNIQUEMENT depuis la creation reelle
 *
 * `NotificationEmitter::emit()` a trois issues : une creation, et deux
 * rattrapages qui rendent une ligne DEJA existante. Seule la premiere doit
 * mettre quoi que ce soit en file — sinon un producteur qui rejoue un fait
 * acquis renverrait un email a chaque rejeu.
 *
 * La garantie est une garantie de CHEMIN : ce planificateur n'est appele que
 * dans la branche de creation. **`wasRecentlyCreated` n'est pas utilise**, et ce
 * n'est pas un detail de style : ce drapeau appartient a l'instance, survit a
 * des manipulations qui n'ont rien a voir, et T1374 a deja montre qu'un
 * `fresh()` le perd. Un drapeau d'ETAT ne peut pas prouver un CHEMIN.
 *
 * ## Seuls les canaux ASYNCHRONES produisent une ligne
 *
 * `in_app` est livre par l'existence meme de la `MemberNotification` : le badge
 * et le Centre la lisent directement. Lui fabriquer une ligne de livraison
 * ajouterait une seconde verite sur un fait deja etabli, sans que rien ne la
 * consomme. Cette table decrit le travail qui reste A FAIRE.
 *
 * ## Le catalogue reste seul juge
 *
 * Aucune cle n'autorise EMAIL aujourd'hui : en V1-A, ce planificateur ne cree
 * donc rien en production. C'est le comportement attendu de la tranche, pas une
 * mecanique morte — P6 activera EMAIL sur `loop.invitation` et ce code
 * fonctionnera sans changer.
 */
/*
 * Non `final` : cette classe expose deliberement `canauxALivrer()` en
 * `protected`, et une classe qui offre un point d'extension ne peut pas
 * l'interdire. Voir le docblock de cette methode pour la raison.
 */
class NotificationDeliveryPlanner
{
    /**
     * Les canaux qui exigent un travail differe.
     *
     * @var list<string>
     */
    private const CANAUX_ASYNCHRONES = [NotificationCatalogue::CHANNEL_EMAIL];

    /**
     * Planifie les livraisons d'une notification FRAICHEMENT creee.
     *
     * @return list<MemberNotificationDelivery> les livraisons reellement creees
     */
    public function plan(MemberNotification $notification): array
    {
        $creees = [];

        foreach ($this->canauxALivrer($notification) as $canal) {
            $livraison = $this->creerLivraison((string) $notification->id, $canal);

            if ($livraison === null) {
                // Une livraison existait deja pour ce couple. C'est le cas d'un
                // rejeu, et la contrainte vient de le trancher : ne RIEN mettre
                // en file. Remettre un job ici reintroduirait exactement le
                // double envoi que la contrainte empeche.
                continue;
            }

            $creees[] = $livraison;

            // `afterCommit` est porte par le job : voir SendNotificationEmail.
            // Sans cela, un worker pourrait lire la livraison avant que la
            // transaction du producteur ait commit — et ne rien trouver.
            SendNotificationEmail::dispatch((string) $notification->id);
        }

        return $creees;
    }

    /**
     * Les canaux a livrer pour cette notification.
     *
     * Le catalogue reste seul juge : un canal asynchrone qu'il n'autorise pas ne
     * produit rien. En V1-A aucune cle n'autorise EMAIL, donc cette methode rend
     * un tableau vide en production — comportement attendu de la tranche.
     *
     * `protected` a dessein : la logique de rejeu situee EN DESSOUS ne serait
     * autrement jamais atteignable par un test, puisque ce filtre s'arrete
     * avant. Une garde qu'on ne peut pas franchir rend le code qu'elle protege
     * non mesurable — et un test qui ne peut pas atteindre son sujet passe pour
     * la mauvaise raison. Le filtre lui-meme est mesure separement.
     *
     * @return list<string>
     */
    protected function canauxALivrer(MemberNotification $notification): array
    {
        return array_values(array_filter(
            self::CANAUX_ASYNCHRONES,
            fn (string $canal) => NotificationCatalogue::allowsChannel((string) $notification->notification_key, $canal)
        ));
    }

    /**
     * Cree la ligne, ou rend `null` si elle existait deja.
     *
     * L'unicite est tranchee par la CONTRAINTE, pas par un `SELECT` prealable
     * qui laisserait une fenetre entre la lecture et l'ecriture.
     *
     * L'insertion vit dans un point de sauvegarde : sur PostgreSQL, une
     * violation de contrainte ABANDONNE la transaction courante, et le
     * producteur — qui nous appelle depuis la sienne — se retrouverait avec une
     * transaction morte en 25P02. SQLite, lui, resterait vert. Meme piege qu'en
     * T1372, meme parade.
     */
    private function creerLivraison(string $notificationId, string $canal): ?MemberNotificationDelivery
    {
        try {
            return DB::transaction(fn () => MemberNotificationDelivery::create([
                'notification_id' => $notificationId,
                'channel' => $canal,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
