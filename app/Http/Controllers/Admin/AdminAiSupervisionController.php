<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\Exceptions\SupervisionException;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAiSupervisionController extends Controller
{
    public function __construct(
        protected SupervisionProvider $provider,
    ) {}

    public function index(): View
    {
        return view('admin.ai-supervision.index', [
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
        ]);

        $error = null;
        $result = null;

        try {
            $result = $this->provider->supervise($data['content']);
        } catch (SupervisionException $e) {
            $error = $e->getMessage();
        }

        return view('admin.ai-supervision.index', [
            'model' => (string) config('ai.openai.model'),
            'enabled' => (bool) config('ai.supervision.enabled', true),
            'content' => $data['content'],
            'result' => $result,
            'supervisionError' => $error,
        ]);
    }
}
