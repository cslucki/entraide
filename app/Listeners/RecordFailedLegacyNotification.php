<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TASK-1384 — un email historique qui echoue laisse une preuve.
 *
 * ## Le constat qui a ouvert cette tranche
 *
 * Mesure du 2026-09-03, sur une vraie `Notification` Laravel avec un transport
 * sabote : `NotificationFailed` etait emis, `NotificationSent` ne l'etait pas,
 * et **rien n'etait ecrit**. L'echec disparaissait — ni dans `email_logs`, ni
 * dans l'historique detaille, ni dans le cockpit de supervision.
 *
 * TASK-1383 avait rendu visibles les envois historiques REUSSIS. Leurs echecs,
 * eux, n'existaient nulle part. Un moteur d'email dont les pannes ne laissent
 * aucune trace ne se supervise pas : il se devine.
 *
 * ## Ce n'est PAS un pas vers la fusion des deux moteurs
 *
 * Cette ligne ne porte deliberement **aucun `notification_id`**, et on n'en
 * invente pas : elle ne vient pas du pipeline `MemberNotification`, elle vient
 * d'une `Notification` Laravel. Le cockpit continue donc de la compter hors
 * pipeline, exactement comme les succes historiques. La distinction posee en
 * T1383 est preservee, pas erodee.
 *
 * ## Trois filtres, et ils sont les MEMES que ceux du succes
 *
 * L'ecouteur de `NotificationSent` vit en clair dans `AppServiceProvider`. Les
 * deux ecrivent dans la MEME table : s'ils divergeaient sur ce qu'ils acceptent,
 * l'un tracerait des envois que l'autre ignore, et le total cesserait d'avoir un
 * sens. Les trois conditions sont donc reproduites a l'identique — canal `mail`,
 * notification de l'application, destinataire `User` — et mesurees ici comme
 * la-bas.
 *
 * ## `error_message` porte un CODE, jamais le message brut
 *
 * Un message de `TransportException` ressemble a « Connection could not be
 * established with host "smtp.exemple:587" : authentication failed for user
 * ... ». Le persister exposerait l'hote et l'identifiant dans l'historique
 * detaille de l'administration — le defaut exact corrige en T1376, ou un bloc
 * publiait l'hote SMTP de production.
 *
 * La classe de l'exception, elle, est stable, diagnostique et n'expose rien.
 *
 * ## Pourquoi PAS de `report()` sur l'exception de la NOTIFICATION
 *
 * Parce que les appelants s'en chargent deja, chacun avec son contexte metier.
 * La forme employee est `rescue($callback, $closure, false)` — TROIS arguments :
 * `TransactionController` (quatre sites), `MessageThread` et `CheckAiBudgets`
 * y journalisent eux-memes via leur closure de repli, et le troisieme argument
 * `false` DESACTIVE explicitement le report automatique. Seul
 * `RegisteredUserController` utilise la forme a un argument, donc le report par
 * defaut.
 *
 * Rapporter ici en plus dupliquerait ce que le producteur dit deja mieux : lui
 * sait de quel evenement metier il s'agit.
 *
 * ## Mais l'echec de CETTE ecriture, lui, est journalise
 *
 * C'est une exception differente, et elle n'escalade nulle part : le `catch`
 * ci-dessous la retient, aucun `rescue` d'appelant ne la voit. La laisser
 * disparaitre reproduirait exactement le defaut que cette tranche corrige — un
 * traceur dont les pannes ne laissent aucune trace — applique au traceur
 * lui-meme. Le silence a d'ailleurs deja masque un vrai defaut pendant
 * l'ecriture de cette tranche.
 *
 * On journalise donc un CODE, jamais un message : la meme discipline que pour
 * `error_message`.
 *
 * ## Il ne casse jamais la requete
 *
 * Un ecouteur de journalisation qui leve transformerait un email rate en erreur
 * 500 sur une action metier qui, elle, a reussi. Meme doctrine qu'en T1377 :
 * une fois le fait acquis, la tenue de registre n'a plus le droit de changer
 * l'issue.
 */
