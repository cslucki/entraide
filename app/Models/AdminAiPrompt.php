<?php

namespace App\Models;

use Database\Factories\AdminAiPromptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminAiPrompt extends Model
{
    /** @use HasFactory<AdminAiPromptFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'scenario_id',
        'name',
        'description',
        'prompt_text',
        'version',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByScenario($query, string $scenarioId)
    {
        return $query->where('scenario_id', $scenarioId);
    }
}
