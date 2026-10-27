<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmissionConflict;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

class BlogCoAuthorController extends Controller
{
    public function index(BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $coAuthors = $post->coAuthors()
            ->get(['users.id', 'users.name', 'users.first_name', 'users.email', 'users.avatar', 'users.organization_id', 'users.banned_at'])
            ->map(fn (User $user) => [
                'id' => $user->isDisplayableIn($organization) ? $user->id : null,
                'name' => $user->publicDisplayName(),
                'first_name' => $user->isDisplayableIn($organization) ? $user->first_name : null,
                'email' => $user->isDisplayableIn($organization) ? $user->email : null,
                'avatar_url' => $user->publicAvatarUrl(),
            ]);

        return response()->json(['co_authors' => $coAuthors]);
    }

    public function store(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('manageCoAuthors', $post);

        $data = $request->validate([
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'id')
                    ->whereNull('banned_at'),
            ],
        ]);

        $user = User::assignable()->findOrFail($data['user_id']);

        if ($user->organization_id !== $post->organization_id) {
            return response()->json(['message' => __('blog.co_author_cross_org')], 422);
        }

        if ($user->id === $post->user_id) {
            return response()->json(['message' => __('blog.co_author_is_owner')], 422);
        }

        $exists = $post->coAuthors()->where('user_id', $user->id)->exists();
        if ($exists) {
            return response()->json(['message' => __('blog.co_author_already')], 422);
        }

        $post->coAuthors()->attach($user->id, [
            'role' => 'coauthor',
            'added_by' => $request->user()->id,
        ]);

        $this->prevenirLeCoAuteur($post, $user, $request->user());

        return response()->json([
            'co_author' => [
                'id' => $user->id,
                'name' => $user->fullName,
                'first_name' => $user->first_name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
            ],
            'message' => __('blog.co_author_added'),
        ]);
    }

    /**
     * TASK-1702 — prevenir celui qu'on vient d'ajouter.
     *
     * Cyril : « quand on ajoute un auteur X en co-ecriture, il doit recevoir
     * une notification dans l'application pour l'informer de cela. »
     *
     * ## Ce que cette methode ne doit JAMAIS faire
     *
     * Empecher l'ajout. La co-ecriture est deja enregistree quand on arrive
     * ici ; une panne du canal de notification ne doit pas la defaire ni
     * rendre une erreur a l'ecran. D'ou les deux `catch` : le conflit est
     * normal (on a deja prevenu cette personne pour cet article), le reste est
     * journalise et tu. Meme forme qu'en TASK-1697 pour les commentaires.
     *
     * ## L'identifiant d'evenement
     *
     * `uuid5` deterministe derive de la CLE et de l'ARTICLE. La table porte
     * `UNIQUE(event_id, recipient_id)` : le destinataire n'a donc pas besoin
     * d'entrer dans le nom — deux co-auteurs du meme article recoivent bien
     * chacun la leur, et retirer puis re-ajouter la meme personne ne la
     * previent pas deux fois.
     */
    private function prevenirLeCoAuteur(BlogPost $post, User $coAuteur, ?User $acteur): void
    {
        try {
            app(NotificationEmitter::class)->emit(
                notificationKey: NotificationCatalogue::BLOG_CO_AUTHOR_ADDED,
                organization: (string) $post->organization_id,
                recipient: $coAuteur,
                eventId: (string) Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    'bouclepro:notification:'.NotificationCatalogue::BLOG_CO_AUTHOR_ADDED.':'.$post->id,
                ),
                objectType: NotificationCatalogue::objectTypeFor(NotificationCatalogue::BLOG_CO_AUTHOR_ADDED),
                objectId: (string) $post->id,
                // L'acteur n'est porte que s'il appartient au MEME tenant : une
                // notification ne doit pas nommer quelqu'un d'une autre
                // Organization.
                actor: $acteur !== null && (string) $acteur->organization_id === (string) $post->organization_id ? $acteur : null,
            );
        } catch (NotificationEmissionConflict) {
            // Deja prevenu pour cet article. Un rejeu n'est pas une anomalie.
        } catch (\Throwable $echec) {
            Log::warning('TASK-1702 notification de co-ecriture non emise', [
                'blog_post_id' => $post->id,
                'co_author_id' => $coAuteur->id,
                'raison' => $echec->getMessage(),
            ]);
        }
    }

    public function destroy(BlogPost $post, User $user): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('manageCoAuthors', $post);

        $post->coAuthors()->detach($user->id);

        return response()->json(['message' => __('blog.co_author_removed')]);
    }

    public function search(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $query = $request->input('q', '');

        $users = User::assignable()
            ->where('organization_id', $post->organization_id)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'first_name', 'email', 'avatar'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->fullName,
                'avatar_url' => $user->avatar_url,
            ]);

        return response()->json(['users' => $users]);
    }

    public function orgIndex(string $org, BlogPost $post): JsonResponse
    {
        return $this->index($post);
    }

    public function orgStore(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->store($request, $post);
    }

    public function orgDestroy(string $org, BlogPost $post, User $user): JsonResponse
    {
        return $this->destroy($post, $user);
    }

    public function orgSearch(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->search($request, $post);
    }
}
