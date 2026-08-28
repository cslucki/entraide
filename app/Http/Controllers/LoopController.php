<?php

namespace App\Http\Controllers;

use App\Models\AiConfig;
use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\User;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopGovernanceService;
use App\Services\LoopMessageService;
use App\Services\Loops\LoopLifecycleService;
use App\Services\Loops\LoopPresetConfigurator;
use App\Services\LoopService;
use App\Support\Ai\AiRefusedException;
use App\Support\Loops\HelpRequestHandoff;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;
use App\Support\Tenancy\CurrentOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoopController extends Controller
{
    /**
     * Combien d'outils la barre de ChatLoop montre directement.
     *
     * **Ce n'est pas la regle des outils mis en avant** (trois au plus,
     * TASK-1124), qui vit dans `LoopCardCompositionService::MAX_PRIMARY` et
     * n'a pas bouge. Ici on ne parle que de place a l'ecran.
     */
    private const TOOLBAR_VISIBLE = 5;

    public function __construct(
        private readonly LoopService $loopService,
        private readonly LoopMessageService $loopMessageService,
        private readonly ChatLoopAiService $chatLoopAiService,
        private readonly AiProvider $aiProvider,
        private readonly HelpRequestHandoff $helpRequestHandoff,
    ) {}

    private function resolveOrganization(): Organization
    {
        $organization = CurrentOrganization::get();

        if ($organization) {
            assert($organization instanceof Organization);

            return $organization;
        }

        $user = auth()->user();

        if (! $user->organization) {
            abort(404);
        }

        assert($user->organization instanceof Organization);

        return $user->organization;
    }

    private function assertUserBelongsToOrganization(Organization $organization): void
    {
        $user = auth()->user();

        $orgId = $user->organization_id;
        if ($orgId !== $organization->id) {
            abort(404);
        }
    }

    private function resolveOrganizationId(): string
    {
        $organizationId = CurrentOrganization::id();

        if ($organizationId) {
            return $organizationId;
        }

        $user = auth()->user();

        if ($orgId = $user->organization_id) {
            return $orgId;
        }

        abort(403);
    }

    /**
     * La seule Boucle qu'une valeur venue du navigateur ou d'un provider peut
     * designer : active, dans cette Organization, et dont l'utilisateur est
     * membre actif. Tout le reste vaut null — y compris un identifiant qui
     * n'est pas un UUID, comme en produisent les scenarios du FakeAIProvider.
     * Le garde `isUuid` n'est pas cosmetique : comparer une chaine arbitraire
     * a une colonne uuid leve une exception sur PostgreSQL.
     */
    private function publishableLoopOrNull(mixed $loopId, Organization $organization, User $user): ?Loop
    {
        if (! is_string($loopId) || ! Str::isUuid($loopId)) {
            return null;
        }

        return Loop::query()
            ->where('id', $loopId)
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id)->where('status', 'active'))
            ->first();
    }

    /**
     * Une suggestion n'atteint l'ecran que si elle designe une Boucle que
     * l'utilisateur peut reellement utiliser. Le libelle est relu en base :
     * l'IA propose un identifiant, jamais un nom qui fait autorite.
     *
     * TASK-1321 : c'est le DERNIER point de validation avant l'ecran et le
     * handoff — le point qui compte le plus. `provenance.verified` n'est
     * JAMAIS repris tel quel de la couche precedente (elle pourrait avoir ete
     * produite par le repli deterministe `FakeAIProvider`, dont les Boucles
     * de scenario sont fictives) : il est reconstruit ici a partir du seul
     * fait que cette methode vient elle-meme de confirmer,
     * `publishableLoopOrNull()` = appartenance active reelle. Seul
     * `provenance.ai_wording` (texte du modele, jamais une preuve) traverse
     * tel quel depuis la couche precedente, toujours marque `verified: false`.
     *
     * @return array{id: string, label: string, provenance: array{verified: list<array{type: string, loop_id: string}>, ai_wording: array{text: string, verified: false}|null}}|null
     */
    private function validatedSuggestedLoopFor(mixed $suggested, Organization $organization, User $user): ?array
    {
        if (! is_array($suggested)) {
            return null;
        }

        $loop = $this->publishableLoopOrNull($suggested['id'] ?? null, $organization, $user);

        if ($loop === null) {
            return null;
        }

        $aiWordingText = trim((string) ($suggested['provenance']['ai_wording']['text'] ?? ''));

        return [
            'id' => $loop->id,
            'label' => $loop->name,
            'provenance' => [
                'verified' => [
                    ['type' => 'active_membership', 'loop_id' => $loop->id],
                ],
                'ai_wording' => $aiWordingText !== '' ? ['text' => $aiWordingText, 'verified' => false] : null,
            ],
        ];
    }

    /**
     * L'URL d'une page de Boucle, sur la surface d'ou l'on vient.
     *
     * Surface org-scoped (`/org/{organization}/...`) : l'Organization de la
     * requete. Surface courte (`/loops`, `/loops/{loop}/...`) : la route courte,
     * inchangee. Routes plates (`/join-requests/{joinRequest}/accept`,
     * `/loop-members/{member}/role`...) : la requete ne porte ni segment
     * `{organization}` ni segment `loops`, elle ne dit donc pas d'ou l'on vient
     * — mais la Boucle, elle, appartient a exactement une Organization. Avant
     * TASK-1277 ces routes retombaient sur la route courte, qui resout `main` :
     * accepter, refuser ou annuler une demande d'adhesion depuis
     * `/org/{slug}/loops/{loop}` finissait en 404 sur `/loops/{loop}`.
     *
     * Le discriminant est l'URI de la route, pas son nom : `loops.members.role`
     * est nommee sous `loops.` mais vit sur `/loop-members/...`, elle est plate.
     */
    private function loopRoute(string $route, Loop $loop): string
    {
        $organization = request()->route('organization');

        if ($organization && request()->routeIs('organization.*')) {
            return route('organization.'.$route, [
                'organization' => $organization,
                'loop' => $loop,
            ]);
        }

        $uri = request()->route()?->uri() ?? '';

        if ($uri === 'loops' || str_starts_with($uri, 'loops/')) {
            return route($route, $loop);
        }

        return route('organization.'.$route, [
            'organization' => $loop->organization,
            'loop' => $loop,
        ]);
    }

    private function resolveRouteLoop(Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): Loop
    {
        if ($loopOrOrganization instanceof Loop) {
            return $loopOrOrganization;
        }

        if ($loop instanceof Loop) {
            return $loop;
        }

        abort(404);
    }

    public function index(): View|RedirectResponse
    {
        $organizationId = $this->resolveOrganizationId();
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        $user = auth()->user();

        // Mono-loop mode: redirect to primary loop if defined
        if ($organization->isMonoLoop()) {
            if ($organization->primary_loop_id) {
                $primaryLoop = $organization->primaryLoop;

                if ($primaryLoop && $primaryLoop->organization_id === $organization->id) {
                    return redirect($this->loopRoute('loops.show', $primaryLoop));
                }
            }

            return view('loops.index', [
                'loops' => collect(),
                'canCreate' => false,
                'noPrimaryLoopWarning' => true,
                // Meme sans Boucle a lister, la vue construit ses onglets de
                // type : elle a besoin de la portee pour les nommer.
                'organization' => $organization,
            ]);
        }

        // Multi-loop mode: show list, redirect if single accessible loop
        $loops = $this->getAccessibleLoopsQuery($organizationId, $user)->get();

        // Les Boucles archivees, pour ceux qui ont le droit de les revoir. Elles
        // ne sont pas melees aux actives : l'archive est une seconde liste, pas
        // une ligne grisee au milieu du catalogue.
        $archivedLoops = $this->archivedLoopsFor($organizationId, $user);

        if ($loops->count() === 1 && $archivedLoops->isEmpty()) {
            return redirect($this->loopRoute('loops.show', $loops->first()));
        }

        $canCreate = $user->can('create', [Loop::class, $organization]);

        // Only the domains actually used by the listed Loops: a filter offering
        // empty options would be noise on a ~200-people Organization.
        $filterDomains = $loops->pluck('categories')->flatten()->unique('id')
            ->sortBy(fn ($c) => $c->displayName('loops'))->values();

        // `organization` part avec le reste : les onglets de type se nomment
        // **dans la portee**, et un type cree par cette Organization doit y
        // avoir son onglet — `all()` sans portee ne le connait pas.
        return view('loops.index', compact('loops', 'filterDomains', 'archivedLoops', 'organization'))
            ->with('canCreate', $canCreate);
    }

    /**
     * Les Boucles archivees que cette personne peut encore consulter.
     *
     * `loops.archive` sert de critere : qui peut archiver peut relire ce qu'il a
     * archive. Un membre simple n'en voit aucune — pour lui, la Boucle a
     * simplement quitte la liste.
     *
     * @return \Illuminate\Support\Collection<int, Loop>
     */
    private function archivedLoopsFor(string $organizationId, $user): \Illuminate\Support\Collection
    {
        $resolver = app(LoopPermissionResolver::class);

        return Loop::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'archived')
            ->with('owners.user')
            ->withCount('activeMembers')
            ->latest('archived_at')
            ->get()
            ->filter(fn (Loop $loop) => $resolver->can($user, $loop, 'loops.archive'))
            ->values();
    }

    /**
     * The catalog: every active Loop of the Organization is discoverable,
     * whether the user is a member or not (TASK-1075 — "privée" no longer
     * means "hidden", it means "content locked to members"). Membership and
     * pending-request state are annotated via correlated EXISTS subqueries
     * so the view can render the right CTA without N+1 queries.
     */
    private function getAccessibleLoopsQuery(string $organizationId, $user)
    {
        return Loop::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            // `organization` : le libelle d'un type peut etre surcharge par
            // locataire, et la carte de catalogue le lit. Charge ici, une fois,
            // plutot qu'une requete par Boucle dans la vue.
            ->with(['owner.user', 'owners.user', 'categories', 'organization'])
            ->withCount('activeMembers')
            ->withMax('messages as last_message_at', 'created_at')
            ->withExists(['members as is_member' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', 'active');
            }])
            ->withExists(['members as is_owner' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', 'active')->where('role', 'owner');
            }])
            ->withExists(['joinRequests as has_pending_request' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', LoopJoinRequest::STATUS_PENDING);
            }])
            ->latest('updated_at');
    }

    public function create(): View
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);
        $this->authorize('create', [Loop::class, $organization]);

        return view('loops.create', [
            'domains' => $this->organizationDomains($organization),
            'organization' => $organization,
            // Les types **ouverts a cette Organization** : un type ouvert pour
            // elle seule doit apparaitre, un type ferme chez elle doit
            // disparaitre.
            'loopTypes' => app(LoopTypeRegistry::class)->available($organization),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);
        $this->authorize('create', [Loop::class, $organization]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'tagline' => 'nullable|string|max:140',
            'description' => 'nullable|string|max:5000',
            'access_mode' => ['nullable', Rule::in(Loop::ACCESS_MODES)],
            'cover_image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'category_ids' => 'nullable|array|max:'.Loop::MAX_DOMAINS,
            'category_ids.*' => 'string',
            'type' => ['nullable', Rule::in(app(LoopTypeRegistry::class)->availableKeys())],
        ]);

        $loop = $this->loopService->createLoop(
            $request->user(),
            $data['name'],
            $data['description'] ?? null,
            'private',
            $data['tagline'] ?? null,
            $data['access_mode'] ?? Loop::ACCESS_REQUEST,
            null,
            $data['type'] ?? null,
        );

        $loop->categories()->sync($this->resolveDomainIds($organization, $data['category_ids'] ?? []));

        if ($request->hasFile('cover_image')) {
            $loop->update(['cover_image_path' => $this->storeCoverImage($request, $loop)]);
        }

        // TASK-1076: optional post-creation step. Never blocking — the screen
        // offers "Plus tard" and "Ouvrir la Boucle".
        return redirect($this->loopRoute('loops.invite', $loop))
            ->with('success', __('loops.created_success'));
    }

    public function edit(Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): View
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $loop);

        $loop->load('categories');

        return view('loops.edit', [
            'loop' => $loop,
            'domains' => $this->organizationDomains($organization),
        ]);
    }

    public function update(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $loop);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'tagline' => 'nullable|string|max:140',
            'description' => 'nullable|string|max:5000',
            'access_mode' => ['nullable', Rule::in(Loop::ACCESS_MODES)],
            'cover_image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'remove_cover_image' => 'nullable|boolean',
            'category_ids' => 'nullable|array|max:'.Loop::MAX_DOMAINS,
            'category_ids.*' => 'string',
        ]);

        if ($request->boolean('remove_cover_image') && $loop->cover_image_path) {
            $this->deleteCoverImage($loop->cover_image_path);
            $data['cover_image_path'] = null;
        } elseif ($request->hasFile('cover_image')) {
            $data['cover_image_path'] = $this->storeCoverImage($request, $loop);
        }

        $loop->categories()->sync($this->resolveDomainIds($organization, $data['category_ids'] ?? []));

        unset($data['cover_image'], $data['remove_cover_image'], $data['category_ids']);

        $this->loopService->updateLoop($loop, $data);

        // TASK-1076: editing is started from the catalog, so we come back to it
        // (with ?updated=<id> to highlight the card) instead of dropping the user
        // into the workspace. The AdminLoopController flow is untouched.
        return redirect($this->loopsIndexRoute(['updated' => $loop->id]))
            ->with('success', __('loops.catalog_updated'));
    }

    /**
     * Optional "invite people" step shown right after a Loop is created.
     * Restricted to whoever may manage the Loop (owner / Organization admin) —
     * a standard member never reaches it.
     */
    public function invite(Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): View
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('addMembers', $loop);

        return view('loops.invite', [
            'loop' => $loop,
            'candidates' => $this->loopService->invitableOrganizationMembers($loop),
            'pendingInvitations' => $this->loopInvitationsFor($loop),
        ]);
    }

    /**
     * E-mail invitations of this Loop, through the shared visibility scope so
     * this screen, the Members Card and the Organization tracking page always
     * agree on who sees what and on what a revocation looks like.
     *
     * @return Collection<int, LoopInvitation>
     */
    private function loopInvitationsFor(Loop $loop): Collection
    {
        return LoopInvitation::visibleTo(auth()->user())
            ->where('loop_id', $loop->id)
            ->latest()
            ->get();
    }

    public function storeMembers(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('addMembers', $loop);

        $data = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'string',
        ]);

        // Re-scope server-side: only active users of THIS Organization, whatever
        // the form posted.
        $userIds = $this->loopService->invitableOrganizationMembers($loop)
            ->whereIn('id', $data['user_ids'])
            ->pluck('id')
            ->all();

        $result = $this->loopService->addMembersFromOrganization($loop, $userIds, $request->user());

        return redirect($this->loopRoute('loops.invite', $loop))
            ->with('success', trans_choice('loops.invite_members_added', $result['added'], ['count' => $result['added']]));
    }

    /** Active Organization members who are not active members of this Loop yet. */

    /** Domains (Annuaire referential) selectable for this Organization. */
    private function organizationDomains(Organization $organization)
    {
        return Category::where('organization_id', $organization->id)
            ->orderBy('name_b2c')
            ->get();
    }

    /**
     * Keep only domain ids that really belong to this Organization, capped at
     * MAX_DOMAINS. Tenant scoping is enforced here, server-side: a forged
     * category_ids payload pointing at another Organization is silently dropped
     * rather than trusted from the form.
     */
    private function resolveDomainIds(Organization $organization, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Category::where('organization_id', $organization->id)
            ->whereIn('id', $ids)
            ->limit(Loop::MAX_DOMAINS)
            ->pluck('id')
            ->all();
    }

    /**
     * URL of the Loop catalog, preserving the organization-prefixed route group
     * when the current request is inside it.
     */
    private function loopsIndexRoute(array $query = []): string
    {
        $organization = request()->route('organization');

        if ($organization && request()->routeIs('organization.*') && Route::has('organization.loops.index')) {
            return route('organization.loops.index', array_merge(['organization' => $organization], $query));
        }

        return route('loops.index', $query);
    }

    private function storeCoverImage(Request $request, Loop $loop): string
    {
        if ($loop->cover_image_path) {
            $this->deleteCoverImage($loop->cover_image_path);
        }

        $filename = Str::random(32).'.'.$request->file('cover_image')->extension();

        return $request->file('cover_image')->storeAs(
            'loop-covers/'.$loop->id,
            $filename,
            'public',
        );
    }

    private function deleteCoverImage(string $path): void
    {
        if (str_starts_with($path, 'loop-covers/') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public function show(Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): View
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = auth()->user();

        $isMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        $isPrimaryLoop = $organization->primary_loop_id === $loop->id;

        if (! $isMember && ! $isPrimaryLoop) {
            // TASK-1075: "privée" no longer means hidden from non-members —
            // it means the workspace content is locked. Every non-member
            // sees the presentation card instead of a hard 404, except for
            // archived Loops which are never independently discoverable.
            if (! $loop->isActive()) {
                abort(404);
            }

            return $this->showPresentation($loop, $user);
        }

        $loop->load(['members.user']);

        $eligibleReferrals = $this->loopService->getEligibleReferrals($user, $loop);

        $clarificationEnabled = AiConfig::get('clarification_enabled', false);

        // TASK-1211 : la clarification deposee par « Qui peut m'aider ? » est
        // lue ICI, une seule fois, par l'ecran qui l'affiche — jamais via un
        // flash de session, que le poll de ChatLoop consommerait avant lui.
        $helpRequest = $this->helpRequestHandoff->pull($user, $loop);

        $canManageJoinRequests = $user->can('manageJoinRequests', $loop);
        $pendingJoinRequests = $canManageJoinRequests
            ? LoopJoinRequest::where('loop_id', $loop->id)
                ->where('status', LoopJoinRequest::STATUS_PENDING)
                ->with('user')
                ->oldest()
                ->get()
            : collect();

        $loopInvitations = $canManageJoinRequests ? $this->loopInvitationsFor($loop) : collect();

        // Governance controls follow the resolved permissions, never a role
        // label read in Blade (TASK-1079 CP5ter).
        $resolver = app(LoopPermissionResolver::class);
        $governance = [
            'owners' => $resolver->can($user, $loop, 'loops.manage_owners'),
            'facilitators' => $resolver->can($user, $loop, 'loops.manage_facilitators'),
            'remove' => $resolver->can($user, $loop, 'loop_members.remove'),
        ];

        return view('loops.show', compact(
            'loop', 'eligibleReferrals', 'isMember', 'clarificationEnabled',
            'canManageJoinRequests', 'pendingJoinRequests', 'loopInvitations', 'governance',
        ) + [
            'helpRequestAnalysis' => $helpRequest['analysis'] ?? null,
            'helpRequestIntention' => $helpRequest['intention'] ?? null,
            // TASK-1210 : les Boucles ou l'utilisateur peut publier une demande.
            // Meme perimetre que la source `user.loops` du Context Builder —
            // membre actif, Boucle active, Organization courante — pour que le
            // selecteur n'offre jamais plus que ce que l'IA a pu voir.
            'publishableLoops' => $isMember
                ? Loop::query()
                    ->where('organization_id', $loop->organization_id)
                    ->where('status', 'active')
                    ->whereHas('members', fn ($q) => $q->where('user_id', $user->id)->where('status', 'active'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
            'workspaceCards' => $this->workspaceCardsFor($loop, $user),
            // Les outils **mis en avant** et les autres (TASK-1124). La barre
            // montrait `take(3)` : la 4e Card active etait introuvable. Les
            // deux collections sortent du meme ensemble visible, donc rien ne
            // peut apparaitre ici qui ne soit pas dans `workspaceCards`.
            'primaryCards' => $this->workspaceCardsFor($loop, $user, 'primary'),
            'secondaryCards' => $this->workspaceCardsFor($loop, $user, 'secondary'),
            // La barre : ce qui tient devant, et ce qui deborde. **Aucune
            // regle metier ici** — `primary_rank` n'est ni lu autrement ni
            // ecrit. On decoupe simplement la meme liste (mis en avant
            // d'abord, puis les autres actifs) a la limite d'affichage.
            ...$this->toolbarSplit($loop, $user),
            // Le cadre permanent et les actions IA de ChatLoop : declares dans
            // le meme registre, mais rendus ailleurs qu'en grille (TASK-1090).
            'frameCards' => $this->placedCardsFor($loop, $user, 'frame'),
            'chatActionCards' => $this->placedCardsFor($loop, $user, 'chat'),
            // Le cycle de vie : qui peut archiver, et ce que l'archivage
            // toucherait. Calcule ici pour que la modale annonce des chiffres
            // reels plutot qu'une formule vague.
            'canArchiveLoop' => app(LoopLifecycleService::class)->canArchive($user, $loop),
            // « Personnaliser ma Boucle » : la capacite **reelle**, celle que
            // le service appliquera — proprietaire autorise par son
            // Organization, ou administrateur — et jamais sur une archivee.
            'canCustomiseTools' => ! $loop->isArchived()
                && app(LoopPresetConfigurator::class)->canConfigure($user, $loop),
            'archiveImpact' => app(LoopLifecycleService::class)->impactOf($loop),
            // La vue rend chaque Card depuis le registre : plus aucune condition
            // sur une cle de Card dans le Blade.
            'cardRegistry' => app(LoopCardRegistry::class),
        ]);
    }

    /**
     * Ce que la barre d'outils montre, et ce qu'elle renvoie au debordement.
     *
     * **Cinq outils accessibles directement** (TASK-1128) : les mis en avant
     * d'abord, puis les autres outils actifs, dans l'ordre. Au-dela, le reste
     * passe au debordement — le meme deplie qu'avant, qui n'apparait plus du
     * tout quand tout tient.
     *
     * **Rien de metier ne se decide ici, et c'est le point important.** Cinq
     * outils *visibles* n'est pas cinq outils *mis en avant* : la regle de
     * TASK-1124 reste a trois, `primary_rank` n'est ni lu autrement ni ecrit,
     * et aucune migration n'accompagne ce changement. La limite est un choix
     * d'affichage, rien d'autre.
     *
     * @return array{toolbarCards: \Illuminate\Support\Collection<int, array<string, mixed>>, toolbarOverflow: \Illuminate\Support\Collection<int, array<string, mixed>>}
     */
    private function toolbarSplit(Loop $loop, User $user): array
    {
        $ordonnes = $this->workspaceCardsFor($loop, $user, 'primary')
            ->concat($this->workspaceCardsFor($loop, $user, 'secondary'))
            ->values();

        return [
            'toolbarCards' => $ordonnes->take(self::TOOLBAR_VISIBLE)->values(),
            'toolbarOverflow' => $ordonnes->slice(self::TOOLBAR_VISIBLE)->values(),
        ];
    }

    /**
     * The cards a given user actually sees in a given Loop's workspace.
     *
     * The three filters — composition, renderer, read permission — now live in
     * LoopCardRegistry, with the catalogue that declares them. This method keeps
     * only what is genuinely about *this screen*: who is entitled to see a
     * workspace at all.
     *
     * Read-only: nothing here writes to loop_cards.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function workspaceCardsFor(Loop $loop, User $user, string $subset = 'all'): \Illuminate\Support\Collection
    {
        // A non-member never reaches the workspace cards; a super-admin does,
        // matching the previous behaviour of this screen.
        $isActiveMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isActiveMember && ! $user->is_admin) {
            return collect();
        }

        $registry = app(LoopCardRegistry::class);

        return match ($subset) {
            'primary' => $registry->primaryWorkspaceCardsFor($loop, $user),
            'secondary' => $registry->secondaryWorkspaceCardsFor($loop, $user),
            default => $registry->workspaceCardsFor($loop, $user),
        };
    }

    /**
     * Les Cards rendues hors de la grille : cadre permanent, actions ChatLoop.
     *
     * Meme porte d'entree que la grille — un non-membre n'y accede pas — pour
     * qu'on ne puisse pas contourner la regle en passant par le cadre.
     *
     * @param  'frame'|'chat'  $placement
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function placedCardsFor(Loop $loop, User $user, string $placement): \Illuminate\Support\Collection
    {
        $isActiveMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isActiveMember && ! $user->is_admin) {
            return collect();
        }

        $registry = app(LoopCardRegistry::class);

        return $placement === 'frame'
            ? $registry->frameCardsFor($loop, $user)
            : $registry->chatActionCardsFor($loop, $user);
    }

    /**
     * Archiver sa propre Boucle, depuis le workspace.
     *
     * Distincte de update() : la garde de LoopPolicy::update refuse une Boucle
     * archivee, ce qui rendrait la reactivation inaccessible a la seule
     * personne censee pouvoir la demander. L'autorisation vient du resolveur,
     * via `loops.archive`.
     */
    public function archive(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);
        abort_if($loop->organization_id !== $organization->id, 404);

        $result = app(LoopLifecycleService::class)->archive($request->user(), $loop);

        abort_if($result === LoopLifecycleService::RESULT_DENIED, 403);

        return redirect()
            ->route('organization.loops.show', ['organization' => $organization->slug, 'loop' => $loop->id])
            ->with(
                $result === LoopLifecycleService::RESULT_OK ? 'success' : 'error',
                $result === LoopLifecycleService::RESULT_OK
                    ? __('loops.archive_done')
                    : __('loops.archive_already'),
            );
    }

    public function reactivate(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);
        abort_if($loop->organization_id !== $organization->id, 404);

        $result = app(LoopLifecycleService::class)->reactivate($request->user(), $loop);

        abort_if($result === LoopLifecycleService::RESULT_DENIED, 403);

        return redirect()
            ->route('organization.loops.show', ['organization' => $organization->slug, 'loop' => $loop->id])
            ->with(
                $result === LoopLifecycleService::RESULT_OK ? 'success' : 'error',
                $result === LoopLifecycleService::RESULT_OK
                    ? __('loops.reactivate_done')
                    : __('loops.reactivate_already'),
            );
    }

    private function showPresentation(Loop $loop, $user): View
    {
        $loop->loadCount('activeMembers');
        $loop->load(['owner.user', 'owners.user', 'categories']);

        $pendingRequest = LoopJoinRequest::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', LoopJoinRequest::STATUS_PENDING)
            ->first();

        // Resolved here rather than in the view, so a private or unpublished
        // Manifesto never reaches the response at all — not as markup, not in a
        // meta tag, not anywhere in the DOM. hasPublicManifesto() requires both
        // a non-private Loop and an explicitly published article; the Manifesto
        // is never a fallback for a missing description.
        $publicManifesto = $loop->hasPublicManifesto() ? $loop->manifesto : null;

        return view('loops.presentation', [
            'loop' => $loop,
            'pendingRequest' => $pendingRequest,
            'publicManifesto' => $publicManifesto,
        ]);
    }

    public function join(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $alreadyActive = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if ($alreadyActive) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('info', __('loops.already_member'));
        }

        // access_mode=open required — defense in depth, the button is only
        // ever shown for open Loops, but a direct POST must be refused too.
        $this->authorize('join', $loop);

        $this->loopService->joinOpenLoop($loop, $user);

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', __('loops.joined'));
    }

    public function storeJoinRequest(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('requestToJoin', $loop);

        $data = $request->validate([
            'message' => 'nullable|string|max:500',
        ]);

        try {
            $this->loopService->requestToJoin($loop, $request->user(), $data['message'] ?? null);
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', __('loops.join_request_sent'));
    }

    public function cancelJoinRequest(Request $request, LoopJoinRequest $joinRequest): RedirectResponse
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($joinRequest->organization_id !== $organization->id) {
            abort(404);
        }

        if ($joinRequest->user_id !== $request->user()->id) {
            abort(404);
        }

        $loop = $joinRequest->loop;

        try {
            $this->loopService->cancelJoinRequest($joinRequest);
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('info', __('loops.join_request_cancelled'));
    }

    public function acceptJoinRequest(Request $request, LoopJoinRequest $joinRequest): RedirectResponse
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($joinRequest->organization_id !== $organization->id) {
            abort(404);
        }

        $loop = $joinRequest->loop;

        $this->authorize('manageJoinRequests', $loop);

        try {
            $this->loopService->acceptJoinRequest($joinRequest, $request->user());
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', __('loops.join_request_accepted'));
    }

    public function rejectJoinRequest(Request $request, LoopJoinRequest $joinRequest): RedirectResponse
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($joinRequest->organization_id !== $organization->id) {
            abort(404);
        }

        $loop = $joinRequest->loop;

        $this->authorize('manageJoinRequests', $loop);

        try {
            $this->loopService->rejectJoinRequest($joinRequest, $request->user());
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('info', __('loops.join_request_rejected'));
    }

    public function leave(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $member = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $member) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('info', __('loops.not_member'));
        }

        // Through the governance service, never a direct mutation: a controller
        // writing the row itself would sidestep the last-owner invariant. An
        // owner may now leave as long as another active one remains.
        $result = app(LoopGovernanceService::class)->leave($loop, $user->id);

        if ($result === LoopGovernanceService::RESULT_LAST_OWNER) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('error', __('loops.last_owner_cannot_leave'));
        }

        $orgSlug = request()->route('organization');
        $indexRoute = $orgSlug && Route::has('organization.loops.index')
            ? route('organization.loops.index', ['organization' => $orgSlug])
            : route('loops.index');

        return redirect($indexRoute)
            ->with('success', __('loops.left'));
    }

    public function analyzeHelpIntention(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $isMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(404);
        }

        $data = $request->validate([
            'intention' => 'required|string|min:3|max:2000',
        ]);

        $clarificationEnabled = AiConfig::get('clarification_enabled', false);

        // TASK-1322 (Core-2) : l'absence d'IA ne bloque jamais le parcours.
        // Avant, ce gate redirigeait avec une erreur — une impasse. Desormais
        // il degrade : les mots du membre (jamais un contenu invente) partent
        // en brouillon vers le formulaire canonique, exactement le meme trajet
        // que « Continuer ma demande » (prepareHelpRequest). Aucun appel
        // provider, aucune publication : la creation reste un acte humain
        // explicite (RequestController::store()).
        if (! $clarificationEnabled) {
            $this->helpRequestHandoff->storeDraft($user, $organization, [
                'title' => '',
                'description' => $data['intention'],
                'relay_loop_id' => $loop->id,
                'category_id' => null,
            ]);

            return redirect()->route('organization.requests.create', [
                'organization' => $organization->slug,
            ])->with('info', __('loops.help_request_ai_unavailable'));
        }

        // TASK-1210 : la clarification se fait DANS un contexte — cet
        // utilisateur, cette Organization, ses Boucles — sans quoi l'IA ne peut
        // suggerer aucun cercle. Le service retombe seul sur la clarification
        // deterministe si l'IA est indisponible.
        //
        // TASK-1322 : une seule indisponibilite pre-appel echappait encore au
        // repli du service — aucun AdminAiPrompt actif (DomainException de
        // clarifyInstructions()), un 500 pour le membre. Ici elle degrade
        // comme les autres. Les surfaces qui veulent ce refus EXPLICITE le
        // gardent : formulate() repond 503, le Shell repond « indisponible » —
        // toutes deux catchent deja cette exception elles-memes.
        try {
            $result = $this->aiProvider instanceof ClarifyUserHelpRequestService
                ? $this->aiProvider->clarifyForLoop($loop, $user, $data['intention'])
                : $this->aiProvider->analyze($data['intention']);
        } catch (DomainException) {
            $result = $this->aiProvider->analyze($data['intention']);
        }

        if ($result->isBlocked()) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('help_request_error', $result->fallback['reason'] ?? 'Cette demande ne peut pas être publiée.');
        }

        // La suggestion est validee ICI, quel que soit le chemin qui l'a
        // produite. Le chemin SDK valide deja contre la liste offerte au
        // modele ; le repli deterministe (FakeAIProvider) renvoie lui des
        // identifiants de scenario qui ne designent aucune Boucle reelle.
        // Sans ce filtre, l'ecran collait la justification d'une Boucle
        // imaginaire sur une preselection choisie par defaut du navigateur.
        $analysis = $result->toArray();
        $analysis['suggested_loop'] = $this->validatedSuggestedLoopFor(
            $analysis['suggested_loop'] ?? null,
            $organization,
            $user,
        );

        // Hors session : entre ce POST et l'ecran redirige, ChatLoop poll et
        // un flash n'y survivrait pas (voir HelpRequestHandoff).
        $this->helpRequestHandoff->store($user, $loop, $analysis, $data['intention']);

        return redirect($this->loopRoute('loops.show', $loop));
    }

    /**
     * TASK-1213 (RAG V1) : reponse documentaire sourcee, read-only, JSON.
     * L'appartenance active a la Boucle et l'Organization sont verifiees ici et
     * a nouveau dans le service ; les sources viennent du Context Builder.
     */
    public function knowledge(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): JsonResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $isMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(404);
        }

        if (! config('ai.chatloop.enabled', true)) {
            abort(404);
        }

        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $answer = app(LoopKnowledgeAnswerService::class)->answer($loop, $user, $data['question']);
        } catch (AiRefusedException $exception) {
            // TASK-1229 : refus AVANT appel, avec son code stable (credit
            // utilisateur epuise / budget Organization atteint / IA non
            // configuree) — l'ecran choisit le bon message et, pour le credit,
            // le bouton « Voir les offres ».
            return response()->json([
                'error' => $exception->getMessage(),
                'code' => $exception->refusalCode,
                'offers_url' => $exception->offersUrl($organization),
            ], 422);
        } catch (\RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json($answer->toArray());
    }

    public function prepareHelpRequest(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $isMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(404);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'need' => ['required', 'string', 'max:2000'],
            'relay_loop_id' => ['bail', 'nullable', 'uuid'],
            'suggested_category_id' => ['bail', 'nullable', 'uuid'],
        ]);

        $relayLoopId = $data['relay_loop_id'] ?? null;
        $cible = $relayLoopId !== null
            ? $this->publishableLoopOrNull($relayLoopId, $organization, $user)
            : null;

        if ($relayLoopId !== null && $cible === null) {
            return back()
                ->withInput()
                ->with('help_request_error', __('loops.help_request_loop_invalid'));
        }

        // La categorie suggeree par l'IA voyage avec le reste, mais seulement
        // si elle appartient a cette Organization : sinon elle est simplement
        // absente et l'humain choisit dans le formulaire.
        $suggestedCategoryId = $data['suggested_category_id'] ?? null;
        $categorie = $suggestedCategoryId !== null
            ? Category::query()
                ->whereKey($suggestedCategoryId)
                ->where('organization_id', $organization->id)
                ->first(['id'])
            : null;

        // Ce clic ne publie plus rien : il transfere seulement la proposition
        // vers le vrai formulaire metier. `relay_loop_id` et `category_id`
        // restent transitoires et seront revalides une seconde fois au submit
        // qui cree la ServiceRequest. Le brouillon voyage hors session, comme
        // l'analyse : ce clic quitte une page qui poll (voir HelpRequestHandoff).
        $this->helpRequestHandoff->storeDraft($user, $organization, [
            'title' => $data['title'],
            'description' => $data['need'],
            'relay_loop_id' => $cible?->id,
            'category_id' => $categorie?->id,
        ]);

        return redirect()->route('organization.requests.create', [
            'organization' => $organization->slug,
        ]);
    }

    /**
     * Change a member's role from the workspace.
     *
     * Gated by the resolved permission, not by the actor's role label, and the
     * transition itself goes through the governance service — so the last-owner
     * invariant applies here exactly as in the admin screens.
     */
    public function updateMemberRole(Request $request, LoopMember $member): RedirectResponse
    {
        // The Loop comes from the membership's own relation, not from a route
        // segment — see the routing comment.
        $loop = $member->loop;
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        abort_if($loop === null || $loop->organization_id !== $organization->id, 404);

        $targetRole = (string) $request->input('role');
        $resolver = app(LoopPermissionResolver::class);

        // Touching an owner — in either direction — requires the owner
        // permission; facilitator transitions require the facilitator one.
        $needsOwnerRight = $targetRole === 'owner'
            || app(LoopRoleRegistry::class)->canonical($member->role) === 'owner';

        $permission = $needsOwnerRight ? 'loops.manage_owners' : 'loops.manage_facilitators';

        abort_unless($resolver->can($request->user(), $loop, $permission), 403);

        $result = app(LoopGovernanceService::class)->changeRole($member, $targetRole);

        return redirect($this->loopRoute('loops.show', $loop))->with(
            $result === LoopGovernanceService::RESULT_OK ? 'success' : 'error',
            match ($result) {
                LoopGovernanceService::RESULT_OK => __('loops.governance_changed'),
                LoopGovernanceService::RESULT_LAST_OWNER => __('loops.governance_refused_last_owner'),
                default => __('loops.governance_refused'),
            },
        );
    }

    public function addMember(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $currentMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $currentMember || ! in_array($currentMember->role, ['owner', 'moderator'])) {
            abort(404);
        }

        $data = $request->validate([
            'referral_id' => 'required|string|exists:referrals,id',
        ]);

        $referral = Referral::findOrFail($data['referral_id']);

        try {
            $this->loopService->addReferralToLoop($loop, $user, $referral);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', 'Membre ajouté à la boucle.');
    }

    public function storeMessage(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        try {
            $this->loopMessageService->sendUserMessage(
                $loop,
                $request->user(),
                $data['body'],
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', 'Message envoyé.');
    }

    public function askAi(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $request->validate([
            'action' => 'required|string|in:answer,ask',
            'question' => 'required_if:action,ask|string|max:500',
        ]);

        if (! config('ai.chatloop.enabled', true)) {
            abort(404);
        }

        try {
            if ($data['action'] === 'ask') {
                $this->chatLoopAiService->ask($loop, $request->user(), trim((string) ($data['question'] ?? '')));
            } else {
                $this->chatLoopAiService->answer($loop, $request->user());
            }
        } catch (AiRefusedException $e) {
            // TASK-1231 (lot 0) : le refus de la garde porte son code et, si le
            // credit personnel est epuise ET que la plateforme le propose, la
            // porte de sortie « Voir les offres » — la meme que les trois
            // surfaces de la 1229. Rien de neuf : offersUrl() decide.
            $redirect = redirect($this->loopRoute('loops.show', $loop))
                ->with('error', $e->getMessage())
                ->with('ai_refusal_code', $e->refusalCode);

            if (($offersUrl = $e->offersUrl($organization)) !== null) {
                $redirect->with('ai_offers_url', $offersUrl);
            }

            return $redirect;
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', $data['action'] === 'ask' ? __('loops.ai_question_requested') : __('loops.ai_answer_requested'));
    }
}
