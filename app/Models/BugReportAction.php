<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1905 — une ligne de l'historique d'un signalement. La note est
 * INTERNE : elle n'est lue que dans le back-office, jamais par un membre.
 */
class BugReportAction extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    public const FIXED = 'fixed';

    public const DISMISSED = 'dismissed';

    public const RESOLVED = 'resolved';

    public const ALERT_SENT = 'alert_sent';

    public const ALERT_FAILED = 'alert_failed';

    public const ALERT_NOT_CONFIGURED = 'alert_not_configured';

    protected $fillable = ['bug_report_id', 'actor_id', 'action', 'note'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function bugReport(): BelongsTo
    {
        return $this->belongsTo(BugReport::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