class RecordFailedLegacyNotification
{
    /**
     * Borne defensive du code d'erreur.
     *
     * La colonne est un `text`, donc rien ne deborderait aujourd'hui. La borne
     * protege d'un nom de classe pathologique — une classe anonyme rend un nom
     * qui contient le chemin du fichier et un octet nul.
     */
    private const CODE_MAX = 120;

    /**
     * Le sujet quand il est INDISPONIBLE — jamais `null`.
     *
     * `email_logs.subject` est declaree `string('subject')` SANS `nullable()` :
     * la colonne est NOT NULL sur les deux moteurs. Un `null` ferait donc
     * echouer l'insertion, l'echec serait avale par le `catch` ci-dessous, et la
     * tranche produirait exactement ce qu'elle existe pour supprimer : rien.
     *
     * Et ce n'est pas un cas theorique : c'est LA famille de pannes ou le sujet
     * manque, celle ou l'echec vient de la CONSTRUCTION du message — gabarit
     * casse, cle de traduction absente, relation supprimee. Precisement celle
     * dont on veut une preuve.
     *
     * Un marqueur stable, en clair, sans donnee. Meme idiome que le
     * `[action-link-redacted]` du livreur du pipeline (T1377).
     */
    private const SUJET_INDISPONIBLE = '[subject-unavailable]';

    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        if (! str_starts_with($event->notification::class, 'App\\Notifications\\')) {
            return;
        }

        $destinataire = $event->notifiable;

        if (! $destinataire instanceof User) {
            return;
        }

        try {
            EmailLog::create([
                'template_id' => null,
                'user_id' => $destinataire->id,
                // Pris sur le DESTINATAIRE, jamais sur un contexte ambiant :
                // cet ecouteur peut s'executer sous worker, ou aucune
                // Organization courante n'est resolue.
                'organization_id' => $destinataire->organization_id,
                'to_email' => $destinataire->email,
                'subject' => $this->sujet($event, $destinataire),
                'status' => EmailLog::STATUS_FAILED,
                'error_message' => $this->code($event),
                'data' => [
                    'source' => class_basename($event->notification),
                ],
            ]);
        } catch (Throwable $echec) {
            // Voir l'en-tete : la tenue de registre ne fait pas echouer ce qui a
            // deja eu lieu. Mais elle ne disparait pas en silence pour autant.
            Log::warning('TASK-1384 preuve d\'echec d\'email historique non enregistree', [
                'raison' => mb_substr(class_basename($echec), 0, self::CODE_MAX),
                'notification' => class_basename($event->notification),
            ]);
        }
    }

    /**
     * Le sujet, au mieux — et un marqueur plutot que `null`.
     *
     * `toMail()` est rappele ici comme le fait l'ecouteur de succes. Mais sur le
     * chemin d'ECHEC il a pu echouer lui-meme : si l'exception vient de la
     * construction du message, le rejouer relevera.
     *
     * La preuve que l'envoi a echoue vaut plus que son sujet — d'ou le repli.
     * Il ne peut PAS etre `null` : la colonne est NOT NULL, voir
     * `SUJET_INDISPONIBLE`.
     */
    private function sujet(NotificationFailed $event, User $destinataire): string
    {
        try {
            return $event->notification->toMail($destinataire)->subject ?? self::SUJET_INDISPONIBLE;
        } catch (Throwable) {
            return self::SUJET_INDISPONIBLE;
        }
    }

    /**
     * Un code stable, derive de la CLASSE de l'exception.
     *
     * Jamais `getMessage()` : voir l'en-tete de cette classe.
     */
    private function code(NotificationFailed $event): string
    {
        $exception = $event->data['exception'] ?? null;

        if (! $exception instanceof Throwable) {
            // L'evenement peut etre emis par un canal qui ne fournit pas
            // d'exception. On le dit, plutot que d'ecrire une chaine vide qui se
            // lirait comme « pas d'erreur ».
            return 'unknown_failure';
        }

        return mb_substr(class_basename($exception), 0, self::CODE_MAX);
    }
}
