<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Services\LoopMessageService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Component;
use Livewire\WithFileUploads;

class LoopChat extends Component
{
    use WithFileUploads;

    public Loop $loop;

    public string $body = '';

    public bool $isMember = false;

    public ?string $replyToMessageId = null;

    public ?array $replyingTo = null;

    public $photo = null;

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;

        $user = auth()->user();
        if ($user) {
            $this->isMember = LoopMember::where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
        }
    }

    public function replyTo(string $messageId): void
    {
        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->with('sender')
            ->first();

        if (! $message) {
            return;
        }

        $this->replyToMessageId = $message->id;
        $this->replyingTo = [
            'body' => mb_substr($message->body, 0, 120),
            'sender_name' => $message->sender?->name ?? 'BouclePro',
        ];
    }

    public function cancelReply(): void
    {
        $this->replyToMessageId = null;
        $this->replyingTo = null;
    }

    public function sendMessage(LoopMessageService $service): void
    {
        $this->validate([
            'body' => 'required|string|max:5000',
            'photo' => 'nullable|image|max:10240',
        ]);

        $user = auth()->user();
        if (! $user || ! $this->isMember) {
            return;
        }

        try {
            $imagePath = null;

            if ($this->photo) {
                $imagePath = $this->storeImage($this->photo, 'loop-messages');
                $this->photo = null;
            }

            $service->sendUserMessage($this->loop, $user, $this->body, null, $this->replyToMessageId, $imagePath);
            $this->body = '';
            $this->cancelReply();
            $this->dispatch('message-sent');
        } catch (\RuntimeException) {
            $this->addError('body', 'Impossible d\'envoyer le message.');
        }
    }

    public function removePhoto(): void
    {
        $this->photo = null;
    }

    private function storeImage($file, string $subdirectory): string
    {
        $img = Image::decode($file);
        $img->scaleDown(1200, 800);

        $filename = Str::uuid()->toString().'.webp';
        $relativePath = 'message-images/'.$this->loop->organization_id.'/'.$subdirectory.'/'.$filename;

        Storage::disk('public')->put($relativePath, (string) $img->encode(new WebpEncoder(quality: 80)));

        return $relativePath;
    }

    public function render()
    {
        $messages = $this->loop->messages()
            ->with('sender')
            ->with('replyTo.sender')
            ->oldest()
            ->get();

        return view('livewire.loop-chat', compact('messages'));
    }
}
