<?php

namespace App\Http\Controllers;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Services\Blog\BlogHtmlSanitizer;
use App\Models\BlogSnapshot;
use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Tag;
use App\Services\BlogAiService;
use App\Support\Ai\AiRefusedException;
use App\Support\Blog\LoopArticleLinks;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BlogController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('throttle:10,1', only: ['uploadImage']),
            new Middleware('throttle:30,1', only: ['aiGenerate', 'aiCorrect', 'aiMethodSelection', 'orgAiMethodSelection']),
        ];
    }

    /**
     * TASK-1785 — ces deux listes ne sont plus DEFINIES ici : elles POINTENT
     * vers `BlogHtmlSanitizer`, ou la sanitation vit desormais.
     *
     * Pourquoi les garder malgre tout : `TASK1084ArticleEditorTest:242` lit
     * `BlogController::ALLOWED_HTML_TAGS` PAR REFLEXION, et les supprimer
     * aurait fait rougir ce test sans rien gagner.
     *
     * Pourquoi POINTER plutot que recopier : deux listes recopiees finissent
     * par diverger, et c'est la plus permissive qui decide de ce qui entre en
     * base. Une seule source, deux noms.
     *
     * ⚠️ `BlogSnapshotController:13,23` porte, lui, ses PROPRES copies des deux
     * listes. Cette duplication PREEXISTE a TASK-1785 et n'est pas traitee ici
     * — signalee a MASTER a part.
     */
    private const ALLOWED_HTML_TAGS = BlogHtmlSanitizer::ALLOWED_HTML_TAGS;

    private const ALLOWED_IFRAME_DOMAINS = BlogHtmlSanitizer::ALLOWED_IFRAME_DOMAINS;

    public function index(): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->assertOrganizationBlogIsReadable($organization);

        $recentPosts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->with(['user', 'category', 'tags'])
            ->withCount(['comments', 'likes'])
            ->latest('published_at')
            ->paginate(12);

        $popularPosts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->with('user')
            ->orderByDesc('views_count')
            ->limit(5)
            ->get();

        $categories = Category::where('organization_id', $organization->id)->withCount([
            'blogPosts' => fn ($q) => $q->published()->where('blog_posts.organization_id', $organization->id),
        ])->get();

        $popularTags = Tag::withCount([
            'blogPosts' => fn ($q) => $q->published()->where('blog_posts.organization_id', $organization->id),
        ])
            ->orderByDesc('blog_posts_count')
            ->limit(30)
            ->get()
            ->filter(fn ($t) => $t->blog_posts_count > 0)
            ->take(20);

        // TASK-1827 — carte par carte serait N+1 sur une page paginee.
        // Vide pour un visiteur sans chemin existant vers le catalogue des
        // Boucles (voir le docblock de `LoopArticleLinks`).
        $linkedLoopsByPost = $this->viewerMaySeeLoopLinks($organization)
            ? app(LoopArticleLinks::class)->forPosts($recentPosts->getCollection(), $organization)
            : collect();

        return view('blog.index', compact('recentPosts', 'popularPosts', 'categories', 'popularTags', 'linkedLoopsByPost'));
    }

    public function byCategory(string $slug): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->assertOrganizationBlogIsReadable($organization);

        $category = Category::where('slug', $slug)->where('organization_id', $organization->id)->firstOrFail();

        $posts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->with(['user', 'category', 'tags'])
            ->withCount(['comments', 'likes'])
            ->where('category_id', $category->id)
            ->latest('published_at')
            ->paginate(12);

        return view('blog.category', compact('category', 'posts'));
    }

    public function orgByCategory(string $org, string $slug): View
    {
        return $this->byCategory($slug);
    }

    public function byTag(string $slug): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->assertOrganizationBlogIsReadable($organization);

        $tag = Tag::where('slug', $slug)->firstOrFail();

        $posts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->with(['user', 'category', 'tags'])
            ->withCount(['comments', 'likes'])
            ->whereHas('tags', fn ($q) => $q->where('slug', $slug))
            ->latest('published_at')
            ->paginate(12);

        return view('blog.tag', compact('tag', 'posts'));
    }

    public function orgByTag(string $org, string $slug): View
    {
        return $this->byTag($slug);
    }

    public function show(BlogPost $post): View
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($post->status !== 'published' && auth()->id() !== $post->user_id && ! auth()->user()?->is_admin && ! $post->coAuthors()->where('user_id', auth()->id())->exists()) {
            abort(404);
        }

        $this->assertOrganizationBlogIsReadable($organization);
        $this->assertPrivateLoopManifestoIsReadable($post);

        $post->increment('views_count');
        $post->load(['user', 'category', 'tags', 'comments.user', 'comments.replies.user'])
            ->loadCount('likes');

        $relatedPosts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->where('category_id', $post->category_id)
            ->where('id', '!=', $post->id)
            ->latest('published_at')
            ->limit(3)
            ->get();

        $isLiked = auth()->check() && $post->isLikedBy(auth()->user());

        [$headers, $postContent] = $this->extractPublishedToc($post);

        // TASK-1827 — n'existe que pour un lecteur qui a deja un chemin vers
        // le catalogue des Boucles (voir le docblock de `LoopArticleLinks`).
        $linkedLoops = $this->viewerMaySeeLoopLinks($organization)
            ? app(LoopArticleLinks::class)->forPost($post, $organization)
            : collect();

        return view('blog.show', compact('post', 'relatedPosts', 'isLiked', 'headers', 'postContent', 'linkedLoops'));
    }

    /**
     * TASK-1827 — un nom de Boucle n'est montre qu'a quelqu'un qui a DEJA un
     * chemin existant vers le catalogue des Boucles (`loops.index`) : un
     * membre actif de CETTE Organization. Un visiteur public ou d'une autre
     * Organization n'y a jamais acces aujourd'hui — le leur montrer ici
     * serait une fuite neuve, pas la reprise d'un contrat existant.
     */
    private function viewerMaySeeLoopLinks(Organization $organization): bool
    {
        if (! $organization->loops_enabled) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && $user->organization_id === $organization->id;
    }

    /**
     * A published article is normally readable by the whole Organization. That
     * is wrong for one case (TASK-1079): an article designated as the Manifesto
     * of a *private* Loop. Publishing it makes it visible on that Loop's
     * presentation page, but the Loop's confidentiality must extend to the
     * article itself — otherwise the direct /blog/{slug} URL walks straight
     * around it.
     *
     * Only active members of that Loop, and platform super-admins, may read it.
     */
    private function assertPrivateLoopManifestoIsReadable(BlogPost $post): void
    {
        $loop = Loop::where('manifesto_blog_post_id', $post->id)
            ->where('visibility', 'private')
            ->first();

        if (! $loop) {
            return;
        }

        $user = auth()->user();

        if ($user?->is_admin) {
            return;
        }

        $isMember = $user && LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        abort_unless($isMember, 404);
    }

    /**
     * TASK-1492 — la confidentialite d'une Organization s'etend a son blog.
     *
     * ## L'autorite n'est pas inventee ici, elle est CONTINUEE
     *
     * `assertPrivateLoopManifestoIsReadable()`, juste au-dessus, ecrit deja le
     * contrat en toutes lettres (TASK-1079) :
     *
     * > « A published article is normally readable by the whole **Organization**.
     * >   [...] the Loop's confidentiality must extend to the article itself —
     * >   otherwise the direct /blog/{slug} URL walks straight around it. »
     *
     * Publier rend donc un article lisible par l'ORGANIZATION, pas par le Web.
     * Et la confidentialite d'un contenant s'etend a l'article qu'il porte.
     * TASK-1079 l'a applique a une Boucle privee ; il manquait le contenant le
     * plus englobant de tous — l'Organization elle-meme.
     *
     * ## Ce qui a ete mesure au HEAD 8f540d69
     *
     * `test20260822` (`is_public = false`), article publie
     * `02-design-cadre-du-dialogue` :
     *
     * | Lecteur | Statut | Titre rendu |
     * |---|---|---|
     * | membre de test20260822 | 200 | oui |
     * | membre d'une AUTRE Organization | **200** | **oui** |
     * | anonyme complet | **200** | **oui** |
     *
     * `BlogController` ne filtrait que sur `organization_id` : ni appartenance,
     * ni publicite. La meme forme de defaut que TASK-1479 et TASK-1488.
     *
     * ## Ce que cette garde ne change PAS
     *
     * Une Organization PUBLIQUE garde exactement son contrat de blog actuel :
     * un article publie y reste lisible par un invite. C'est la raison d'etre du
     * blog comme surface d'acquisition, et rien ici ne la touche. Le SuperAdmin
     * conserve son acces transverse, avec le meme predicat `is_admin` que
     * `EnsureOrganizationMember` et `OrgAdminMiddleware` — ouvrir un
     * contournement neuf a l'occasion d'un correctif de fuite serait le
     * contraire du but.
     *
     * Le refus est un 404, comme les deux gardes voisines et pour la meme
     * raison : un 403 confirmerait a un tiers que cette Organization existe.
     */
    private function assertOrganizationBlogIsReadable(Organization $organization): void
    {
        if ($organization->is_public) {
            return;
        }

        $user = auth()->user();

        if ($user?->is_admin) {
            return;
        }

        abort_unless($user && $user->organization_id === $organization->id, 404);
    }

    public function orgShow(string $org, BlogPost $post): View
    {
        return $this->show($post);
    }

    public function create(): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->authorize('create', BlogPost::class);
        $categories = Category::where('organization_id', $organization->id)->orderBy('name_b2c')->get();
        $tags = Tag::orderBy('name')->get();

        return view('blog.create', compact('organization', 'categories', 'tags'));
    }

    public function orgCreate(string $org): View
    {
        return $this->create();
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->authorize('create', BlogPost::class);

        $data = $this->validateBlogPostRequest($request, $organization, ['draft', 'published']);

        $data['content'] = $this->sanitizeHtml($data['content']);

        $data['user_id'] = auth()->id();
        $data['organization_id'] = $organization->id;
        $data['slug'] = Str::slug($data['title']);
        $data['published_at'] = $data['status'] === 'published' ? now() : null;

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('blog', 'public');
        }

        $post = BlogPost::create($data);

        if (! empty($data['tags'])) {
            $post->tags()->syncWithPivotValues(
                $this->tagIdsFromInput($data['tags'], $organization->id),
                ['organization_id' => $organization->id]
            );
        }

        $message = $data['status'] === 'published' ? __('blog.created_published') : __('blog.created_draft');

        return redirect($this->blogUrl('show', ['post' => $post]))->with('success', $message);
    }

    public function orgStore(Request $request, string $org): RedirectResponse|JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->authorize('create', BlogPost::class);

        $data = $this->validateBlogPostRequest($request, $organization, ['draft', 'published']);

        $data['content'] = $this->sanitizeHtml($data['content']);
        $data['user_id'] = auth()->id();
        $data['organization_id'] = $organization->id;
        $data['slug'] = Str::slug($data['title']);
        $data['published_at'] = $data['status'] === 'published' ? now() : null;

        $post = BlogPost::create($data);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'post' => [
                    'id' => $post->id,
                    'slug' => $post->slug,
                    'title' => $post->title,
                ],
                // La route nommee, jamais une URL fabriquee : l'edition vit
                // sur `/blog/rediger/{slug}/modifier`, et cette chaine-la
                // envoyait sur un 404. Meme faute que celle corrigee dans
                // `DossierArticleController` (TASK-1130) — c'etait la derniere
                // occurrence du depot.
                'redirect_url' => route('organization.blog.edit', [
                    'organization' => $org,
                    'post' => $post->slug,
                ]),
            ], 201);
        }

        return redirect($this->blogUrl('show', ['post' => $post]))->with('success', __('blog.created_draft'));
    }

    public function edit(BlogPost $post): View
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);
        $categories = Category::where('organization_id', $organization->id)->orderBy('name_b2c')->get();
        $tags = Tag::orderBy('name')->get();

        $userLoops = LoopMember::where('user_id', auth()->id())
            ->where('status', 'active')
            ->with('loop')
            ->get()
            ->pluck('loop')
            ->filter(fn ($l) => $l && $l->organization_id === $organization->id)
            ->values();

        $postLoops = $post->loops()->get();

        return view('blog.edit', compact('organization', 'post', 'categories', 'tags', 'userLoops', 'postLoops'));
    }

    public function orgEdit(string $org, BlogPost $post): View
    {
        return $this->edit($post);
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $data = $this->validateBlogPostRequest($request, $organization, ['draft', 'pending', 'published', 'archived']);

        $data['content'] = $this->sanitizeHtml($data['content']);

        if ($data['status'] === 'published' && ! $post->published_at) {
            $data['published_at'] = now();
        }

        if ($request->boolean('remove_image') && $post->image) {
            Storage::disk('public')->delete($post->image);
            $data['image'] = null;
        }

        if ($request->hasFile('image')) {
            if ($post->image) {
                Storage::disk('public')->delete($post->image);
            }
            $data['image'] = $request->file('image')->store('blog', 'public');
        }

        unset($data['remove_image']);

        $post->update($data);

        if (isset($data['tags'])) {
            $post->tags()->syncWithPivotValues(
                $this->tagIdsFromInput($data['tags'], $post->organization_id),
                ['organization_id' => $post->organization_id]
            );
        }

        $this->createAutoSnapshot($post, $data, $request->user());

        return redirect($this->blogUrl('show', ['post' => $post]))->with('success', __('blog.updated'));
    }

    public function orgUpdate(Request $request, string $org, BlogPost $post): RedirectResponse
    {
        return $this->update($request, $post);
    }

    public function publish(BlogPost $post): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);
        $post->update([
            'status' => 'published',
            'published_at' => $post->published_at ?? now(),
        ]);

        return back()->with('success', __('blog.published'));
    }

    public function orgPublish(string $org, BlogPost $post): RedirectResponse
    {
        return $this->publish($post);
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('delete', $post);
        $post->delete();

        return redirect($this->blogUrl('my-posts'))->with('success', __('blog.deleted'));
    }

    public function orgDestroy(string $org, BlogPost $post): RedirectResponse
    {
        return $this->destroy($post);
    }

    public function saveContent(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $data = $request->validate([
            'content' => 'required|string',
        ]);

        $post->update([
            'content' => $this->sanitizeHtml($data['content']),
        ]);

        return response()->json(['message' => __('blog.content_saved')]);
    }

    public function orgSaveContent(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->saveContent($request, $post);
    }

    public function updatePlan(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $data = $request->validate([
            'show_toc' => 'required|boolean',
        ]);

        $post->update(['show_toc' => $data['show_toc']]);

        $message = $data['show_toc']
            ? __('blog.plan_visible')
            : __('blog.plan_hidden');

        return response()->json(['message' => $message]);
    }

    public function orgUpdatePlan(string $org, BlogPost $post): JsonResponse
    {
        return $this->updatePlan(request(), $post);
    }

    public function myPosts(): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $user = auth()->user();

        $drafts = BlogPost::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['draft', 'pending'])
            ->with(['user', 'coAuthors'])
            ->withCount(['comments', 'likes', 'coAuthors'])
            ->latest()
            ->paginate(15, ['*'], 'drafts_page');

        $publishedPosts = BlogPost::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->with(['user', 'coAuthors'])
            ->withCount(['comments', 'likes', 'coAuthors'])
            ->latest()
            ->paginate(15, ['*'], 'published_page');

        $coAuthorIds = $user->coAuthoredBlogPosts()
            ->where('organization_id', $organization->id)
            ->pluck('blog_posts.id');

        $ownedWithCoAuthorIds = BlogPost::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->whereHas('coAuthors')
            ->pluck('id');

        $allCollaborativeIds = $coAuthorIds->merge($ownedWithCoAuthorIds)->unique()->values();

        $coAuthoredPosts = BlogPost::whereIn('id', $allCollaborativeIds)
            ->where('organization_id', $organization->id)
            ->with(['user', 'coAuthors'])
            ->withCount(['comments', 'likes', 'coAuthors'])
            ->latest()
            ->paginate(15, ['*'], 'coauthored_page');

        $comments = BlogComment::where('user_id', $user->id)
            ->whereHas('post', fn ($q) => $q->where('organization_id', $organization->id))
            ->with('post')
            ->latest()
            ->paginate(15, ['*'], 'comments_page');

        return view('blog.my-posts', compact(
            'drafts', 'publishedPosts', 'coAuthoredPosts', 'comments', 'user'
        ));
    }

    public function orgMyPosts(string $org): View
    {
        return $this->myPosts();
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|max:5120|mimes:jpeg,png,webp,gif',
        ]);

        $org = currentOrganization();
        $user = $request->user();
        $uuid = (string) Str::uuid();
        $ext = $request->file('image')->extension();
        $dir = sprintf('blog/images/%s/%s', $org->id, $user->id);
        $filename = sprintf('%s.%s', $uuid, $ext);
        $path = sprintf('%s/%s', $dir, $filename);
        $request->file('image')->storeAs($dir, $filename, 'public');

        return response()->json(['url' => Storage::disk('public')->url($path)]);
    }

    public function orgUploadImage(Request $request, string $org): JsonResponse
    {
        return $this->uploadImage($request);
    }

    public function createDraft(Request $request): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->authorize('create', BlogPost::class);

        $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'required|string|max:500',
            // Optional since TASK-1084: an article without a category is a
            // perfectly ordinary article, and 89 of them already existed.
            'category_id' => 'nullable|uuid|exists:categories,id',
        ]);

        if ($request->filled('category_id')
            && ! Category::where('id', $request->input('category_id'))->where('organization_id', $organization->id)->exists()) {
            return response()->json(['error' => __('blog.validation_category_invalid')], 422);
        }

        $post = BlogPost::create([
            'user_id' => $request->user()->id,
            'organization_id' => $organization->id,
            'title' => $request->input('title'),
            'summary' => $request->input('summary', ''),
            'content' => '<p></p>',
            'status' => 'draft',
            'category_id' => $request->input('category_id'),
        ]);

        return response()->json([
            'post_id' => $post->id,
            'edit_url' => $this->blogUrl('edit', ['post' => $post]),
        ]);
    }

    public function orgCreateDraft(Request $request, string $org): JsonResponse
    {
        return $this->createDraft($request);
    }

    public function aiGenerate(Request $request, BlogAiService $ai): JsonResponse
    {
        return $this->handleAi($request, $ai, 'generate');
    }

    public function orgAiGenerate(Request $request, BlogAiService $ai, string $org): JsonResponse
    {
        return $this->aiGenerate($request, $ai);
    }

    public function aiCorrect(Request $request, BlogAiService $ai): JsonResponse
    {
        return $this->handleAi($request, $ai, 'correct');
    }

    public function orgAiCorrect(Request $request, BlogAiService $ai, string $org): JsonResponse
    {
        return $this->aiCorrect($request, $ai);
    }

    public function aiRemaining(Request $request, BlogAiService $ai): JsonResponse
    {
        $user = $request->user();

        $generateConfig = $ai->checkEnabled('blog_generate', $user);
        $correctConfig = $ai->checkEnabled('blog_correct', $user);

        $providerInfo = $ai->getProviderInfo();

        $result = [
            'generate' => $generateConfig['limit'],
            'correct' => $correctConfig['limit'],
            'limits' => [
                'generate' => $generateConfig['limit'],
                'correct' => $correctConfig['limit'],
            ],
            'provider' => $providerInfo['provider'],
            'model' => $providerInfo['model'],
        ];

        if ($request->has('post_id') && $request->filled('post_id')) {
            $post = $this->resolveBlogPost($request->input('post_id'), $user);

            $result['generate'] = $ai->remainingCount($post, $user, 'blog_generate');
            $result['correct'] = $ai->remainingCount($post, $user, 'blog_correct');
        }

        return response()->json($result);
    }

    public function orgAiRemaining(Request $request, BlogAiService $ai, string $org): JsonResponse
    {
        return $this->aiRemaining($request, $ai);
    }

    public function aiMethodSelection(Request $request, BlogAiService $ai): JsonResponse
    {
        $data = $request->validate([
            'post_id' => ['required', 'string'],
            'method' => ['required', 'string', Rule::in(['explorer', 'clarifier', 'slow_down', 'invent'])],
            'selected_text' => ['required', 'string', 'min:2', 'max:1200'],
            'start_offset' => ['nullable', 'integer', 'min:0'],
            'end_offset' => ['nullable', 'integer', 'min:0'],
            'context_before' => ['nullable', 'string', 'max:1200'],
            'context_after' => ['nullable', 'string', 'max:1200'],
        ]);

        $user = $request->user();
        $post = BlogPost::findOrFail($data['post_id']);
        $organization = currentOrganization();

        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        try {
            $result = $ai->methodSelection(
                $post,
                $user,
                $data['method'],
                $data['selected_text'],
                $data['context_before'] ?? null,
                $data['context_after'] ?? null,
            );
        } catch (AiRefusedException $e) {
            // TASK-1247 : refus economique AVANT tout appel, code stable.
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->refusalCode,
                'offers_url' => $e->offersUrl($organization),
            ], 429);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json($result);
    }

    public function orgAiMethodSelection(Request $request, BlogAiService $ai, string $org): JsonResponse
    {
        return $this->aiMethodSelection($request, $ai);
    }

    private function handleAi(Request $request, BlogAiService $ai, string $mode): JsonResponse
    {
        $post = null;
        $isCreateFlow = false;

        try {
            $user = $request->user();

            $title = $request->input('title');
            $summary = $request->input('summary');

            if ($request->has('post_id') && $request->filled('post_id')) {
                $post = $this->resolveBlogPost($request->input('post_id'), $user);
            }

            // TASK-1288 : sans article PERSISTE — flux creation des deux modes,
            // ou `post_id` inconnu que `resolveBlogPost()` transforme en article
            // temporaire — le tenant de tout ce qui suit (l'article cree,
            // l'appel provider, la ligne de ledger : `BlogAiService::tenantOf()`)
            // est l'Organization RESOLUE. Sur la surface courte, c'est
            // l'Organization PAR DEFAUT, quel que soit l'utilisateur connecte
            // (`ResolveUrlOrganization` ne consulte jamais Auth pour /blog) ;
            // sur la surface prefixee, celle de l'URL. Un article existant est
            // deja garde par `checkPostAccess()` (tenant + policy `update`) ;
            // ici, rien ne l'etait : un membre d'une AUTRE Organization
            // creait un article et debitait le budget IA de celle-ci.
            // Meme regle que partout ailleurs (ProfileController,
            // ReportController, LoopEventAgendaController, CreateFeedPost) :
            // on n'ecrit ni ne depense dans l'Organization d'un autre. Le refus
            // vient AVANT toute ecriture et AVANT tout appel — rien n'est cree,
            // rien ne part, rien n'est inscrit au ledger — et prend la forme
            // des autres refus fonctionnels de ce controleur (`ai_disabled`).
            $organizationId = currentOrganization()?->id ?? $user->organization_id;

            if (($post === null || ! $post->exists) && $user->organization_id !== $organizationId) {
                return response()->json(['error' => __('blog.ai_cross_org')], 403);
            }

            if ($post === null) {
                if ($mode !== 'generate') {
                    $request->validate(['content' => 'required|string|min:10']);
                    $post = new BlogPost;
                    $post->id = (string) Str::uuid();
                    $post->organization_id = $organizationId;
                    $post->user_id = $user->id;
                    $post->content = $request->input('content');
                } else {
                    if (empty($title) || empty($summary) || empty($request->input('category_id'))) {
                        return response()->json(['error' => __('blog.ai_need_title_summary')], 422);
                    }

                    if (! Category::where('id', $request->input('category_id'))->where('organization_id', $organizationId)->exists()) {
                        return response()->json(['error' => __('blog.validation_category_invalid')], 422);
                    }

                    $post = BlogPost::create([
                        'user_id' => $user->id,
                        'organization_id' => $organizationId,
                        'title' => $title,
                        'summary' => $summary,
                        'content' => '<p></p>',
                        'status' => 'draft',
                        'category_id' => $request->input('category_id'),
                    ]);
                    $isCreateFlow = true;
                }
            }

            $feature = $mode === 'generate' ? 'blog_generate' : 'blog_correct';

            $featureConfig = $ai->checkEnabled($feature, $user);
            if (! $featureConfig['enabled']) {
                $label = $mode === 'generate' ? __('blog.ai_label_generation') : __('blog.ai_label_correction');

                return response()->json(['error' => __('blog.ai_disabled', ['label' => $label])], 403);
            }

            $remaining = $ai->remainingCount($post, $user, $feature);

            if ($remaining <= 0) {
                $allowed = $ai->checkEnabled($feature, $user);

                return response()->json(['error' => __('blog.ai_limit_reached', ['limit' => $allowed['limit']])], 429);
            }

            if ($mode === 'correct') {
                $request->validate(['content' => 'required|string|min:10']);
                $post->content = $request->input('content');
            }

            $result = $mode === 'generate'
                ? $ai->generate($post, $user, $title, $summary)
                : $ai->correct($post, $user);

            if ($isCreateFlow) {
                $post->title = $result['title'] ?? $post->title;
                $post->summary = $result['summary'] ?? $post->summary;
                $post->content = $result['content'];
                $post->save();
            }

            $newRemaining = $ai->remainingCount($post, $user, $feature);

            $response = [
                'content' => $result['content'],
                'provider' => $result['provider'],
                'model' => $result['model'],
                'limit' => $result['limit'],
                'title' => $result['title'] ?? null,
                'summary' => $result['summary'] ?? null,
                'remaining' => [
                    'generate' => $feature === 'blog_generate' ? $newRemaining : $ai->remainingCount($post, $user, 'blog_generate'),
                    'correct' => $feature === 'blog_correct' ? $newRemaining : $ai->remainingCount($post, $user, 'blog_correct'),
                ],
            ];

            if ($isCreateFlow) {
                $response['post_id'] = $post->id;
                $response['edit_url'] = $this->blogUrl('edit', ['post' => $post]);
            }

            return response()->json($response);
        } catch (HttpException $e) {
            throw $e;
        } catch (AiRefusedException $e) {
            // TASK-1247 : refus economique AVANT tout appel provider (credit
            // utilisateur epuise / budget Organization atteint / IA non
            // configuree), avec son code stable et, pour le credit, l'URL des
            // offres — meme contrat JSON que `LoopController::knowledge()`.
            // Rien n'a ete consomme, rien n'a ete ecrit.
            return response()->json(array_filter([
                'error' => $e->getMessage(),
                'code' => $e->refusalCode,
                'offers_url' => $e->offersUrl(currentOrganization()),
                'post_id' => $isCreateFlow && $post?->exists ? $post->id : null,
                'edit_url' => $isCreateFlow && $post?->exists ? $this->blogUrl('edit', ['post' => $post]) : null,
            ], static fn ($value): bool => $value !== null), 429);
        } catch (\RuntimeException $e) {
            if ($isCreateFlow && $post?->exists) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'post_id' => $post->id,
                    'edit_url' => $this->blogUrl('edit', ['post' => $post]),
                ]);
            }

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function resolveBlogPost(string $postId, $user): BlogPost
    {
        $post = BlogPost::find($postId);
        if ($post) {
            $this->checkPostAccess($post, $user);

            return $post;
        }

        $temp = new BlogPost;
        $temp->id = $postId;
        $temp->organization_id = currentOrganization()?->id ?? $user->organization_id;
        $temp->user_id = $user->id;

        return $temp;
    }

    private function checkPostAccess(BlogPost $post, $user): void
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }
        // La policy, pas un test d'auteur recode en dur : ce garde protege les
        // endpoints IA de la page editeur, et `aiMethodSelection` passe deja
        // par `authorize('update', ...)`. L'ancien test « auteur ou admin »
        // repondait 403 aux co-auteurs et aux editeurs du manifeste d'une
        // Boucle (TASK-1279) que la meme page laisse pourtant rediger.
        if (! $user->can('update', $post)) {
            abort(403);
        }
    }

    private function blogUrl(string $name, array $parameters = []): string
    {
        $organization = request()->route('organization') ?: currentOrganization()?->slug;

        if ($organization && Route::has('organization.blog.'.$name)) {
            return route('organization.blog.'.$name, ['organization' => $organization, ...$parameters]);
        }

        return route('blog.'.$name, $parameters);
    }

    /**
     * TASK-1785 — DELEGATION. Le code vit desormais dans
     * `App\Services\Blog\BlogHtmlSanitizer`, pour que l'importateur RSS
     * puisse l'appeler : une methode privee de controleur est inatteignable
     * depuis un service.
     *
     * Le corps a ete deplace a l'IDENTIQUE, pas reecrit. Dupliquer aurait donne
     * deux sanitations qui divergent, et le jour ou l'une se durcit sans
     * l'autre, c'est la plus faible qui decide de ce qui entre en base.
     */
    private function sanitizeHtml(string $html): string
    {
        return app(BlogHtmlSanitizer::class)->sanitizeHtml($html);
    }

    /**
     * Drafts stay lightweight; publication requires the public fields.
     * Empty TipTap content is persisted as a minimal HTML paragraph to satisfy the DB constraint.
     */
    private function validateBlogPostRequest(Request $request, $organization, array $allowedStatuses): array
    {
        $isPublished = $request->input('status') === 'published';

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'summary' => [$isPublished ? 'required' : 'nullable', 'string', 'max:500'],
            'content' => [$isPublished ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($isPublished): void {
                if ($isPublished && $this->plainTextContent((string) $value) === '') {
                    $fail(__('blog.validation_content_required'));
                }
            }],
            'image' => ['nullable', 'image', 'max:5120', 'mimes:jpeg,png,webp,gif'],
            'remove_image' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in($allowedStatuses)],
            // Optional whatever the status: publishing an article is not a
            // reason to invent a category for it.
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'tags' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'show_toc' => ['nullable', 'boolean'],
            'toc_max_level' => ['nullable', Rule::in([2, 3, 4])],
            'toc_navigation_enabled' => ['nullable', 'boolean'],
        ], [
            'title.required' => __('blog.validation_title_required'),
            'summary.required' => __('blog.validation_summary_required'),
            'content.required' => __('blog.validation_content_required'),
            'category_id.required' => __('blog.validation_category_required'),
            'category_id.exists' => __('blog.validation_category_invalid'),
            'image.image' => __('blog.validation_image_valid'),
            'image.mimes' => __('blog.validation_image_format'),
            'image.max' => __('blog.validation_image_size'),
        ]);

        $validator->after(function ($validator) use ($request, $organization): void {
            $categoryId = $request->input('category_id');
            if ($categoryId && ! Category::where('id', $categoryId)->where('organization_id', $organization->id)->exists()) {
                $validator->errors()->add('category_id', __('blog.validation_category_invalid'));
            }
        });

        $data = $validator->validate();

        $data['content'] = filled($data['content'] ?? null) ? $data['content'] : '<p></p>';
        $data['category_id'] = filled($data['category_id'] ?? null) ? $data['category_id'] : null;
        $data['show_toc'] = $request->boolean('show_toc');
        $data['toc_max_level'] = (int) ($data['toc_max_level'] ?? 4);
        $data['toc_navigation_enabled'] = $request->boolean('toc_navigation_enabled');

        return $data;
    }

    /**
     * @return array{0: array<int, array{level: int, text: string, id: string}>, 1: string}
     */
    private function extractPublishedToc(BlogPost $post): array
    {
        $headers = [];
        $postContent = $post->content;

        if (! $post->show_toc) {
            return [$headers, $postContent];
        }

        $maxLevel = min(4, max(2, (int) ($post->toc_max_level ?: 4)));
        $headingQuery = implode(' | ', array_map(fn (int $level) => '//h'.$level, range(2, $maxLevel)));

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$postContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $headingNodes = $xpath->query($headingQuery);

        if (! $headingNodes || $headingNodes->length === 0) {
            return [$headers, $postContent];
        }

        $headingCounts = [];
        foreach ($headingNodes as $node) {
            $rawText = trim($node->textContent);
            if ($rawText === '') {
                continue;
            }

            $baseId = 'heading-'.Str::slug($rawText);
            $baseId = $baseId === 'heading-' ? 'heading-section' : $baseId;
            $headingCounts[$baseId] = ($headingCounts[$baseId] ?? 0) + 1;
            $id = $headingCounts[$baseId] > 1 ? $baseId.'-'.$headingCounts[$baseId] : $baseId;

            $node->setAttribute('id', $id);

            $headers[] = [
                'level' => (int) $node->tagName[1],
                'text' => $rawText,
                'id' => $id,
            ];
        }

        return [$headers, $headers === [] ? $postContent : $dom->saveHTML()];
    }

    private function plainTextContent(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);

        return trim($text);
    }

    /** @return array<int, string> */
    private function tagIdsFromInput(?string $input, string $organizationId): array
    {
        $tagIds = [];
        $seenSlugs = [];

        foreach (explode(',', (string) $input) as $rawName) {
            $name = trim(preg_replace('/^#+/', '', trim($rawName)) ?? '');
            $slug = Str::slug($name);

            if ($name === '' || $slug === '' || isset($seenSlugs[$slug])) {
                continue;
            }

            $seenSlugs[$slug] = true;
            $tagIds[] = Tag::firstOrCreate(
                ['slug' => $slug, 'organization_id' => $organizationId],
                ['name' => $name, 'slug' => $slug]
            )->id;

            if (count($tagIds) >= 10) {
                break;
            }
        }

        return $tagIds;
    }

    private function createAutoSnapshot(BlogPost $post, array $data, $user): void
    {
        $lastSnapshot = $post->snapshots()->latest()->first();

        $sanitizedContent = $this->sanitizeHtml($data['content']);

        $changed = (
            ! $lastSnapshot
            || $lastSnapshot->title !== $data['title']
            || $lastSnapshot->summary !== ($data['summary'] ?? null)
            || $lastSnapshot->content !== $sanitizedContent
            || $lastSnapshot->meta_title !== ($data['meta_title'] ?? null)
            || $lastSnapshot->meta_description !== ($data['meta_description'] ?? null)
            || $lastSnapshot->status !== ($data['status'] ?? $post->status)
        );

        if (! $changed) {
            return;
        }

        BlogSnapshot::create([
            'blog_post_id' => $post->id,
            'name' => __('blog.snapshot_auto_prefix').now()->format('Y-m-d H:i'),
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'content' => $sanitizedContent,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'status' => $data['status'] ?? $post->status,
            'created_by' => $user->id,
        ]);
    }
}
