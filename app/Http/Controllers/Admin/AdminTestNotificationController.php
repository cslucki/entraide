<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Notifications\NotificationWebPushDeliverer;
use App\Support\Ops\ExternalEgressGuard;
use App\Support\Push\PushTestPolicy;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * TASK-1740 — diagnostiquer le Push, choisir un destinataire et un appareil,
 * puis envoyer depuis l'administration.
 *
 * ## Ce que l'outil autorise, et ce qu'il ne peut pas faire
 *
 * Un administrateur choisit UN compte de recette et UN de ses appareils, puis
 * envoie. Il n'a pas besoin de se connecter sur le telephone avec son propre
 * compte — c'etait la limite de la premiere version, acceptable pour un
 * premier essai technique, pas pour l'outil definitif.
 *
 * Trois bornes, toutes portees par {@see PushTestPolicy} et non par des `if`
 * eparpilles ici :
 *
 * 1. **Les destinataires** sont les comptes de recette, reconnus par le TLD
 *    reserve `.test` (RFC 2606, non enregistrable, donc aucune adresse reelle
 *    n'en porte). Le jumeau contient de VRAIES adresses — 21 `gmail.com`
 *    mesures le 01/11 — qui ne peuvent donc jamais etre selectionnees.
 * 2. **Les appareils** sont ceux dont l'abonnement a ete CREE sur cet
 *    environnement (`created_env`). Un abonnement importe d'un dump de
 *    production ne passe pas.
 * 3. **Tout est revalide au POST**, jamais cru depuis le formulaire : un
 *    champ cache se falsifie, une politique relue ne se falsifie pas.
 *
 * ## Ce que l'ecran ne montre jamais
 *
 * Ni l'endpoint complet, ni aucune clef. Un endpoint EST un jeton de reveil :
 * qui le detient avec les clefs peut deposer une notification sur l'appareil.
 * L'ecran n'en rend que le fournisseur et une empreinte courte.
 *
 * ## Et ce qu'un succes signifie
 *
 * Un `201` du fournisseur dit qu'il a ACCEPTE le message, pas qu'Android l'a
 * affiche. Le diagnostic rend le code HTTP brut pour que la difference reste
 * visible.
 */
class AdminTestNotificationController extends Controller
{
    public function __construct(private readonly PushTestPolicy $politique) {}

    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));

        $destinataires = $this->politique->destinatairesAdmis()
            ->when($recherche !== '', fn ($q) => $q->whereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($recherche).'%']))
            ->limit(50)
            ->get();

        $choisi = null;
        $appareils = collect();

        if (($id = (string) $request->query('user', '')) !== '') {
            $candidat = User::query()->find($id);

            // Meme politique a l'affichage qu'a l'envoi : l'ecran ne peut pas
            // proposer quelqu'un que le POST refuserait.
            if ($this->politique->destinataireEstAdmis($candidat)) {
                $choisi = $candidat;
                $appareils = $this->politique->abonnementsEnvoyables($candidat)->get()
                    ->map(fn (PushSubscription $a) => (object) ([
                        'id' => $a->id,
                        'libelle' => $a->device_label,
                        'vu' => $a->last_seen_at,
                        'origine' => $a->created_env,
                    ] + $this->politique->reperageDe($a)));
            }
        }

        return view('admin.outils.test-notification', $this->diagnostic($request) + [
            'recherche' => $recherche,
            'destinataires' => $destinataires,
            'choisi' => $choisi,
            'appareils' => $appareils,
        ]);
    }

    /**
     * Envoyer a UN appareil, choisi, apres revalidation complete.
     */
    public function send(Request $request): RedirectResponse
    {
        $valide = $request->validate([
            'user' => ['required', 'string', 'uuid'],
            'subscription' => ['required', 'string', 'uuid'],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:160'],
            // Une destination INTERNE seulement : le chemin est relatif, et
            // l'ecouteur du Service Worker refuse de toute facon une URL qui
            // ne serait pas de notre origine.
            'path' => ['nullable', 'string', 'max:300', 'starts_with:/'],
        ]);

        $destinataire = User::query()->find($valide['user']);

        // 1 — le destinataire est-il TOUJOURS admis ? Le formulaire ne fait
        // pas autorite : il a pu etre fabrique a la main.
        if (! $this->politique->destinataireEstAdmis($destinataire)) {
            return back()->with('push_test_resultat', __('admin.push_test_recipient_refused'));
        }

        // 2 — l'appareil appartient-il BIEN a ce destinataire, et est-il
        // envoyable ? La requete part de la politique, pas de l'identifiant
        // recu : un abonnement d'un autre compte ne peut pas etre atteint.
        $appareil = $this->politique->abonnementsEnvoyables($destinataire)
            ->where('id', $valide['subscription'])
            ->first();

        if ($appareil === null) {
            return back()->with('push_test_resultat', __('admin.push_test_device_refused'));
        }

        $resultats = app(NotificationWebPushDeliverer::class)->envoyerUnTest(
            collect([$appareil]),
            [
                'title' => $valide['title'],
                'body' => $valide['body'],
                'url' => url($valide['path'] ?? '/notifications'),
                'tag' => 'bouclepro-test',
            ],
        );

        // 3 — la TRACE de l'essai, demandee par MASTER. Elle nomme QUI a
        // envoye a QUI, sans jamais ecrire l'endpoint ni une clef.
        Log::info('TASK-1740 envoi de test Web Push depuis l administration.', [
            'par' => (string) $request->user()?->email,
            'vers' => (string) $destinataire->email,
            'appareil' => $this->politique->reperageDe($appareil),
            'resultat' => ['ok' => $resultats['ok'], 'revoque' => $resultats['revoque'], 'echec' => $resultats['echec']],
        ]);

        return back()
            ->with('push_test_resultat', __('admin.push_test_done', [
                'ok' => $resultats['ok'],
                'revoques' => $resultats['revoque'],
                'echecs' => $resultats['echec'],
            ]))
            ->with('push_test_detail', $resultats['motifs'])
            ->with('push_test_http', $resultats['http']);
    }

    /** @return array<string, mixed> */
    private function diagnostic(Request $request): array
    {
        $connexion = (string) config('database.default');
        $reglage = config("database.connections.{$connexion}");
        $base = is_array($reglage)
            ? (string) ((new ConfigurationUrlParser)->parseConfiguration($reglage)['database'] ?? '?')
            : '?';

        return [
            'pushActif' => (bool) config('webpush.enabled'),
            'clePubliquePosee' => ((string) config('webpush.vapid.public_key')) !== '',
            'clePriveePosee' => ((string) config('webpush.vapid.private_key')) !== '',
            'sujet' => (string) config('webpush.vapid.subject'),
            'base' => $base,
            'sortiesBloquees' => is_array($reglage) && ExternalEgressGuard::viseUnJumeau($reglage),
            'pushAutorise' => ExternalEgressGuard::sortieDeRecetteAutorisee(
                'https',
                'fcm.googleapis.com',
                [ExternalEgressGuard::OPTION_TRANSPORT_PUSH => true],
            ),
        ];
    }
}
