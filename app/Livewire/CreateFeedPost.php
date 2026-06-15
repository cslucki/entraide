<?php

namespace App\Livewire;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Services\LoopMessageService;
use App\Services\UrlPreviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Component;
use Livewire\WithFileUploads;

class CreateFeedPost extends Component
{
    use WithFileUploads;

    public string $title = '';

    public string $content = '';

    public bool $pin = false;

    public array $selectedLoops = [];

    public bool $allLoops = false;

    public $image = null;

    public function mount(): void
    {
        $org = currentOrganization();

        if (! $org) {
            abort(404);
        }

        if (! auth()->user() || ! auth()->user()->can('create', FeedPost::class)) {
            abort(403);
        }
    }

    public function submit(LoopMessageService $loopMessageService): void
    {
        $this->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'required|string|max:10000',
            'image' => 'nullable|image|max:10240',
            'pin' => 'boolean',
            'selectedLoops' => 'nullable|array',
            'selectedLoops.*' => 'string|uuid',
            'allLoops' => 'boolean',
        ]);

        $org = currentOrganization();

        if (! $org) {
            abort(404);
        }

        $user = auth()->user();

        DB::transaction(function () use ($org, $user, $loopMessageService) {
            $imagePath = $this->image ? $this->storeImage($org->id) : null;
            $url = UrlPreviewService::extractFirstUrl($this->content);
            $preview = $url ? app(UrlPreviewService::class)->fetchPreview($url) : null;

            $post = FeedPost::create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'type' => FeedPost::TYPE_ANNOUNCEMENT,
                'title' => $this->title ?: null,
                'content' => $this->content,
                'image_path' => $imagePath,
                'url_preview' => $preview,
                'status' => FeedPost::STATUS_PUBLISHED,
                'pinned_at' => $this->pin ? now() : null,
                'pinned_by_id' => $this->pin ? $user->id : null,
            ]);

            $targetLoops = collect();

            if ($this->allLoops) {
                $targetLoops = Loop::where('organization_id', $org->id)->get();
            } elseif (! empty($this->selectedLoops)) {
                $targetLoops = Loop::whereIn('id', $this->selectedLoops)
                    ->where('organization_id', $org->id)
                    ->get();
            }

            foreach ($targetLoops as $loop) {
                $post->loops()->syncWithoutDetaching([$loop->id]);

                try {
                    $body = ($this->title ? "**{$this->title}**\n\n" : '').$this->content;
                    $loopMessageService->sendUserMessage(
                        $loop,
                        $user,
                        $body,
                        metadata: ['feed_post_id' => $post->id, 'type' => 'announcement'],
                    );
                } catch (\RuntimeException) {
                    // skip loops where user is not a member
                }
            }
        });

        $this->image = null;
        $this->dispatch('announcement-created');

        $route = currentOrganization()?->is_default ? 'flux' : 'organization.flux';
        $parameters = $route === 'organization.flux' ? ['organization' => currentOrganization()?->slug] : [];

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
        $org = currentOrganization();
        $loops = $org
            ? Loop::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name', 'slug', 'description'])->map(fn (Loop $loop) => [
                'id' => $loop->id,
                'name' => $loop->name,
                'slug' => $loop->slug,
                'description' => $loop->description,
            ])
            : collect();

        return view('livewire.create-feed-post', compact('loops'));
    }
}
