<?php

namespace App\Livewire;

use App\Models\FeedPost;
use App\Services\UrlPreviewService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
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

    public function mount(FeedPost $feedPost): void
    {
        $this->post = $feedPost;

        if (! auth()->user() || ! auth()->user()->can('update', $feedPost)) {
            abort(403);
        }

        $this->title = $feedPost->title ?? '';
        $this->content = $feedPost->content;
        $this->mode = match ($feedPost->status) {
            FeedPost::STATUS_DRAFT => 'draft',
            FeedPost::STATUS_SCHEDULED => 'schedule',
            default => 'publish',
        };
        $this->scheduledAt = $feedPost->scheduled_at?->format('Y-m-d\TH:i');
        $this->loopMessage = $feedPost->loop_message ?? '';
    }

    public function submit(): void
    {
        if (! auth()->user() || ! auth()->user()->can('update', $this->post)) {
            abort(403);
        }

        $this->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'required|string|max:10000',
            'image' => 'nullable|image|max:10240',
            'mode' => 'required|in:publish,draft,schedule',
            'scheduledAt' => $this->mode === 'schedule' ? ['required', 'date', 'after:now'] : 'nullable',
            'loopMessage' => 'nullable|string|max:10000',
        ]);

        $org = currentOrganization();

        if (! $org) {
            abort(404);
        }

        [$status, $publishedAt, $scheduledAtDb] = match ($this->mode) {
            'draft' => [FeedPost::STATUS_DRAFT, null, null],
            'schedule' => [FeedPost::STATUS_SCHEDULED, null, $this->scheduledAt],
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

        $route = currentOrganization()?->is_default ? 'flux.my' : 'organization.flux.my';
        $parameters = $route === 'organization.flux.my' ? ['organization' => currentOrganization()?->slug] : [];

        $this->redirectRoute($route, $parameters);
    }

    public function removeImage(): void
    {
        $this->image = null;
    }

    private function storeImage(string $organizationId): string
    {
        $img = Image::decode($this->image);
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
