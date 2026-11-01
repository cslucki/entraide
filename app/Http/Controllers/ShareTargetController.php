<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Services\LoopMessageService;
use App\Services\UrlPreviewService;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\VisibleLoops;
use App\Support\Sharing\ShareIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * TASK-1738 — recevoir un lien partage depuis l'exterieur, et le publier dans
 * une Boucle apres validation humaine.
 *
 * ## Trois routes, et pourquoi pas une seule
 *
 * 1. `GET /partage` — PUBLIQUE. C'est l'`action` declaree dans
 *    `share_target` : la feuille de partage d'Android y arrive directement,
 *    avec `url`, `text` et `title` en query string.
 * 2. `GET /partage/choisir` — AUTHENTIFIEE. L'ecran ou l'on choisit la Boucle
 *    et ou l'on ecrit un commentaire facultatif.
 * 3. `POST /partage/publier` — AUTHENTIFIEE. La publication.
 *
 * ### Pourquoi la reception doit etre PUBLIQUE
 *
 * Si `/partage` etait derriere `auth`, le middleware redirigerait vers la
 * connexion AVANT que ce controleur ne s'execute — et les parametres du
 * partage seraient perdus en route. La personne se connecterait pour arriver
 * sur un ecran vide, sans comprendre ce qu'elle vient de perdre.
 *
 * La reception est donc ouverte, elle ne fait que LIRE et RANGER, et c'est la
 * deuxieme route qui exige une session. L'intention survit a la connexion
 * parce qu'elle vit en session : `regenerate()` change l'identifiant mais
 * CONSERVE le contenu.
 *
 * ### Pourquoi une redirection 303 entre les deux
 *
 * CDC section 4.5 : « eviter de laisser durablement la ressource partagee en
 * query string visible ». L'adresse partagee peut etre privee. En query
 * string, elle entre dans l'historique du navigateur, dans l'en-tete
 * `Referer` des requetes suivantes, et dans les journaux d'acces du serveur.
 *
 * La reception range donc l'intention cote serveur et redirige IMMEDIATEMENT
 * vers une URL nue. Le 303 — et pas le 302 — parce qu'il dit explicitement
 * « va chercher cette autre ressource en GET » : c'est le code fait pour
 * ca, et il evite qu'un navigateur rejoue la requete d'origine.
 */
class ShareTargetController extends Controller
{
    public function __construct(
        private readonly VisibleLoops $visibleLoops,
        private readonly LoopPermissionResolver $permissions,
        private readonly UrlPreviewService $apercus,
    ) {}

    /**
     * La cible de partage. Lit, range, et s'efface.
     */
    public function receive(Request $request): RedirectResponse
    {
        $intention = ShareIntent::depuisLaRequete($request);

        if ($intention !== null) {
            $request->session()->put(ShareIntent::CLE_SESSION, $intention->pourLaSession());
        } else {
            // Rien d'exploitable : on n'emporte pas une intention vide, qui
            // ferait croire plus tard a un partage en cours.
            $request->session()->forget(ShareIntent::CLE_SESSION);
        }

        // `Redirector::route($nom, $parametres, $statut, $entetes)` — le 303
        // est passe la, pas par `setStatusCode()` apres coup.
        return redirect()->route('partage.choisir', [], 303, self::ENTETES_DISCRETION);
    }

    /**
     * L'ecran de choix : quelle Boucle, et quel commentaire facultatif.
     */
    public function show(Request $request): Response
    {
        $intention = ShareIntent::depuisLaSession($request->session()->get(ShareIntent::CLE_SESSION));

        // Une intention perimee est RETIREE, pas seulement ignoree : la
        // laisser en session la ferait reapparaitre a la requete suivante.
        if ($intention === null) {
            $request->session()->forget(ShareIntent::CLE_SESSION);
        }

        $boucles = $this->bouclesOuIlPeutPublier($request);

        $apercu = $intention !== null
            ? $this->apercuDe($intention->url)
            : null;

        return response()
            ->view('sharing.choose', [
                'intention' => $intention,
                'apercu' => $apercu,
                'boucles' => $boucles,
            ])
            ->withHeaders(self::ENTETES_DISCRETION);
    }

