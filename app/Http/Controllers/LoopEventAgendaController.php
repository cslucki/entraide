<?php

namespace App\Http\Controllers;

use App\Models\LoopEvent;
use App\Models\Organization;
use App\Services\Loops\LoopEventService;
use App\Support\Loops\LoopEventPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * L'agenda d'une Organization : les rencontres de mes Boucles, plus celles
 * ouvertes a tout le monde.
 *
 * La page ne cree rien et ne modifie rien — organiser se fait dans la Boucle.
 * Elle lit, a travers le meme service et le meme presentateur que la Card, pour
 * que les deux ecrans ne puissent pas diverger.
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
