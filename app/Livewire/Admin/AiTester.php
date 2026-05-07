<?php

namespace App\Livewire\Admin;

use App\Services\AI\AIIntentService;
use Livewire\Component;

class AiTester extends Component
{
    public string $prompt = '';
    public ?array $result = null;
    public bool $loading = false;

    public function test(AIIntentService $aiService)
    {
        if (empty(trim($this->prompt))) {
            return;
        }

        $this->loading = true;
        $this->result = null;

        $this->result = $aiService->classify($this->prompt);
        $this->loading = false;
    }

    public function render()
    {
        return view('livewire.admin.ai-tester');
    }
}