    /**
     * Les Boucles ou cette personne peut REELLEMENT publier.
     *
     * Deux filtres, et les deux sont necessaires. `VisibleLoops` dit ce qu'elle
     * a le droit de VOIR dans son Organization courante — c'est la frontiere
     * de tenant. `LoopPermissionResolver` dit si elle peut y PUBLIER : voir
     * une Boucle en lecture n'autorise pas a y ecrire.
     *
     * Cette liste est une COMMODITE d'affichage. Elle ne fait pas autorite :
     * la permission est revalidee a la publication, sur la Boucle reellement
     * soumise. Un formulaire se falsifie ; une verification au moment de
     * l'ecriture, non.
     *
     * @return \Illuminate\Support\Collection<int, Loop>
     */
    private function bouclesOuIlPeutPublier(Request $request)
    {
        $utilisateur = $request->user();
        $organizationId = $utilisateur?->organization_id;

        if ($utilisateur === null || $organizationId === null) {
            return collect();
        }

        return $this->visibleLoops
            ->query($organizationId, $utilisateur)
            // `reorder()`, pas `orderBy()` : la requete du catalogue pose deja
            // `latest('updated_at')`, et un `orderBy` AJOUTE ne passe qu'apres
            // lui — il ne trierait jamais rien.
            ->reorder('name')
            ->get()
            // `is_member` en PLUS de la permission, et ce n'est pas une
            // redondance : le resolveur accorde `chatloop.post` a un admin
            // d'Organization SANS appartenance (etapes 3-4), mais
            // `LoopMessageService::assertCanSend()` exige une appartenance
            // active. Lister une Boucle que la publication refuserait ensuite
            // serait un bouton factice.
            ->filter(fn (Loop $loop): bool => (bool) $loop->is_member
                && $this->permissions->can($utilisateur, $loop, 'chatloop.post'))
            ->values();
    }

