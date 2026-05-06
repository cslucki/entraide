<?php

namespace App\Models;

use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'subject',
        'content_html',
        'variables',
    ];

    protected $casts = [
        'variables' => 'array',
    ];

    public function logs()
    {
        return $this->hasMany(EmailLog::class, 'template_id');
    }
}
