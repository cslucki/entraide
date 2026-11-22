<?php

namespace App\Livewire;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\User;
use App\Models\Organization;
use App\Services\LoopMessageService;
use App\Services\UrlPreviewService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class CreateFeedPost extends Component
{
    use WithFileUploads;

    public string $title = '';

    public string $content = '';

    public bool $pin = false;

    public string $mode = 'publish';

    public ?string $scheduledAt = null;

    public string $loopMessage = '';

    public array $selectedLoops = [];

    public bool $allLoops = false;

    public $image = null;

    /**
     * TASK-1691 (P0) — l'Organization du formulaire n'est plus a la main du
     * client.
     *
     * Mesure faite en T1690 : cette propriete etait publique et SANS `#[Locked]`.
     * Livewire hydrate les proprietes publiques depuis le snapshot **puis
     * applique les `updates` envoyes par le client** — un membre de A chargeait
     * SA page `/org/A/flux/creer` (200), obtenait un snapshot legitimement
     * signe, puis envoyait `{"updates":{"orgId":"<id de B>"}}`. `mount()` ne
     * rejoue pas aux updates ; `render()` listait alors les Boucles de B
     * (`id`, `name`, `slug`, `description`), y compris pour une Organization
     * PRIVEE et des Boucles privees. Symetrique dans les deux sens. Contre-
     * epreuve : sans falsification, rien de B n'apparaissait.
     *
     * `#[Locked]` ferme cela : Livewire refuse l'ecriture cliente.
     */
    #[Locked]
    public ?string $orgId = null;

    /**
     * TASK-1879 (demande Cyril, 22/11) — la page a-t-elle ete ouverte par une
     * URL prefixee /org/{slug} ? La redirection finale y reste (une requete
     * Livewire n'a plus le parametre de route).
     */
    #[Locked]
    public bool $urlPrefixee = false;

    /** TASK-1879 — « Le champ Message », pas « Le champ content ». */
    protected function validationAttributes(): array
    {
        return ['content' => __('feed-post.message'), 'title' => __('feed-post.title_optional')];
    }

    public function mount(): void
    {
        $this->urlPrefixee = (bool) request()->route('organization');

        $org = currentOrganization();

        if (! $org) {
            abort(404);
        }

        $this->orgId = $org->id;

        if (! auth()->user() || ! auth()->user()->can('create', FeedPost::class)) {
            abort(403);
        }
    }

    /**
     * TASK-1691 — `#[Locked]` protege une VALEUR ; seule une preuve
     * d'appartenance protege la DONNEE.
     *
     * `submit()` portait deja cette preuve ; `render()` n'en portait aucune, et
     * c'est `render()` qui fuyait. La poser ICI la rend commune aux deux, et a
     * toute methode future : c'est leur divergence qui a laisse vivre le defaut.
     *
     * On ne lit PAS `currentOrganization()` a la place : sur la page NON
     * prefixee (`/flux/creer`), seul `ResolveOrganization` est persistant chez
     * Livewire, et le tenant resolu lors d'un update y serait incertain. La
     * question sure est « cet utilisateur appartient-il a cette Organization ? ».
     */
    private function org(): ?Organization
    {
        if ($this->orgId === null) {
            return null;
        }

        $organization = Organization::find($this->orgId);

        if ($organization === null || auth()->user()?->organization_id !== $organization->id) {
            return null;
        }

        return $organization;
    }

    public function submit(LoopMessageService $loopMessageService): void
    {
        $scheduledAtUtc = null;

        if ($this->mode === 'schedule' && $this->scheduledAt) {
            $scheduledAtUtc = Carbon::parse($this->scheduledAt, 'Europe/Paris')->utc();
            $this->scheduledAt = $scheduledAtUtc->format('Y-m-d\TH:i');
        }

        $this->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'required|string|max:50000',
            'image' => 'nullable|image|max:10240',
            'pin' => 'boolean',
            'mode' => 'required|in:publish,draft,schedule',
            'scheduledAt' => $this->mode === 'schedule' ? ['required', 'date', 'after:now'] : 'nullable',
            'loopMessage' => 'nullable|string|max:10000',
            'selectedLoops' => 'nullable|array',
            'selectedLoops.*' => 'string|uuid',
            'allLoops' => 'boolean',
        ]);

        // TASK-1879 (demande Cyril, 22/11) — HTML de TipTap, nettoye comme celui
        // du blog. Un message fait seulement de balises vides est vide.
        $this->content = FeedPost::nettoyerContenu($this->content);

        if (trim(html_entity_decode(strip_tags($this->content))) === '') {
            $this->addError('content', __('validation.required', ['attribute' => __('feed-post.message')]));

            return;
        }

        $org = $this->org();

        if (! $org) {
            abort(404);
        }

        $user = auth()->user();

        if (! $user || $user->organization_id !== $org->id) {
            abort(403);
        }

        if ($org->feed_post_publish_mode !== 'members' && $org->admin_id !== $user->id && ! $user->is_admin) {
            abort(403);
        }

        if ($this->pin && ! $user->can('pin', FeedPost::class)) {
            abort(403);
        }

        // TASK-1879 (decision Cyril, 22/11) — hors administrateur, l'annonce vise
        // au moins une Boucle de l'auteur, et n'est visible que de leurs membres.
        $aTous = $this->peutPublierATous();
        $ciblesAutorisees = $this->bouclesDiffusables($org->id, $user)
            ->when(! $this->allLoops, fn ($q) => $q->whereIn('id', $this->selectedLoops ?: []))
            ->exists();

        if (! $aTous && ! $ciblesAutorisees) {
            $this->addError('selectedLoops', __('feed-post.loops_required'));

            return;
        }

        // TASK-1879 (signale par Cyril, 22/11) — la diffusion vers les Boucles se fait
        // APRES le commit : dans la transaction, l'echec de la diffusion temps reel
        // (Reverb injoignable) surgissait au commit, hors de tout try -> erreur 500,
        // alors que la garde de `sendUserMessage()` (TASK-1839) le gere tres bien seule.
        $post = DB::transaction(function () use ($org, $user, $scheduledAtUtc, $aTous) {
            $imagePath = $this->image ? $this->storeImage($org->id) : null;
            $url = UrlPreviewService::extractFirstUrl($this->content);
            $preview = $url ? app(UrlPreviewService::class)->fetchPreview($url) : null;

            [$status, $publishedAt, $scheduledAtDb] = match ($this->mode) {
                'draft' => [FeedPost::STATUS_DRAFT, null, null],
                'schedule' => [FeedPost::STATUS_SCHEDULED, null, $scheduledAtUtc],
                default => [FeedPost::STATUS_PUBLISHED, now(), null],
            };

            $post = FeedPost::create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'type' => FeedPost::TYPE_ANNOUNCEMENT,
                'title' => $this->title ?: null,
                'content' => $this->content,
                'image_path' => $imagePath,
                'url_preview' => $preview,
                'status' => $status,
                'published_at' => $publishedAt,
                'scheduled_at' => $scheduledAtDb,
                'loop_message' => $this->loopMessage ?: null,
                'pinned_at' => $this->pin ? now() : null,
                'pinned_by_id' => $this->pin ? $user->id : null,
                // TASK-1879 — `loops` : visible des seuls membres de ses Boucles
                // (pivot `feed_post_loop`), cf. `FeedPost::scopeVisibleTo()`.
                'visibility' => $aTous ? 'organization' : FeedPost::VISIBILITE_BOUCLES,
            ]);

            $targetLoops = collect();

            // TASK-1879 (signale par Cyril, 22/11) — seulement les Boucles dont
            // l'auteur est membre actif : la diffusion poste EN SON NOM dans le
            // ChatLoop, ce qu'il ne peut pas faire ailleurs.
            if ($this->allLoops) {
                $targetLoops = $this->bouclesDiffusables($org->id, $user)->get();
            } elseif (! empty($this->selectedLoops)) {
                $targetLoops = $this->bouclesDiffusables($org->id, $user)
                    ->whereIn('id', $this->selectedLoops)
                    ->get();
            }

            foreach ($targetLoops as $loop) {
                $post->loops()->syncWithoutDetaching([$loop->id]);
            }

            return $post;
        });

        if ($this->mode === 'publish') {
            $post->broadcastToAssociatedLoops($loopMessageService, $user);
        }

        $this->image = null;
        $this->dispatch('announcement-created');

        session()->flash('status', match ($this->mode) {
            'draft' => 'Brouillon enregistré.',
            'schedule' => 'Annonce planifiée.',
            default => 'Annonce publiée.',
        });

        $route = $org->is_default && ! $this->urlPrefixee ? 'flux' : 'organization.flux';
        $parameters = $route === 'organization.flux' ? ['organization' => $org->slug] : [];

        $this->redirectRoute($route, $parameters);
    }

    public function removeImage(): void
    {
        $this->image = null;
    }

    private function storeImage(string $organizationId): string
    {
        // TASK-1623 — le CONTENU, jamais le chemin (cf. LoopChat::storeImage).
        $img = Image::decode($this->image->get());
        $img->scaleDown(1400, 1000);

        $filename = Str::uuid()->toString().'.webp';
        $relativePath = 'feed-post-images/'.$organizationId.'/'.$filename;

        Storage::disk('public')->put($relativePath, (string) $img->encode(new WebpEncoder(quality: 82)));

        return $relativePath;
    }

    /**
     * TASK-1879 (decision Cyril, 22/11) — publier a TOUTE l'Organization est
     * reserve a son administrateur (et au superadmin). Les autres membres
     * publient dans leurs Boucles seulement.
     */
    public function peutPublierATous(): bool
    {
        $org = $this->org();
        $user = auth()->user();

        return $org !== null && $user !== null && ($org->admin_id === $user->id || $user->is_admin);
    }

    /**
     * TASK-1879 (signale par Cyril, 22/11) — les Boucles ou l'on peut diffuser :
     * celles de l'Organization dont l'auteur est membre ACTIF (meme regle que
     * `LoopMessageService::assertCanSend()`).
     */
    private function bouclesDiffusables(string $organizationId, User $user): \Illuminate\Database\Eloquent\Builder
    {
        return Loop::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', \App\Models\LoopMember::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->select('loop_id'));
    }

    public function render()
    {
        $org = $this->org();
        $user = auth()->user();
        $loops = $org && $user
            ? $this->bouclesDiffusables($org->id, $user)->orderBy('name')->get(['id', 'name', 'slug', 'description'])->map(fn (Loop $loop) => [
                'id' => $loop->id,
                'name' => $loop->name,
                'slug' => $loop->slug,
                'description' => $loop->description,
            ])
            : collect();

        $peutPublierATous = $this->peutPublierATous();

        return view('livewire.create-feed-post', compact('loops', 'peutPublierATous'));
    }
}
