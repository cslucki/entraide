<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIPrompt extends Model
{
    use HasUuids;

    protected $table = 'ai_prompts';

    protected $fillable = [
        'type',
        'content',
        'version',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function getLatest(string $type): ?self
    {
        return static::where('type', $type)->orderByDesc('version')->first();
    }
}
