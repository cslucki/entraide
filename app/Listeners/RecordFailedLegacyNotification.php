<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationFailed;
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
 * ## Pourquoi PAS de `report()` ici
 *
 * Il avait ete envisage, puis mesure : `rescue($callback, false)` — la forme
 * utilisee par tous les appelants de ce flux — a pour signature
 * `rescue($callback, $rescue = null, $report = true)`. Le `false` est la VALEUR
 * DE REPLI, pas le drapeau de report. L'exception est donc **deja** reportee par
 * `rescue`, et celles qui remontent le sont par le gestionnaire de Laravel.
 * Ajouter un `report()` ici doublerait chaque trace sans rien apprendre.
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
        } catch (Throwable) {
            // Voir l'en-tete : la tenue de registre ne fait pas echouer ce qui a
            // deja eu lieu.
        }
    }

    /**
     * Le sujet, au mieux — et `null` plutot que rien du tout.
     *
     * `toMail()` est rappele ici comme le fait l'ecouteur de succes. Mais sur le
     * chemin d'ECHEC il a pu echouer lui-meme : si l'exception vient de la
     * construction du message, le rejouer relevera. La preuve que l'envoi a
     * echoue vaut plus que son sujet, donc on garde la ligne et on perd le
     * sujet.
     */
    private function sujet(NotificationFailed $event, User $destinataire): ?string
    {
        try {
            return $event->notification->toMail($destinataire)->subject;
        } catch (Throwable) {
            return null;
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
