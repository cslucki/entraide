<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Verified;

/**
 * TASK-1694 bug I — l'annonce des points part APRES la confirmation d'adresse.
 *
 * ## Ce qui change, et pourquoi
 *
 * L'email partait a l'INSCRIPTION. Cyril : « avant, "welcome" etait utilise
 * directement car pour les tests je ne voulais pas de confirmation, et donc que
 * le compte soit cree automatiquement. Ce qui n'est donc plus le cas
 * desormais. » La verification etant redevenue obligatoire (bug C), annoncer
 * 100 points a quelqu'un qui ne peut pas encore entrer n'avait plus de sens.
 *
 * ## Ce qui NE change PAS : le moment du CREDIT
 *
 * Les 100 points sont credites a l'inscription (`RegisteredUserController`,
 * `points_balance` et une ligne de `PointLedger`). Seule l'ANNONCE se deplace.
 *
 * Les deplacer aussi serait un autre geste, avec ses propres consequences — le
 * grand livre des points, les rapports, les comptes deja credites et non
 * verifies. Signale plutot que fait en silence.
 *
 * ## L'envoi ne peut pas faire echouer la verification
 *
 * `rescue()`, comme a l'inscription (T1043) : une panne de transport mail ne
 * doit pas transformer une confirmation reussie en erreur. La personne est
 * verifiee ; l'email est un accessoire.
 *
 * ## Decouvert automatiquement
 *
 * Laravel abonne les ecouteurs de `app/Listeners` par le TYPE de leur argument.
 * Une ligne `Event::listen()` de plus l'abonnerait une SECONDE fois — la note
 * de `AppServiceProvider` le dit, mesure faite a l'epoque : deux lignes ecrites
 * pour un seul evenement.
 */
class SendPointsGrantedEmailOnVerification
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        // TASK-1714 (demande Cyril, 27/10) — quand l'organisation exige une
        // validation, l'email de bienvenue part a L'APPROBATION, pas ici.
        //
        // Annoncer « bienvenue, voici vos 100 points » a quelqu'un qui ne peut
        // pas encore entrer repeterait exactement l'erreur que TASK-1694 bug I
        // avait corrigee en deplacant cet email de l'inscription vers la
        // verification. Une adresse confirmee n'est pas un acces ouvert.
        //
        // La regle est SYMETRIQUE : ce declencheur-ci n'envoie que si
        // l'approbation est deja acquise, et celui de l'approbation
        // (`AdminController::approveUserAccount`) n'envoie que si l'adresse est
        // deja verifiee. L'email part donc exactement UNE fois, au moment du
        // SECOND evenement — quel que soit leur ordre, et sans drapeau a
        // maintenir.
        if ($user->attendApprobation()) {
            return;
        }

        rescue(fn () => $user->notify(new WelcomeNotification));
    }
}
