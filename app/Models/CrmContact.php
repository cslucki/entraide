<?php

namespace App\Models;

use Database\Factories\CrmContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TASK-1413 — CRM-1 : « qu'est-ce que je sais de ma relation avec cette
 * personne, ou en sommes-nous, et quelle est ma prochaine action ? ».
 *
 * Un Contact est un objet de RELATION appartenant a une Organization. Il
 * n'est PAS un User : un prospect existe avant tout compte, et un membre
 * n'est pas automatiquement un Contact (arbitrage MASTER 07/09, Q1 : le CRM
 * n'est pas un annuaire bis). `user_id` est un lien optionnel, pose quand le
 * prospect ouvre un compte dans la MEME Organization.
 *
 * Tenant : `organization_id` est NOT NULL et n'est JAMAIS deduit d'un contexte
 * implicite — ce modele n'utilise pas `HasOrganizationId`, volontairement :
 * un Contact sans Organization doit echouer, pas atterrir « quelque part ».
 *
 * Les notes, statuts, prochaines actions et messages arrivent dans les
 * tranches suivantes (CRM-2, CRM-3, CRM-6…) ; cette tranche ne porte que
 * l'identite, la provenance, la contactabilite et le lien au compte.
 */
class CrmContact extends Model
{
    /** @use HasFactory<CrmContactFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SIGNUP = 'signup';

    public const SOURCE_WORKSHOP = 'workshop';

    public const SOURCE_SHELL_WELCOME = 'shell_welcome';

    public const SOURCE_IMPORT = 'import';

    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_SIGNUP,
        self::SOURCE_WORKSHOP,
        self::SOURCE_SHELL_WELCOME,
        self::SOURCE_IMPORT,
    ];

    protected $fillable = [
        'organization_id',
        'user_id',
        'created_by_user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_normalized',
        'company',
        'status_id',
        'source',
        'source_ref',
        'do_not_contact_at',
        'last_interaction_at',
    ];

    protected $casts = [
        'do_not_contact_at' => 'datetime',
        'last_interaction_at' => 'datetime',
    ];

    // ── Relations ───────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** TASK-1414 — le statut COURANT fait autorite ; la timeline est la memoire. */
    public function status(): BelongsTo
    {
        return $this->belongsTo(CrmStatus::class, 'status_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CrmContactEvent::class, 'crm_contact_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    /**
     * TOUTE lecture CRM commence ici. Il n'existe pas de lecture « toutes
     * Organizations » en dehors de la vue SuperAdmin (CRM-15), qui sera
     * explicite.
     */
    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $query->where('crm_contacts.organization_id', $id);
    }

    public function scopeContactable(Builder $query): Builder
    {
        return $query->whereNull('crm_contacts.do_not_contact_at');
    }

    // ── Etat ────────────────────────────────────────────────────────────────

    public function isLinkedToAccount(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Fail closed : un Contact marque « ne pas contacter » n'est contactable
     * par AUCUN canal. Le contrat complet (opt-in WhatsApp, motif, auteur)
     * arrive en CRM-13 ; la barriere existe des maintenant.
     */
    public function isContactable(): bool
    {
        return $this->do_not_contact_at === null;
    }

    public function hasInternationalPhone(): bool
    {
        return is_string($this->phone_normalized) && str_starts_with($this->phone_normalized, '+');
    }

    public function getFullNameAttribute(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : ($this->email ?? $this->phone ?? '');
    }

    // ── Normalisation ───────────────────────────────────────────────────────

    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /**
     * Normalisation STRICTEMENT syntaxique (arbitrage MASTER Q3) : un « + »
     * de tete est conserve, puis les chiffres, rien d'autre. Ce n'est PAS du
     * E.164 — « 06 12 34 56 78 » devient « 0612345678 », jamais
     * « +33612345678 » : sans pays d'autorite, deviner serait mentir. Le brut
     * reste dans `phone` ; cette forme ne sert qu'a comparer, et seulement
     * quand elle est explicitement internationale (voir CrmContactService).
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $plus = str_starts_with($phone, '+') ? '+' : '';
        $digits = preg_replace('/\D+/', '', $phone);

        return $digits === '' ? null : $plus.$digits;
    }
}
