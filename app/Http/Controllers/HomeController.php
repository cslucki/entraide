<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Homepage\RootDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): RedirectResponse|View
    {
        // TASK-1782 — un membre d'une Organization scopee qui demande la racine
        // veut SON accueil, pas celui de la plateforme.
        //
        // C'est aussi le correctif du lancement PWA : le manifeste declare
        // `start_url: "/"` et `scope: "/"`, partages par toutes les
        // Organizations. Plutot qu'un manifeste par compte — qui multiplierait
        // les PWA installables et casserait celles deja installees — c'est la
        // RACINE qui devient capable de reconnaitre son visiteur. Une seule
        // PWA, un seul manifeste, et chacun arrive chez lui.
        //
        // Pose AVANT tout le reste, et c'est voulu : la resolution souveraine
        // de `RootDestination` (TASK-1628) decide de ce que la racine sert au
        // PUBLIC. Elle n'est pas touchee, elle est seulement precedee. Les
        // quatre cas qui ne redirigent pas — anonyme, superadmin, statut
        // invalide, Organization par defaut — sont documentes un a un dans
        // `accueilDuCompteAuthentifie()`.
        if (($accueilDuCompte = accueilDuCompteAuthentifie()) !== null) {
            return redirect()->to($accueilDuCompte);
        }

        $defaultOrganization = Organization::where('is_default', true)->first()
            ?? Organization::where('slug', 'main')->where('is_active', true)->first();

        // TASK-1506 — le superadmin choisit ce que sert la racine
        // (`/admin/homepage`).
        //
        // TASK-1628 — et ce choix est SOUVERAIN.
        //
        // `RootDestination::effective()` porte desormais seule la resolution :
        // choix explicite s'il y en a un, sinon le repli historique (gabarit
        // hero -> landing de l'Organization). Elle etait ecrite ici en clair,
        // et l'ecran d'administration, lui, lisait `normalize()` — les deux
        // divergeaient donc par construction. Une seule fonction, deux
        // appelants : l'ecran ne peut plus annoncer autre chose que ce que
        // cette methode sert.
        //
        // Le defaut mesure par Cyril tenait a cette divergence : « Accueil
        // traditionnel » enregistre et confirme a l'ecran, et le visiteur
        // arrive sur le Shell, parce que `normalize()` rabat NULL sur
        // `HOMEPAGE` et rendait « jamais choisi » indiscernable de « choisi ».
        $stored = $defaultOrganization?->root_destination;
        $destination = RootDestination::effective($stored, $defaultOrganization?->homepage_template);

        if ($destination === RootDestination::SHELL_WELCOME && $defaultOrganization !== null) {
            // La landing de l'Organization ; le mode d'affichage du Guest
            // Shell (TASK-1500) decide seul de sa forme. C'est aussi la sortie
            // du repli historique : un gabarit hero sans choix explicite.
            return redirect()->route('organization.home', $defaultOrganization);
        }

        if (($route = RootDestination::routeFor($destination)) !== null) {
            // `members.index` est derriere `auth` depuis TASK-1479 (P0
            // privacy) : un anonyme y rencontre la connexion, puis revient.
            return redirect()->route($route);
        }

        $stats = [
            'users' => User::activeAccount()->count(),
            'services' => Service::active()->count(),
            'requests' => ServiceRequest::open()->count(),
            'exchanges' => Transaction::where('status', 'completed')->count(),
        ];

        $featuredServices = Service::active()
            ->with('category', 'user')
            ->inRandomOrder()
            ->limit(6)
            ->get();

        $categories = Category::orderBy('name_b2c')->get();

        return view('home', compact('stats', 'featuredServices', 'categories', 'defaultOrganization'));
    }

    public function members(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            return view('members.setup-required');
        }

        $organizationId = $organization->id;

        // TASK-1697 correctif 15 — recherche simple sur le prenom et le nom.
        //
        // `users.name` porte le nom de FAMILLE depuis TASK-1399 et `first_name`
        // est NULLABLE : chercher sur l'un des deux seulement laisserait la
        // moitie des gens introuvables. La troisieme clause couvre la saisie
        // complete (« cyril s »), que ni l'un ni l'autre des champs ne contient.
        //
        // `ilike` en PostgreSQL, `like` en SQLite : meme precedent que
        // `DossierFileController`. `like` est deja insensible a la casse en
        // SQLite, il ne l'est pas en PostgreSQL.
        $recherche = trim((string) request()->query('q', ''));

        // TASK-1762 point 11.4 — la vue « Nouveaux arrivés ».
        //
        // L'annuaire etait DEJA trie du plus recent au plus ancien : un bouton
        // qui ne ferait que « trier par date » n'aurait rien change a l'ecran.
        // Arbitrage de Cyril : le bouton change de PRESENTATION — une liste ou
        // la photo est grande et la presentation lue EN ENTIER, la ou la carte
        // la tronque a trois lignes.
        //
        // Toute valeur autre que `latest` se lit « vue normale » : un parametre
        // forge ne doit jamais fabriquer un etat tiers.
        $vueNouveaux = request()->query('view') === 'latest';

        // TASK-1762 point 10 — le filtre « Localisation ».
        //
        // Cyril a tranche le menu plutot que le champ libre : « j'ai peur que
        // les gens ne trouvent pas le fait qu'on puisse chercher par ville ».
        // Un menu MONTRE ce qui est cherchable ; un champ libre le cache.
        $ville = trim((string) request()->query('city', ''));

        // Les villes du parc, REGROUPEES SANS TENIR COMPTE DE LA CASSE.
        //
        // Ce n'est pas une precaution theorique : le jumeau porte « Marseille »
        // ET « MARSEILLE », saisis a la main par des gens differents. Un
        // `GROUP BY city` brut les listerait tous les deux dans le menu, et
        // chacun ne montrerait qu'une partie des membres de la ville.
        //
        // Le regroupement se fait en PHP plutot qu'en SQL : `LOWER()` sur une
        // colonne se comporte differemment selon le collationnement, et le
        // parc d'une Organization tient de toute facon en memoire.
        $villes = User::where('organization_id', $organizationId)
            ->activeAccount()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->pluck('city')
            ->groupBy(fn ($v) => mb_strtolower(trim($v)))
            ->map(function ($graphies) {
                // L'orthographe la PLUS FREQUENTE represente le groupe, et
                // l'ordre alphabetique departage les ex aequo : sans ce second
                // critere, « Marseille » et « MARSEILLE » a une voix chacun
                // rendraient tantot l'un tantot l'autre d'un chargement a
                // l'autre.
                $parGraphie = $graphies->countBy()->sortDesc();
                $maximum = $parGraphie->first();

                return [
                    'libelle' => $parGraphie->filter(fn ($n) => $n === $maximum)->keys()->sort()->first(),
                    'total' => $graphies->count(),
                ];
            })
            ->sortBy('libelle', SORT_NATURAL | SORT_FLAG_CASE);

        // Une ville forgee dans l'URL ne filtre rien : le menu ne propose que
        // ce que le parc contient, et un parametre invalide doit se lire comme
        // « aucun filtre », pas comme « aucun membre ».
        if ($ville !== '' && ! $villes->has(mb_strtolower($ville))) {
            $ville = '';
        }

        $members = User::where('organization_id', $organizationId)
            ->activeAccount()
            ->when($recherche !== '', function ($q) use ($recherche, $organization) {
                $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $motif = '%'.$recherche.'%';

                // TASK-1762 point 9 — la structure entre dans la recherche,
                // mais SEULEMENT si l'Organization a ouvert le champ.
                //
                // Ce n'est pas une precaution de forme : decocher la case
                // n'efface AUCUNE donnee (c'est garde par un test du point 2).
                // Une Organization qui a essaye puis renonce garde donc des
                // structures en base, et une recherche non bornee les rendrait
                // trouvables alors qu'elles ne s'affichent plus nulle part.
                $chercheLaStructure = structureIsShown($organization);

                $q->where(function ($w) use ($like, $motif, $chercheLaStructure) {
                    $w->where('first_name', $like, $motif)
                        ->orWhere('name', $like, $motif)
                        ->orWhereRaw("COALESCE(first_name, '') || ' ' || COALESCE(name, '') ".$like.' ?', [$motif]);

                    if ($chercheLaStructure) {
                        $w->orWhere('structure', $like, $motif);
                    }

                    // TASK-1762 point 13 — le CONTENU de la presentation.
                    //
                    // « la personne dit dans sa présentation "Je suis auditeur
                    // Qualiopi", on doit pouvoir le trouver en tapant
                    // "Qualiopi" ». La recherche cesse d'etre un annuaire de
                    // noms pour devenir un annuaire de COMPETENCES.
                    //
                    // Aucune donnee nouvelle n'est exposee : la bio est deja
                    // affichee sur la carte et, en entier, dans la vue
                    // « Nouveaux arrivés ». Chercher dedans ne rend trouvable
                    // que ce qui etait deja lisible.
                    //
                    // Pas de garde d'Organization ici, contrairement a la
                    // structure : la bio n'a pas de drapeau, elle fait partie
                    // du profil depuis toujours.
                    $w->orWhere('bio', $like, $motif);

                    // TASK-1762 point 17 — le slogan, meme raisonnement que la
                    // bio : deja affiche sur la carte et la fiche publique,
                    // aucune garde d'Organization puisqu'il n'en a pas.
                    $w->orWhere('slogan', $like, $motif);
                });
            })
            // TASK-1762 point 10 — la comparaison ignore la casse, pour la
            // meme raison que le regroupement du menu : choisir « Marseille »
            // doit rendre AUSSI les « MARSEILLE ».
            ->when($ville !== '', fn ($q) => $q->whereRaw('LOWER(city) = ?', [mb_strtolower($ville)]))
            ->withCount([
                'services as active_services_count' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->active()->where('organization_id', $organizationId),
                'serviceRequests as open_requests_count' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->open()->where('organization_id', $organizationId),
            ])
            ->with(['services' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->active()->where('organization_id', $organizationId)->with('skills', 'category')])
            // TASK-1812 correctif 04 — demande de Cyril : la grille par defaut
            // se lit desormais par PRENOM, et non plus par date d'inscription.
            // Le point 11.4 l'avait anticipe : le tri de « Nouveaux arrivés »
            // est son propre CONTRAT, independant de celui de la grille — il
            // reste donc au plus recent d'abord, inchange.
            //
            // `COALESCE(first_name, name)` et non `LOWER(first_name)` :
            // `first_name` est NULLABLE (voir plus haut), et `LOWER()` ne
            // replie pas les caracteres accentues de la meme facon en SQLite
            // (ASCII seul, sans extension ICU) qu'en PostgreSQL — le meme
            // piege de collation que celui deja documente pour les villes,
            // quelques lignes plus haut. Majuscule/minuscule n'a pas besoin
            // d'un repli : les deux bases les comparent deja de la meme facon
            // par defaut, et les prenoms sont toujours saisis avec une
            // majuscule initiale.
            ->when(
                $vueNouveaux,
                fn ($q) => $q->orderByDesc('created_at'),
                fn ($q) => $q->orderByRaw('COALESCE(first_name, name) asc')
            )
            // Tiebreak deterministe : deux membres au meme prenom, ou crees
            // au meme instant (import, seed), ne doivent pas changer d'ordre
            // d'une page a l'autre au fil de la pagination.
            ->orderBy('id')
            // TASK-1762 point 11.2 — 36 et non 16.
            //
            // Mesure du 05/11 : les Organizations du parc comptent 6, 9 et 35
            // membres. A 16 par page, la plus grande se coupait en trois pour
            // rien. A 36, aucune ne pagine aujourd'hui — et le chargement au
            // defilement prend le relais le jour ou l'une d'elles grandira.
            //
            // 36 et pas 30 : la grille fait trois colonnes (point 11.1), donc
            // un multiple de trois remplit toujours la derniere rangee.
            ->paginate(36)
            ->withQueryString();

        $identifiants = $members->getCollection()->pluck('id');

        // TASK-1697 correctif 15 — le compteur d'articles.
        //
        // MEME predicat que `ProfileController::show()` (lignes 100-108), pour
        // que le compteur de l'annuaire ne contredise pas la liste du profil.
        // Deliberement PAS `scopePublished()`, qui ajoute `listed_in_blog` : le
        // profil l'ignore, l'annuaire doit l'ignorer aussi.
        $articleCounts = $identifiants->isEmpty() ? collect() : BlogPost::query()
            ->whereIn('user_id', $identifiants)
            ->where('organization_id', $organizationId)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        // TASK-1697 correctif 15 — les Boucles de chaque membre.
        //
        // Le perimetre reprend celui de `VisibleLoops` : Organization courante,
        // Boucles `active` (donc jamais les archivees). Nommer une Boucle ne
        // revele rien de plus que le catalogue : depuis TASK-1075, « privee »
        // veut dire « contenu reserve aux membres », pas « cachee ».
        //
        // Le filtre porte sur `loops.organization_id`, JAMAIS sur
        // `loop_members.organization_id` : cette colonne est nullable et 3
        // lignes sur 24 y sont NULL en base — le filtre les perdrait en
        // silence. L'organisation de la Boucle est l'autorite.
        //
        // Aucun filtre de role : `LoopRoleRegistry` en connait trois (owner,
        // facilitator, member) plus l'alias historique `moderator`. « Membre,
        // animateur ou proprietaire » les couvre tous — c'est l'appartenance
        // ACTIVE qui est le critere, pas le role.
        $loopsByUser = $identifiants->isEmpty() ? collect() : LoopMember::query()
            ->whereIn('user_id', $identifiants)
            ->where('status', 'active')
            ->whereHas('loop', fn ($q) => $q->where('organization_id', $organizationId)->where('status', 'active'))
            ->with(['loop' => fn ($q) => $q->select('id', 'name', 'slug', 'organization_id', 'status')])
            ->get()
            ->groupBy('user_id')
            // TASK-1762 point 14 — les plus REPRESENTATIVES d'abord.
            //
            // La carte ne montre que les trois premieres et replie le reste :
            // l'ordre cesse d'etre cosmetique, il decide de ce qu'on voit. Une
            // Boucle qu'on ANIME dit davantage de quelqu'un qu'une Boucle dont
            // il est l'un des trente membres.
            //
            // Le nom reste le second critere : sans lui, deux Boucles de meme
            // role rendraient l'ordre de la base, qui n'est garanti par rien.
            ->map(function ($lignes) {
                $rang = ['owner' => 0, 'facilitator' => 1, 'moderator' => 1, 'member' => 2];

                return $lignes
                    ->filter(fn ($ligne) => $ligne->loop !== null)
                    ->unique(fn ($ligne) => $ligne->loop->id)
                    // Une clef COMPOSITE, et non un tableau de fermetures :
                    // `sortBy()` traite un tableau comme des paires
                    // [colonne, sens], pas comme une suite de criteres — il ne
                    // triait donc rien du tout, et la base rendait son ordre.
                    ->sortBy(fn ($ligne) => sprintf(
                        '%d-%s',
                        $rang[$ligne->role] ?? 3,
                        mb_strtolower((string) $ligne->loop->name),
                    ))
                    ->pluck('loop')
                    ->values();
            });

        return view('members.index', compact('members', 'recherche', 'articleCounts', 'loopsByUser', 'villes', 'ville', 'vueNouveaux'));
    }

    public function boucles(): View
    {
        return view('boucles.index');
    }

    public function partners(): View
    {
        return view('partenaires.index');
    }

    public function exchanges(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $organizationId = $organization->id;

        $exchanges = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('status', 'completed')
            ->where('organization_id', $organizationId)
            ->with(['buyer', 'seller', 'service.category', 'serviceRequest', 'reviews'])
            ->latest('updated_at')
            ->paginate(20);

        return view('exchanges.index', compact('exchanges'));
    }
}