    /**
     * La publication — le seul endroit du parcours qui ECRIT.
     *
     * ## L'ordre des verifications, et pourquoi il est celui-la
     *
     * 1. L'intention d'abord : sans elle, rien a publier, quoi que dise le
     *    formulaire.
     * 2. Le jeton ensuite : il doit etre CELUI de l'intention en session. Un
     *    formulaire rejoue depuis un autre onglet, apres qu'un nouveau partage
     *    a remplace l'intention, publierait l'ancien lien sous le jeton du
     *    nouveau.
     * 3. La Boucle : existante, du MEME tenant, appartenance active et
     *    permission `chatloop.post` — la liste affichee n'a jamais fait
     *    autorite, c'est ici que la decision se prend (CDC 4.5 : revalidation
     *    lors du POST).
     * 4. La pose atomique du jeton, en DERNIER avant l'ecriture : la poser
     *    plus tot consommerait l'unique essai sur un refus de validation.
     *
     * ## L'anti-doublon, et ce qu'il garantit
     *
     * `Cache::add()` est une pose conditionnelle ATOMIQUE — l'insertion ne
     * reussit que si la cle n'existe pas, en une seule operation du magasin.
     * Deux confirmations concurrentes : une seule pose, un seul message
     * (CDC 4.6). Ce n'est PAS un « lire puis supprimer », dont les deux temps
     * laissent passer deux lecteurs.
     *
     * Si l'ecriture echoue APRES la pose, le jeton est relache et l'intention
     * reste en session : l'essai redevient possible, le lien n'est pas perdu
     * (CDC 4.5 — reprise sans doublon). Au succes, l'intention quitte la
     * session mais le jeton RESTE pose : un client qui retente apres un
     * timeout ambigu retombe sur « deja publie », jamais sur un second
     * message.
     */
    public function publish(Request $request, LoopMessageService $messages): RedirectResponse
    {
        $valide = $request->validate([
            'loop' => ['required', 'string', 'uuid'],
            'jeton' => ['required', 'string'],
            'commentaire' => ['nullable', 'string', 'max:'.ShareIntent::LONGUEUR_MAX_COMMENTAIRE],
        ]);

        $utilisateur = $request->user();
        $intention = ShareIntent::depuisLaSession($request->session()->get(ShareIntent::CLE_SESSION));

        if ($intention === null) {
            $request->session()->forget(ShareIntent::CLE_SESSION);

            return redirect()
                ->route('partage.choisir')
                ->with('partage_statut', __('sharing.intent_gone'));
        }

        if (! hash_equals($intention->jeton, (string) $valide['jeton'])) {
            return redirect()->route('partage.choisir')->withErrors([
                'jeton' => __('sharing.stale_form'),
            ]);
        }

        $boucle = Loop::query()
            ->where('id', $valide['loop'])
            ->where('organization_id', $utilisateur->organization_id)
            ->where('status', 'active')
            ->first();

        if ($boucle === null || ! $this->permissions->can($utilisateur, $boucle, 'chatloop.post')) {
            // Meme refus pour « inexistante », « autre tenant » et « pas le
            // droit » : detailler reviendrait a confirmer l'existence d'une
            // Boucle qu'on n'a pas a connaitre.
            return redirect()->route('partage.choisir')->withErrors([
                'loop' => __('sharing.loop_refused'),
            ]);
        }

        if (! Cache::add($this->cleAntiDoublon($intention), now()->timestamp, ShareIntent::EXPIRATION_SECONDES)) {
            return redirect()
                ->route('partage.choisir')
                ->with('partage_statut', __('sharing.already_published'));
        }

        $commentaire = trim((string) ($valide['commentaire'] ?? ''));
        $corps = $commentaire === '' ? $intention->url : $commentaire."\n\n".$intention->url;

        // L'apercu est un AGREMENT, jamais une condition : LinkedIn qui bloque
        // les robots rend `null`, et le message part quand meme (CDC 4.5).
        $apercu = $this->apercuDe($intention->url);
        $metadata = $apercu !== null ? ['url_preview' => $apercu] : null;

        try {
            $messages->sendUserMessage($boucle, $utilisateur, $corps, $metadata);
        } catch (\RuntimeException) {
            // Echec RECUPERABLE : le jeton est relache, l'intention reste en
            // session — l'essai suivant repart entier, sans doublon possible.
            Cache::forget($this->cleAntiDoublon($intention));

            return redirect()->route('partage.choisir')->withErrors([
                'loop' => __('sharing.publish_failed'),
            ]);
        }

        $request->session()->forget(ShareIntent::CLE_SESSION);

        return redirect()
            ->to($boucle->workspaceUrl())
            ->with('status', __('sharing.published'));
    }

    private function cleAntiDoublon(ShareIntent $intention): string
    {
        return 'partage:publie:'.$intention->jeton;
    }

    /**
     * L'apercu d'une URL, memorise le temps du parcours.
     *
     * L'ecran de choix le recupere, la publication le reutilise : sans ce
     * cache, chaque partage couterait DEUX allers-retours sortants vers le
     * meme site — et le message publie pourrait differer de ce que l'ecran
     * a montre. Toute la decision de securite (SSRF, plages privees,
     * redirections) reste dans `UrlPreviewService` : ce cache memorise un
     * resultat, il n'ouvre aucune porte.
     *
     * Un refus (`null`) n'est pas memorise — `remember()` ne retient pas
     * `null` — et c'est acceptable : il ne coute aucune requete sortante.
     */
    private function apercuDe(string $url): ?array
    {
        return Cache::remember(
            'partage:apercu:'.sha1($url),
            ShareIntent::EXPIRATION_SECONDES,
            fn (): ?array => $this->apercus->fetchPreview($url),
        );
    }

    /**
     * Les en-tetes de discretion, poses sur TOUTES les reponses de ce parcours.
     *
     * - `no-store` : la page porte l'adresse partagee et la liste des Boucles
     *   de quelqu'un. Ni l'une ni l'autre ne doit rester dans un cache
     *   partage, ni revenir par le bouton « precedent » apres deconnexion.
     * - `Referrer-Policy: no-referrer` : sans elle, une navigation depuis
     *   cette page emporterait son URL vers le site visite. Ici l'URL est
     *   deja nue, mais la regle doit tenir meme si la premiere redirection
     *   echoue — une defense qui depend d'une autre defense n'en est pas une.
     */
    private const ENTETES_DISCRETION = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'Referrer-Policy' => 'no-referrer',
    ];
}
