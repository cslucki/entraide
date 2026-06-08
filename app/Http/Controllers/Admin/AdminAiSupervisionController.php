<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\Exceptions\SupervisionException;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAiSupervisionController extends Controller
{
    private const AVAILABLE_MODELS = [
        'gpt-4o-mini' => 'GPT-4o Mini (rapide, économique)',
        'gpt-4o' => 'GPT-4o (précis, plus coûteux)',
        'gpt-4.1-mini' => 'GPT-4.1 Mini',
        'gpt-4.1-nano' => 'GPT-4.1 Nano',
        'o4-mini' => 'o4-mini (raisonnement)',
    ];

    public function __construct(
        protected SupervisionProvider $provider,
    ) {}

    public function index(): View
    {
        return view('admin.ai-supervision.index', [
            'models' => self::AVAILABLE_MODELS,
            'model' => (string) config('ai.openai.model'),
            'enabled' => (bool) config('ai.supervision.enabled', true),
        ]);
    }

    public function analyze(Request $request): View
    {
        if (! config('ai.supervision.enabled', true)) {
            abort(403, 'Centre de supervision IA désactivé.');
        }

        $data = $request->validate([
            'content' => ['required', 'string', 'min:3', 'max:5000'],
            'model' => ['nullable', 'string', 'in:' . implode(',', array_keys(self::AVAILABLE_MODELS))],
        ]);

        $selectedModel = $data['model'] ?? (string) config('ai.openai.model');

        $error = null;
        $result = null;

        try {
            $result = $this->provider->supervise($data['content'], $selectedModel);
        } catch (SupervisionException $e) {
            $error = $e->getMessage();
        }

        return view('admin.ai-supervision.index', [
            'models' => self::AVAILABLE_MODELS,
            'model' => $selectedModel,
            'enabled' => (bool) config('ai.supervision.enabled', true),
            'content' => $data['content'],
            'result' => $result,
            'supervisionError' => $error,
        ]);
    }
}
