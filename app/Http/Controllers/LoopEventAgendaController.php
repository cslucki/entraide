<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\Organization;
use App\Services\Loops\EventException;
use App\Services\Loops\LoopEventService;
use App\Support\Loops\LoopEventPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * L'agenda d'une Organization : les rencontres de mes Boucles, plus celles
 * ouvertes a tout le monde.
 *
 * TASK-1706 — cette page ORGANISE desormais, et cette phrase remplace celle qui
 * disait l'inverse : « la page ne CREE rien — organiser se fait dans la
 * Boucle ». Cyril : « ceci n'est pas pratique, car il faut donc passer par une
 * boucle. Pas de bouton pour creer un evenement. » La decision est revisee,
 * pas contredite en silence.
 *
 * Ce qui n'a PAS change : elle n'ecrit rien elle-meme. Elle lit et elle delegue,
 * a travers le meme service et le meme presentateur que la Card, pour que les
 * deux ecrans ne puissent pas diverger.
 *
 * TASK-1656 : elle porte desormais UN geste, celui de REPONDRE a une invitation.
 * Ce n'est pas organiser. `LoopEventService::canRespondTo()` autorisait deja
 * tout membre actif de l'Organization a repondre a un evenement remonte au
 * niveau Organization — « c'est le sens meme de l'avoir remonte », dit son
 * docblock — mais AUCUNE interface ne l'offrait : la Card vit dans la Boucle, et
 * une non-membre n'y accede pas. Le moteur permettait ce que l'interface
 * n'offrait pas ; c'est la dette
 * `ORG_WIDE_EVENT_HAS_NO_RSVP_SURFACE_FOR_NON_MEMBERS` de T1655.
 *
 * Aucune regle d'autorisation n'est ecrite ici : le service tranche, comme pour
 * la Card. Rejoindre la Boucle n'est pas demande.
 */
