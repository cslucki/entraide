<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\FakeAIProvider;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminIaDesignLabController extends Controller
{
    public function __construct(
        protected FakeAIProvider $fakeAIProvider,
    ) {
        abort_if(app()->isProduction(), 404);
    }

    public function index(): View
    {
        $scenarios = $this->fakeAIProvider->getScenarios();

        return view('admin.ia-design-lab.index', [
            'scenarios' => $scenarios,
        ]);
    }

    public function test(Request $request): View
    {
        $data = $request->validate([
            'phrase' => 'required|string|max:2000',
            'scenario' => 'nullable|string|max:100',
        ]);

        $result = $this->fakeAIProvider->analyze($data['phrase']);
        $scenarios = $this->fakeAIProvider->getScenarios();

        return view('admin.ia-design-lab.index', [
            'scenarios' => $scenarios,
            'result' => $result,
            'inputPhrase' => $data['phrase'],
            'selectedScenario' => $data['scenario'] ?? null,
        ]);
    }
}
