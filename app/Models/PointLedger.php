<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointLedger extends Model
{
    use HasUuids;

    protected $table = 'point_ledger';

    public $timestamps = false;

    protected $fillable = ['user_id', 'transaction_id', 'delta', 'reason'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'delta' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
