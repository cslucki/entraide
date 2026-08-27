<?php

namespace App\Support\Ai;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1315 — le PageContext du Shell « BouclePro IA » : OU se trouve
 * l'utilisateur, et sur quel objet, quand il ouvre le Shell.
 *
 * ## La regle qui gouverne tout ce fichier
 *
 * **Etre sur une page n'accorde AUCUN droit.** Le PageContext n'est pas une
 * autorisation : c'est une DESCRIPTION de ce que l'utilisateur voit deja.
 *
 * Deux chemins y menent, et ils n'ont pas le meme statut :
 *
 * 1. `forRequest()` — rendu de page. Le controleur de la page a deja tourne et
 *    a deja `abort(404)` si l'acces etait refuse ; l'objet resolu par le
 *    routeur est donc, par construction, un objet que l'utilisateur peut voir.
 *    Un identifiant forge n'arrive jamais ici : la page ne se rend pas.
 *
 * 2. `resolve()` — requete Livewire du Shell. Il n'y a plus de route de page :
 *    l'identifiant vient de proprietes `#[Locked]` du composant. Livewire
 *    signe deja l'instantane, mais **on ne fait pas reposer une frontiere de
 *    tenant sur une signature** : chaque identifiant est REJOUE contre la
 *    garde de sa page.
 *
 * Ce rejeu n'est pas une seconde autorite : c'est litteralement la meme regle,
 * dans le meme ordre, que le controleur de la page correspondante —
 * `LoopController::show()`, `DossierPolicy::view()`,
 * `BlogController::show()` + son garde de Manifeste prive (T1079). Il ne peut
 * qu'etre plus restrictif que la page, jamais plus permissif : quand il doute,
 * le contexte retombe a `other` et le Shell ne montre rien.
 *
 * Note de perimetre assumee : sur la carte de PRESENTATION d'une Boucle (non
 * membre, Boucle non primaire), le contexte retombe volontairement a `other`.
 * La page se rend, mais l'utilisateur n'a pas l'espace de travail — le Shell
 * prefere ne rien nommer plutot que de nommer un objet dont il ne pourrait
 * rien faire.
 */
final class AiShellPageContext
{
    public const KIND_LOOP = 'loop';

    public const KIND_DOSSIER = 'dossier';

    public const KIND_ARTICLE = 'article';

    public const KIND_DASHBOARD = 'dashboard';

    public const KIND_OTHER = 'other';

    /**
     * Le contexte de la page en cours de rendu.
     *
     * @return array<string, mixed>
     */
    public function forRequest(Request $request, User $user, Organization $organization): array
    {
        $routeName = (string) ($request->route()?->getName() ?? '');

        [$kind, $objectId] = $this->routeSubject($request, $routeName);

        return $this->resolve($user, $organization, $kind, $objectId, $routeName);
    }

    /**
     * Le contexte reconstitue a partir d'un couple (kind, id) — chemin Livewire.
     *
     * Chaque branche rejoue la garde de la page. Un identifiant qui ne passe
     * pas rend le contexte `other` : aucun nom, aucune URL, aucune action.
     *
     * @return array<string, mixed>
     */
    public function resolve(
        User $user,
        Organization $organization,
        ?string $kind,
        ?string $objectId,
        string $routeName = '',
    ): array {
        $object = null;

        if ($kind !== null && $objectId !== null && $objectId !== '') {
            $object = match ($kind) {
                self::KIND_LOOP => $this->loopSubject($organization, $user, $objectId),
                self::KIND_DOSSIER => $this->dossierSubject($organization, $user, $objectId),
                self::KIND_ARTICLE => $this->articleSubject($organization, $user, $objectId),
                default => null,
            };
        }

        $resolvedKind = $object['type'] ?? ($kind === self::KIND_DASHBOARD ? self::KIND_DASHBOARD : self::KIND_OTHER);

        // La page portait un objet, et cet objet n'a pas passe sa garde : nous
        // sommes sur un REFUS. Ce n'est pas la meme chose qu'une page sans
        // objet, et le Shell doit pouvoir faire la difference — voir
        // `AiFabContext::shouldMountShell()`.
        $refused = $object === null
            && $objectId !== null
            && $objectId !== ''
            && in_array($kind, [self::KIND_LOOP, self::KIND_DOSSIER, self::KIND_ARTICLE], true);

        return [
            'refused' => $refused,
            'organization' => [
                'id' => (string) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
            ],
            'route' => $routeName,
            'kind' => $resolvedKind,
            'object' => $object,
            'label' => $this->label($resolvedKind, $object, $organization),
        ];
    }

