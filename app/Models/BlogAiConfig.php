<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogAiConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'generate_enabled',
        'correct_enabled',
        'generate_limit',
        'correct_limit',
        'dialogue_message_limit',
    ];

    protected function casts(): array
    {
        return [
            'generate_enabled' => 'boolean',
            'correct_enabled' => 'boolean',
            'generate_limit' => 'integer',
            'correct_limit' => 'integer',
            'dialogue_message_limit' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function forOrganization(string $orgId): self
    {
        return static::firstOrCreate(
            ['organization_id' => $orgId],
            [
                'generate_enabled' => true,
                'correct_enabled' => true,
                'generate_limit' => 3,
                'correct_limit' => 3,
                'dialogue_message_limit' => 5,
            ],
        );
    }
}