class LoopEventAgendaController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $organization = $this->resolveOrganization($user);

        // TASK-1658 — l'acces au tenant passe par l'autorite unique.
        //
        // La garde disait « on n'entre pas dans l'Organization d'un autre ».
        // Elle reste vraie pour un utilisateur ordinaire ; elle etait fausse
        // pour le SuperAdmin plateforme, qui doit pouvoir inspecter n'importe
        // quel tenant sans en devenir membre.
        abort_unless($user->canAccessOrganization($organization), 404);

        $service = app(LoopEventService::class);
        $presenter = app(LoopEventPresenter::class);

        // Le cloisonnement est fait par le service : Evenements remontes au
        // niveau Organization, plus ceux des Boucles dont cette personne est
        // membre. Rien d'autre ne remonte, meme avec un identifiant connu.
        $events = $service->agendaFor($user, $organization->id)
            ->map(fn (LoopEvent $event) => $presenter->present($event, $user, $event->loop));

        // Les Boucles proposees au filtre sont celles reellement presentes :
        // offrir une option vide serait du bruit.
        $loops = $events->pluck('loop_name', 'loop_id')->filter()->unique()->sort();

        $when = $request->query('when', 'upcoming');
        $loopFilter = $request->query('loop');
        $formatFilter = $request->query('format');

        $filtered = $events
            ->when($loopFilter, fn ($c) => $c->where('loop_id', $loopFilter))
            ->when($formatFilter, fn ($c) => $c->where('format', $formatFilter))
            ->filter(fn (array $e) => $when === 'past'
                ? ($e['is_past'] || $e['is_cancelled'])
                : (! $e['is_past'] && ! $e['is_cancelled']));

        $filtered = $when === 'past'
            ? $filtered->sortByDesc('starts_at')
            : $filtered->sortBy('starts_at');

        // TASK-1706 — ou cette personne peut-elle organiser, et sinon pourquoi.
        // Le service tranche ; cette page ne redevine rien.
        $creation = $service->creationContextFor($user, $organization->id);

        return view('loops.agenda', [
            'organization' => $organization,
            'creationLoops' => $creation['loops'],
            'creationRefus' => $creation['refus'],
            'events' => $filtered->values(),
            'loops' => $loops,
            'when' => $when,
            'loopFilter' => $loopFilter,
            'formatFilter' => $formatFilter,
        ]);
    }

    /**
     * TASK-1706 — le formulaire de creation, depuis l'agenda.
     *
     * Cyril : « pas de bouton pour creer un evenement. Il faudrait avoir un
     * bouton, qui permet de creer un nouvel evenement, ceci comme sur une
     * boucle. Et bien entendu on selectionne la boucle. »
     *
     * ## Ce que cette methode NUANCE
     *
     * Le docblock de cette classe disait : « La page ne CREE rien — organiser
     * se fait dans la Boucle. » C'etait vrai, et c'est precisement ce que Cyril
     * demande de changer : passer par une Boucle pour organiser n'est pas
     * pratique quand on arrive par l'agenda. La decision est donc revisee ici,
     * explicitement, et non contredite en silence.
     *
     * Ce qui ne change PAS : l'Evenement appartient toujours a une Boucle.
     * `loop_events.loop_id` est NOT NULL, et aucune ecriture ne contourne
     * `LoopEventService`.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $organization = $this->resolveOrganization($user);

        abort_unless($user->canAccessOrganization($organization), 404);

        $contexte = app(LoopEventService::class)->creationContextFor($user, $organization->id);

        // Le droit est REEVALUE ici, et pas seulement a l'affichage du bouton.
        // Une URL tapee a la main doit rencontrer la meme reponse qu'un clic.
        if ($contexte['loops']->isEmpty()) {
            return $this->versAgenda($organization)
                ->with('error', __('events.'.($contexte['refus'] ?? 'role').'_refus'));
        }

        return view('loops.agenda-create', [
            'organization' => $organization,
            'loops' => $contexte['loops'],
            'canPublishToOrganization' => $contexte['loops']
                ->contains(fn ($loop) => app(LoopEventService::class)->canPublishToOrganization($user, $loop)),
        ]);
    }

    /**
     * Enregistrer la rencontre.
     *
     * Aucune regle n'est ecrite ici : `LoopEventService::create()` porte tout —
     * le droit de creer, l'appartenance active, le fuseau, la coherence des
     * dates, le lieu ou l'URL selon le format, et le refus de publier une
     * Boucle privee a l'Organization. Dupliquer ici le moindre predicat ferait
     * exister deux verites.
     *
     * La Boucle est resolue A LA MAIN avec sa frontiere de tenant, pour la
     * raison exposee sur `respond()` : un identifiant connu d'une autre
     * Organization ne doit jamais atteindre le service.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $organization = $this->resolveOrganization($user);

        abort_unless($user->canAccessOrganization($organization), 404);

        $loop = Loop::query()
            ->whereKey((string) $request->input('loop_id'))
            ->where('organization_id', $organization->id)
            ->first();

        abort_if($loop === null, 404);

        try {
            app(LoopEventService::class)->create($user, $loop, $request->all());
        } catch (EventException $e) {
            return back()->withInput()->with('error', __($e->getMessage()));
        }

        return $this->versAgenda($organization)->with('status', __('events.created'));
    }

    /** L'agenda, sur la surface ou l'on se trouve. */
    private function versAgenda(Organization $organization): RedirectResponse
    {
        return \Illuminate\Support\Facades\Route::has('organization.events.agenda')
            ? redirect()->route('organization.events.agenda', ['organization' => $organization->slug])
            : redirect()->route('events.agenda');
    }

    /**
     * Repondre a une invitation depuis l'agenda.
     *
     * Le service porte TOUTE la decision — droit de repondre, evenement annule,
     * Boucle archivee, concurrence. Ce controleur ne fait que lui passer la
     * main : dupliquer ici le moindre predicat ferait exister deux verites sur
     * qui peut repondre, et c'est exactement ce que la Card et cet ecran
     * partagent pour eviter.
     */
    public function respond(Request $request): RedirectResponse
    {
        $user = $request->user();
        $organization = $this->resolveOrganization($user);

        abort_if($user->organization_id !== $organization->id, 404);

        // L'Evenement est resolu ICI, et pas par le liage implicite de route.
        //
        // Deux raisons. D'abord une contrainte de forme : cette methode sert
        // DEUX routes, `/agenda/...` et `/org/{organization}/agenda/...`. La
        // seconde passe le slug d'Organization en premier argument positionnel
        // — c'est pourquoi les autres controleurs org-prefixes declarent un
        // `string $org` —, si bien qu'une signature typee recevrait le slug a la
        // place de l'Evenement. Le liage implicite ne peut pas servir les deux.
        //
        // Ensuite, et c'est la vraie raison : resoudre a la main OBLIGE a ecrire
        // la frontiere de tenant. Un `LoopEvent` charge par identifiant n'est
        // filtre par aucun scope global ; sans le `where` ci-dessous, un
        // identifiant connu d'une autre Organization arriverait jusqu'au
        // service. Un 404 franc vaut mieux qu'un refus metier sur une ressource
        // qu'on n'aurait jamais du regarder.
        $event = LoopEvent::query()
            ->whereKey((string) $request->route('event'))
            ->where('organization_id', $organization->id)
            ->first();

        abort_if($event === null, 404);

        $donnees = $request->validate([
            'response' => ['required', Rule::in(LoopEventResponse::RESPONSES)],
        ]);

        $loop = $event->loop;

        abort_if($loop === null, 404);

        try {
            app(LoopEventService::class)->respond($user, $event, $loop, $donnees['response']);
        } catch (EventException $e) {
            return back()->withErrors(['response' => __($e->getMessage())]);
        }

        return back()->with('status', __('events.response_saved'));
    }

    private function resolveOrganization($user): Organization
    {
        $organization = \App\Support\Tenancy\CurrentOrganization::get();

        if ($organization instanceof Organization) {
            return $organization;
        }

        abort_if($user->organization === null, 404);

        return $user->organization;
    }
}
