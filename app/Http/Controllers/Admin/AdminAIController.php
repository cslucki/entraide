<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AI\AISettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAIController extends Controller
{
    public function __construct(protected AISettingsService $settings)
    {
    }

    public function index(): View
    {
        $config = [
            'ai_provider'            => $this->settings->getActiveProvider(),
            'ai_openai_model'        => $this->settings->getOpenAIModel(),
            'ai_master_prompt'       => $this->settings->getMasterPrompt(),
            'ai_classification_prompt'=> $this->settings->getClassificationPrompt(),
            'ai_examples_json'       => json_encode($this->settings->getFewShotExamples(), JSON_PRETTY_PRINT),
            'ai_enabled'             => $this->settings->isAIEnabled(),
        ];

        return view('admin.ai.index', compact('config'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ai_provider'             => 'required|in:fake,openai',
            'ai_openai_model'         => 'required|string',
            'ai_master_prompt'        => 'required|string',
            'ai_classification_prompt' => 'required|string',
            'ai_examples_json'        => 'required|json',
            'ai_enabled'              => 'boolean',
        ]);

        $this->settings->setMany([
            'ai_provider'             => $data['ai_provider'],
            'ai_openai_model'         => $data['ai_openai_model'],
            'ai_master_prompt'        => $data['ai_master_prompt'],
            'ai_classification_prompt' => $data['ai_classification_prompt'],
            'ai_examples_json'        => $data['ai_examples_json'],
            'ai_enabled'              => $request->has('ai_enabled') ? '1' : '0',
        ]);

        return redirect()->route('admin.ai')->with('success', 'AI Configuration updated successfully.');
    }
}
