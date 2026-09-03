<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Ops\NotificationCockpitDiagnostics;
use Illuminate\View\View;

/**
 * TASK-1380 — l'ecran de supervision des notifications.
 *
 * ## Ce qu'il repond
 *
 * « Le systeme de notifications fonctionne-t-il, et sinon ou est-ce coince ? »
 * Rien d'autre. Il ne sert pas a lire les notifications de quelqu'un, ni a en
 * renvoyer une.
 *
 * ## Supervision n'est pas lecture
 *
 * Aucun destinataire, aucun acteur, aucune adresse, aucun corps de message,
 * aucun identifiant d'objet metier ne traverse cet ecran. Il COMPTE. Meme
 * doctrine que le cockpit IA plateforme (T1223).
 *
 * ## Portee PLATEFORME, et il faut le dire
 *
 * Cet ecran voit toutes les Organizations. Ce n'est pas un contournement :
 * `MemberNotification` n'a **deliberement pas** de portee globale — elle
 * retomberait sur `0 = 1` sous worker, faute de contexte de requete, et viderait
 * silencieusement la table. Ecrire `withoutGlobalScopes()` ici serait un no-op
 * trompeur, suggerant une protection qui n'existe pas sur ce modele.
 *
 * La garde reelle est le groupe de routes `['auth', 'admin']` : `is_admin` est
 * un attribut de PLATEFORME, pas une appartenance a une Organization.
 *
 * ## Aucune logique ici
 *
 * Le controleur demande, le service mesure, la vue affiche. Meme forme qu'en
 * T1376 : la doctrine de ce qui ne doit pas sortir vit dans le service, a un
 * seul endroit.
 */
class AdminNotificationCockpitController extends Controller
{
    public function index(NotificationCockpitDiagnostics $diagnostics): View
    {
        return view('admin.notifications-cockpit.index', $diagnostics->overview());
    }
}
