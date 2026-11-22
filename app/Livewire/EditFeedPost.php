<?php

namespace App\Livewire;

use App\Models\FeedPost;
use App\Services\UrlPreviewService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class EditFeedPost extends Component
{
    use WithFileUploads;

    public FeedPost $post;

    public string $title = '';

    public string $content = '';

    public string $mode = 'publish';

    public ?string $scheduledAt = null;

    public string $loopMessage = '';

    public $image = null;

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

    public function mount(FeedPost $feedPost): void
    {
        $this->urlPrefixee = (bool) request()->route('organization');

        $this->post = $feedPost;

        if (! auth()->user() || ! auth()->user()->can('update', $feedPost)) {
            abort(403);
        }

        $this->title = $feedPost->title ?? '';
        // TASK-1879 — une annonce ancienne (Markdown) s'ouvre convertie en HTML.
        $this->content = $feedPost->contenuHtml();
        $this->mode = match ($feedPost->status) {
            FeedPost::STATUS_DRAFT => 'draft',
            FeedPost::STATUS_SCHEDULED => 'schedule',
            default => 'publish',
        };
        $this->scheduledAt = $feedPost->scheduled_at
            ? $feedPost->scheduled_at->setTimezone('Europe/Paris')->format('Y-m-d\TH:i')
            : null;
        $this->loopMessage = $feedPost->loop_message ?? '';
    }

    public function submit(): void
    {
        if (! auth()->user() || ! auth()->user()->can('update', $this->post)) {
            abort(403);
        }

        $scheduledAtUtc = null;

        if ($this->mode === 'schedule' && $this->scheduledAt) {
            $scheduledAtUtc = Carbon::parse($this->scheduledAt, 'Europe/Paris')->utc();
            $this->scheduledAt = $scheduledAtUtc->format('Y-m-d\TH:i');
        }

        $this->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'required|string|max:50000',
            'image' => 'nullable|image|max:10240',
            'mode' => 'required|in:publish,draft,schedule',
            'scheduledAt' => $this->mode === 'schedule' ? ['required', 'date', 'after:now'] : 'nullable',
            'loopMessage' => 'nullable|string|max:10000',
        ]);

        // TASK-1879 (demande Cyril, 22/11) — HTML de TipTap, nettoye comme celui
        // du blog. Un message fait seulement de balises vides est vide.
        $this->content = FeedPost::nettoyerContenu($this->content);

        if (trim(html_entity_decode(strip_tags($this->content))) === '') {
            $this->addError('content', __('validation.required', ['attribute' => __('feed-post.message')]));

            return;
        }

        $org = currentOrganization();

        if (! $org) {
            abort(404);
        }

        [$status, $publishedAt, $scheduledAtDb] = match ($this->mode) {
            'draft' => [FeedPost::STATUS_DRAFT, null, null],
            'schedule' => [FeedPost::STATUS_SCHEDULED, null, $scheduledAtUtc],
            default => [FeedPost::STATUS_PUBLISHED, $this->post->published_at ?? now(), null],
        };

        $imagePath = $this->image ? $this->storeImage($org->id) : $this->post->image_path;

        $url = UrlPreviewService::extractFirstUrl($this->content);
        $preview = $url
            ? ($this->content !== $this->post->content
                ? app(UrlPreviewService::class)->fetchPreview($url)
                : $this->post->url_preview)
            : null;

        $this->post->update([
            'title' => $this->title ?: null,
            'content' => $this->content,
            'image_path' => $imagePath,
            'url_preview' => $preview,
            'status' => $status,
            'published_at' => $publishedAt,
            'scheduled_at' => $scheduledAtDb,
            'loop_message' => $this->loopMessage ?: null,
        ]);

        session()->flash('status', match ($this->mode) {
            'draft' => 'Brouillon mis à jour.',
            'schedule' => 'Annonce planifiée mise à jour.',
            default => 'Annonce mise à jour.',
        });

        $route = currentOrganization()?->is_default && ! $this->urlPrefixee ? 'flux.my' : 'organization.flux.my';
        $parameters = $route === 'organization.flux.my' ? ['organization' => currentOrganization()?->slug] : [];

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

    public function render()
    {
        return view('livewire.edit-feed-post');
    }
}
