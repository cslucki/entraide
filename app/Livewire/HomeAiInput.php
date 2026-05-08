<?php

namespace App\Livewire;

use App\Services\AI\AIIntentService;
use App\Services\AI\AISettingsService;
use Livewire\Component;

class HomeAiInput extends Component
{
    public string $prompt = '';
    public bool $loading = false;
    public ?array $debugResult = null;

    public $suggestions = [
        "I want to help people with Excel",
        "I need a plumber in Marseille",
        "I can teach English remotely",
        "I need help creating my company",
    ];

    public function submit(AIIntentService $aiService, AISettingsService $settings)
    {
        if (empty(trim($this->prompt))) {
            return;
        }

        $this->loading = true;
        $this->debugResult = null;

        $result = $aiService->classify($this->prompt);

        \Illuminate\Support\Facades\Log::info('AI Submit', [
            'prompt' => $this->prompt,
            'debug_mode' => $settings->isDebugMode(),
            'is_admin' => auth()->user()?->is_admin
        ]);

        if ($settings->isDebugMode()) {
            $this->debugResult = $result;
            $this->loading = false;
            return;
        }

        $this->handleRedirection($result);
    }

    public function setPrompt(string $value)
    {
        $this->prompt = $value;
    }

    protected function handleRedirection(array $result)
    {
        $intent = $result['intent'] ?? 'unknown';
        $categorySlug = $result['category'] ?? null;
        $categoryId = null;

        if ($categorySlug && $categorySlug !== 'other') {
            $categoryId = \App\Models\Category::where('slug', $categorySlug)->first()?->id;
        }

        switch ($intent) {
            case 'service_offer':
                return redirect()->route('services.create', array_filter(['category_id' => $categoryId]));

            case 'service_request':
                return redirect()->route('requests.create', array_filter(['category_id' => $categoryId]));

            case 'search':
                return redirect()->route('explorer', array_filter([
                    'search' => $this->prompt,
                    'selectedCategories' => $categoryId ? [$categoryId] : null
                ]));

            case 'profile':
                return redirect()->route('profile.edit');

            default:
                return redirect()->route('explorer', ['search' => $this->prompt]);
        }
    }

    public function render()
    {
        return view('livewire.home-ai-input', [
            'isAdmin' => auth()->check() && auth()->user()->is_admin
        ]);
    }
}
