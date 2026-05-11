<?php

namespace App\Models;

use App\Models\Scopes\BelongsToTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'service_requests';

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToTenantScope);
    }

    protected $fillable = [
        'community_id',
        'user_id',
        'title',
        'description',
        'category_id',
        'delivery_mode',
        'budget_min',
        'budget_max',
        'deadline',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'budget_min' => 'integer',
            'budget_max' => 'integer',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'community_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'request_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RequestAttachment::class)->orderBy('order');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