    /**
     * Ce que la ROUTE courante porte — rien de plus. Une route qui ne porte pas
     * d'objet gouverne n'en invente pas un.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function routeSubject(Request $request, string $routeName): array
    {
        if (in_array($routeName, ['loops.show', 'organization.loops.show'], true)) {
            $loop = $request->route('loop');

            return [self::KIND_LOOP, $loop instanceof Loop ? (string) $loop->id : (is_string($loop) ? $loop : null)];
        }

        if (in_array($routeName, ['dossiers.show', 'organization.dossiers.show'], true)) {
            $dossier = $request->route('dossier');

            return [self::KIND_DOSSIER, $dossier instanceof Dossier ? (string) $dossier->id : (is_string($dossier) ? $dossier : null)];
        }

        if (in_array($routeName, ['blog.show', 'organization.blog.show'], true)) {
            $post = $request->route('post');

            return [self::KIND_ARTICLE, $post instanceof BlogPost ? (string) $post->id : (is_string($post) ? $post : null)];
        }

        if (in_array($routeName, ['dashboard', 'organization.dashboard'], true)) {
            return [self::KIND_DASHBOARD, null];
        }

        return [null, null];
    }

    /**
     * Meme regle que `LoopController::show()` : meme Organization, et membre
     * ACTIF ou Boucle primaire de l'Organization.
     *
     * @return array<string, mixed>|null
     */
    private function loopSubject(Organization $organization, User $user, string $loopId): ?array
    {
        $loop = Loop::query()->find($loopId);

        if (! $loop instanceof Loop || (string) $loop->organization_id !== (string) $organization->id) {
            return null;
        }

        $isMember = LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember && (string) $organization->primary_loop_id !== (string) $loop->id) {
            return null;
        }

        return [
            'type' => self::KIND_LOOP,
            'id' => (string) $loop->id,
            'label' => (string) $loop->name,
            'url' => $this->url($organization, 'loops.show', ['loop' => $loop->id]),
        ];
    }

    /**
     * Meme regle que la vue Dossier : `DossierPolicy::view()`, qui porte deja
     * la frontiere de tenant et la remontee vers le Dossier gouvernant.
     *
     * @return array<string, mixed>|null
     */
    private function dossierSubject(Organization $organization, User $user, string $dossierId): ?array
    {
        $dossier = Dossier::query()->find($dossierId);

        if (! $dossier instanceof Dossier || $user->cannot('view', $dossier)) {
            return null;
        }

        return [
            'type' => self::KIND_DOSSIER,
            'id' => (string) $dossier->id,
            'label' => (string) $dossier->name,
            'url' => $this->url($organization, 'dossiers.show', ['dossier' => $dossier->id]),
        ];
    }

    /**
     * Memes trois conditions que `BlogController::show()`, dans le meme ordre :
     * meme Organization ; publie, ou auteur / co-auteur / super-admin ; et le
     * garde de Manifeste de Boucle privee (T1079), sans lequel l'URL directe
     * contourne la confidentialite de la Boucle.
     *
     * @return array<string, mixed>|null
     */
    private function articleSubject(Organization $organization, User $user, string $postId): ?array
    {
        $post = BlogPost::query()->find($postId);

        if (! $post instanceof BlogPost || (string) $post->organization_id !== (string) $organization->id) {
            return null;
        }

        if ($post->status !== 'published'
            && (string) $post->user_id !== (string) $user->id
            && ! $user->is_admin
            && ! $post->coAuthors()->where('user_id', $user->id)->exists()) {
            return null;
        }

        $privateManifestoLoop = Loop::query()
            ->where('manifesto_blog_post_id', $post->id)
            ->where('visibility', 'private')
            ->first();

        if ($privateManifestoLoop instanceof Loop && ! $user->is_admin) {
            $isMember = LoopMember::query()
                ->where('loop_id', $privateManifestoLoop->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if (! $isMember) {
                return null;
            }
        }

        return [
            'type' => self::KIND_ARTICLE,
            'id' => (string) $post->id,
            'label' => (string) $post->title,
            'url' => $this->url($organization, 'blog.show', ['post' => $post->slug]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function label(string $kind, ?array $object, Organization $organization): string
    {
        return match ($kind) {
            self::KIND_LOOP => __('ai.shell_context_loop', ['name' => $object['label'] ?? '']),
            self::KIND_DOSSIER => __('ai.shell_context_dossier', ['name' => $object['label'] ?? '']),
            self::KIND_ARTICLE => __('ai.shell_context_article', ['name' => $object['label'] ?? '']),
            self::KIND_DASHBOARD => __('ai.shell_context_dashboard', ['name' => $organization->name]),
            default => __('ai.shell_context_organization', ['name' => $organization->name]),
        };
    }

    /**
     * L'URL dans le prefixe d'Organization quand il existe — meme regle que
     * les autres liens du FAB.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(Organization $organization, string $name, array $parameters): string
    {
        $prefixed = 'organization.'.$name;

        if (Route::has($prefixed)) {
            return route($prefixed, array_merge(['organization' => $organization->slug], $parameters));
        }

        // `dossiers.show` n'existe QUE prefixe : une URL vide vaut mieux qu'une
        // exception de generation dans un layout rendu sur toutes les pages.
        return Route::has($name) ? route($name, $parameters) : '';
    }
}
