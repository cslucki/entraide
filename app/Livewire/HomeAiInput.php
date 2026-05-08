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

        if ($settings->isDebugMode()) {
            $this->debugResult = $result;
            $this->loading = false;
            return;
        }

        return $this->handleRedirection($result);
    }

    public function selectSuggestion(string $value, AIIntentService $aiService, AISettingsService $settings)
    {
        $this->prompt = $value;
        return $this->submit($aiService, $settings);
    }

    protected function handleRedirection(array $result)
    {
        $community = app()->bound('current_community') ? app('current_community') : null;
        $routePrefix = $community ? 'community.' : '';
        $routeParams = $community ? ['community' => $community->slug] : [];

        $intent = $result['intent'] ?? 'unknown';
        $categorySlug = $result['category'] ?? null;
        $categoryId = null;

        if ($categorySlug && $categorySlug !== 'other') {
            $categoryId = \App\Models\Category::where('slug', $categorySlug)->first()?->id;
        }

        switch ($intent) {
            case 'service_offer':
                $params = array_merge($routeParams, array_filter([
                    'category_id' => $categoryId,
                    'title' => $this->prompt
                ]));
                return redirect()->route($routePrefix . 'services.create', $params);

            case 'service_request':
                $params = array_merge($routeParams, array_filter([
                    'category_id' => $categoryId,
                    'title' => $this->prompt
                ]));
                return redirect()->route($routePrefix . 'requests.create', $params);

            case 'search':
                $params = array_merge($routeParams, array_filter([
                    'search' => $this->prompt,
                    'selectedCategories' => $categoryId ? [$categoryId] : null
                ]));
                return redirect()->route($routePrefix . 'explorer', $params);

            case 'profile':
                return redirect()->route($routePrefix . 'profile.edit', $routeParams);

            default:
                $params = array_merge($routeParams, ['search' => $this->prompt]);
                return redirect()->route($routePrefix . 'explorer', $params);
        }
    }

    public function render()
    {
        return view('livewire.home-ai-input', [
            'isAdmin' => auth()->check() && auth()->user()->is_admin
        ]);
    }
}
