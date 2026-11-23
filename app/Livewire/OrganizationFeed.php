<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReactsAndCommentsOnFeedPosts;
use App\Models\FeedPost;
use App\Models\Reaction;
use App\Support\Search\TexteContient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OrganizationFeed extends Component
{
    use ReactsAndCommentsOnFeedPosts;
    use WithPagination;

    public int $perPage = 15;

    /**
     * TASK-1885 C8 (demande Cyril, 22/11) — filtre par type de publication.
     * Vide = tout. Seuls les types PRESENTS dans le Flux visible du membre sont
     * proposes ; une valeur hors liste (URL forgee) est ignoree.
     */
    #[Url(as: 'type', except: '')]
    public string $type = '';

    /** TASK-1885 C8 — recherche texte (titre, contenu, auteur). */
    #[Url(as: 'q', except: '')]
    public string $q = '';

    public function loadMore(): void
    {
        $this->perPage += 15;
    }

    public function updatedType(): void
    {
        $this->perPage = 15;
    }

    public function updatedQ(): void
    {
        $this->perPage = 15;
    }

    public function resetFilters(): void
    {
        $this->type = '';
        $this->q = '';
        $this->perPage = 15;
    }

    public function render()
    {
        $orgId = currentOrganization()?->id;

        $pinned = collect();
        $items = collect();
        $hasMore = false;
        $emojiMap = [];
        $types = [];

        if ($orgId && auth()->user()?->can('viewAny', FeedPost::class)) {
            // TASK-1752 — le filtre d'audience, sur TOUTES les requetes.
            //
            // L'oublier sur l'une suffirait a faire fuir une ligne restreinte :
            // une annonce epinglee est lue par la premiere, tout le reste par
            // la seconde — et la liste des types en dirait l'existence.
            // Construite UNE fois (le filtre d'audience lit les adhesions),
            // puis clonee pour chaque requete.
            $socle = FeedPost::forOrganization($orgId)
                ->published()
                ->visibleTo(auth()->user());
            $base = fn (): Builder => clone $socle;

            // TASK-1885 C8 — les types qui existent dans CE Flux, pour ce membre.
            $types = $base()->reorder()->distinct()->pluck('type')->filter()->values()->all();

            $type = in_array($this->type, $types, true) ? $this->type : '';
            $recherche = trim(Str::limit($this->q, 100, ''));

            $filtrer = function (Builder $query) use ($type, $recherche): Builder {
                if ($type !== '') {
                    $query->where('type', $type);
                }

                if ($recherche !== '') {
                    // TASK-1885 C9 — meme aide que la recherche des Boucles :
                    // `ESCAPE` explicite, jokers pris litteralement.
                    $motif = TexteContient::motif($recherche);

                    $query->where(function (Builder $q) use ($motif): void {
                        TexteContient::where($q, 'title', $motif);
                        TexteContient::orWhere($q, 'content', $motif);
                        $q->orWhereHas('user', fn (Builder $u) => TexteContient::where($u, 'name', $motif));
                    });
                }

                return $query;
            };

            $pinned = $filtrer($base()->pinned())
                ->with(['user', 'reactions.user', 'comments.user', 'loops', 'boucle'])
                ->latest('published_at')
                ->get();

            $paginator = $filtrer($base()->whereNull('pinned_at'))
                ->with(['user', 'reactions.user', 'comments.user', 'loops', 'boucle'])
                ->latest('published_at')
                ->paginate($this->perPage);

            $items = $paginator;
            $hasMore = $paginator->hasMorePages();
            $emojiMap = Reaction::emojiMap();
        }

        $filtresActifs = $this->type !== '' || trim($this->q) !== '';

        return view('livewire.organization-feed', compact('pinned', 'items', 'hasMore', 'emojiMap', 'types', 'filtresActifs'));
    }
}
