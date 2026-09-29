<?php

namespace App\Http\Controllers;

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
 * La page ne CREE rien — organiser se fait dans la Boucle. Elle lit, a travers le
 * meme service et le meme presentateur que la Card, pour que les deux ecrans ne
 * puissent pas diverger.
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

        // Meme regle que partout ailleurs : on n'entre pas dans l'Organization
        // d'un autre, meme par une URL ecrite a la main.
        abort_if($user->organization_id !== $organization->id, 404);

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

        return view('loops.agenda', [
            'organization' => $organization,
            'events' => $filtered->values(),
            'loops' => $loops,
            'when' => $when,
            'loopFilter' => $loopFilter,
            'formatFilter' => $formatFilter,
        ]);
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
