<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Support\Notifications\NotificationWebPushDeliverer;
use App\Support\Ops\ExternalEgressGuard;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1740 — diagnostiquer le Push, et s'envoyer une notification de test.
 *
 * ## Pourquoi « a SOI » et pas « a qui je veux »
 *
 * C'est la garde demandee par MASTER le 01/11 : « aucun envoi vers les
 * abonnements eventuellement copies de production ».
 *
 * La garde n'est pas une liste d'autorisation a tenir a jour — ce serait une
 * promesse a surveiller. Elle est STRUCTURELLE : cet outil n'envoie qu'aux
 * appareils de la personne qui clique. On ne peut donc tester que sur un
 * telephone qu'on tient dans la main, et jamais reveiller celui de quelqu'un
 * d'autre, meme par erreur, meme sur un jumeau de production peuple de vrais
 * comptes.
 *
 * Un abonnement ne peut d'ailleurs exister que si un navigateur a accorde une
 * permission SUR CET ENVIRONNEMENT : la table est nee avec T1739 et n'a jamais
 * figure dans un export de production. Toute ligne y est donc locale par
 * construction. La garde ci-dessus protege l'avenir, pas le present.
 *
 * ## Ce que le diagnostic dit, et pourquoi il le dit
 *
 * Un envoi qui ne part pas a trois causes possibles — configuration absente,
 * aucun appareil, ou sortie reseau refusee par `ExternalEgressGuard`. Les
 * trois produisent le meme silence. L'ecran les SEPARE, sans quoi la recette
 * se passerait a deviner laquelle des trois s'applique.
 */
class AdminTestNotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.outils.test-notification', $this->diagnostic($request));
    }

    /**
     * S'envoyer une notification de test, a SOI, sur ses propres appareils.
     */
    public function send(Request $request): RedirectResponse
    {
        $utilisateur = $request->user();

        $appareils = PushSubscription::query()
            ->where('user_id', $utilisateur->getKey())
            ->orderByDesc('last_seen_at')
            ->get();

        if ($appareils->isEmpty()) {
            return back()->with('push_test_resultat', __('admin.push_test_no_device'));
        }

        $deliverer = app(NotificationWebPushDeliverer::class);

        $resultats = $deliverer->envoyerUnTest($appareils, [
            'title' => __('admin.push_test_title'),
            'body' => __('admin.push_test_body'),
            'url' => route('notifications.index'),
            'tag' => 'bouclepro-test',
        ]);

        return back()->with('push_test_resultat', __('admin.push_test_done', [
            'ok' => $resultats['ok'],
            'revoques' => $resultats['revoque'],
            'echecs' => $resultats['echec'],
        ]))->with('push_test_detail', $resultats['motifs']);
    }

    /** @return array<string, mixed> */
    private function diagnostic(Request $request): array
    {
        $connexion = (string) config('database.default');
        $reglage = config("database.connections.{$connexion}");
        $base = is_array($reglage)
            ? (string) ((new ConfigurationUrlParser)->parseConfiguration($reglage)['database'] ?? '?')
            : '?';

        // Le garde d'egress s'arme sur la BASE visee, jamais sur `APP_ENV` :
        // c'est donc elle qu'il faut montrer, pas l'environnement.
        $sortiesBloquees = is_array($reglage) && ExternalEgressGuard::viseUnJumeau($reglage);

        return [
            'pushActif' => (bool) config('webpush.enabled'),
            'clePubliquePosee' => ((string) config('webpush.vapid.public_key')) !== '',
            'clePriveePosee' => ((string) config('webpush.vapid.private_key')) !== '',
            'sujet' => (string) config('webpush.vapid.subject'),
            'base' => $base,
            'sortiesBloquees' => $sortiesBloquees,
            'mesAppareils' => PushSubscription::query()
                ->where('user_id', $request->user()?->getKey())
                ->orderByDesc('last_seen_at')
                ->get(),
        ];
    }
}
