<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogTodoThread extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'todo_id',
        'user_id',
        'body',
    ];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(BlogTodo::class, 'todo_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
