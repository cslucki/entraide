<?php

namespace App\Models;

use Database\Factories\SystemEmailTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemEmailTemplate extends Model
{
    /** @use HasFactory<SystemEmailTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'subject',
        'content_html',
        'variables',
        'enabled',
    ];

    protected $casts = [
        'variables' => 'array',
        'enabled' => 'boolean',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
