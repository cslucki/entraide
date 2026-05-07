<?php

namespace App\Services\AI;

use App\Services\AI\Providers\AIProviderInterface;
use App\Services\AI\Providers\FakeProvider;
use App\Services\AI\Providers\OpenAIProvider;
use Illuminate\Support\Facades\App;

class AIProviderFactory
{
    public function __construct(protected AISettingsService $settings)
    {
    }

    public function make(): AIProviderInterface
    {
        $provider = $this->settings->getActiveProvider();

        if ($provider === AISettingsService::PROVIDER_OPENAI) {
            return App::make(OpenAIProvider::class);
        }

        return App::make(FakeProvider::class);
    }
}
