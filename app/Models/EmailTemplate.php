<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    /**
     * TASK-1694 bug Z — les langues proposees pour un gabarit de l'Emailer.
     *
     * `null` est une troisieme valeur legitime : « langue non precisee ». Les
     * lignes anterieures au 24/10 sont dans cet etat, et un gabarit sans langue
     * reste propose dans les DEUX langues a l'envoi — mieux vaut montrer un
     * gabarit dont on ignore la langue que le faire disparaitre d'un ecran.
     */
    public const LOCALES = ['fr', 'en'];

    protected $fillable = [
        'slug',
        'name',
        'locale',
        'subject',
        'content_html',
        'variables',
        'organization_id',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected $casts = [
        'variables' => 'array',
    ];

    public function logs()
    {
        return $this->hasMany(EmailLog::class, 'template_id');
    }
}
