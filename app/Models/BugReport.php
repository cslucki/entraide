<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-1904 — « Signalement » : dispositif unique et confidentiel (bugs,
 * comportements, contenus, securite des enfants...). Un signalement n'est lu
 * que par son auteur, par le superadmin, et — sauf `child_safety` — par les
 * administrateurs de SON organisation.
 */
class BugReport extends Model
{
    use HasUuids;

    public const CHILD_SAFETY = 'child_safety';

    /** Ordre d'affichage du formulaire. */
    public const CATEGORIES = [
        'technical_bug',
        'inappropriate_behavior',
        'harassment_threats',
        'inappropriate_content',
        self::CHILD_SAFETY,
        'spam_fraud',
        'other',
    ];

    protected $fillable = [
        'organization_id',
        'reporter_id',
        'category',
        'reason',
        'details',
        'page_url',
        'user_agent',
        'status',
        'resolution_notes',
        'fixed_at',
    ];

    protected function casts(): array
    {
        return [
            'fixed_at' => 'datetime',
            'child_safety_alerted_at' => 'datetime',
        ];
    }

    /**
     * TASK-1905 (decision MASTER D4) — un signalement « Securite des enfants »
     * n'est JAMAIS supprime definitivement, par aucun chemin Eloquent.
     */
    protected static function booted(): void
    {
        static::deleting(function (BugReport $report): void {
            if ($report->isChildSafety()) {
                throw new \LogicException('TASK-1905 : un signalement « Securite des enfants » ne peut pas etre supprime.');
            }
        });
    }

    /** TASK-1905 — historique des traitements (notes INTERNES). */
    public function actions(): HasMany
    {
        return $this->hasMany(BugReportAction::class)->orderBy('created_at')->orderBy('id');
    }

    /** TASK-1905 — trace une action dans l'historique. */
    public function recordAction(string $action, ?User $actor = null, ?string $note = null): BugReportAction
    {
        return $this->actions()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function isChildSafety(): bool
    {
        return $this->category === self::CHILD_SAFETY;
    }

    /** Ce que l'administrateur d'une organisation a le droit de lire (decision MASTER D3). */
    public function scopeVisibleByOrganizationAdmin(Builder $query): Builder
    {
        return $query->where('category', '!=', self::CHILD_SAFETY);
    }

    /**
     * Une URL n'est conservee — et rendue cliquable — que si elle pointe vers
     * l'hote courant : jamais de lien externe arbitraire dans le back-office.
     */
    public static function isInternalUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true)
            && is_string($host)
            && strcasecmp($host, request()->getHost()) === 0;
    }
}
