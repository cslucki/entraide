<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\CrmStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * TASK-1414 — CRM-2 : le pipeline de statuts d'une Organization, et le seul
 * chemin qui change le statut d'un Contact.
 *
 * Regles :
 * - le pipeline est seme UNE fois, dans la locale de l'Organization, et
 *   volontairement banal (arbitrage MASTER Q6) : « RDV-Atelier » est une etape
 *   de funnel CyberWorkers, pas une primitive CRM ;
 * - un statut d'une autre Organization est REFUSE partout (fail closed) ;
 * - chaque changement ecrit un evenement `status_changed` (ancien → nouveau,
 *   auteur, horodatage) — et un changement vers le meme statut n'ecrit rien ;
 * - un statut se desactive, ne se supprime pas ; le statut par defaut ne se
 *   desactive pas (un pipeline sans entree rendrait chaque creation ambigue).
 */
class CrmStatusService
{
    /** Cles de traduction du pipeline initial, dans l'ordre. */
    public const DEFAULT_PIPELINE = ['new', 'to_contact', 'in_progress', 'quote_sent', 'client', 'lost'];

    /**
     * Idempotent : une Organization qui possede deja un statut (actif ou non)
     * garde son pipeline tel quel — on ne re-seme jamais par-dessus.
     */
    public function ensureDefaultPipeline(Organization $organization): Collection
    {
        $existing = CrmStatus::forOrganization($organization)->ordered()->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $locale = $this->localeOf($organization);

        return DB::transaction(function () use ($organization, $locale) {
            $created = collect();

            foreach (self::DEFAULT_PIPELINE as $index => $key) {
                $created->push(CrmStatus::create([
                    'organization_id' => $organization->id,
                    'label' => __('crm.default_status.'.$key, [], $locale),
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'is_default' => $index === 0,
                ]));
            }

            return $created;
        });
    }

    public function defaultStatus(Organization $organization): CrmStatus
    {
        $pipeline = $this->ensureDefaultPipeline($organization);

        return $pipeline->first(fn (CrmStatus $status) => $status->is_default && $status->is_active)
            ?? $pipeline->first(fn (CrmStatus $status) => $status->is_active)
            ?? throw new LogicException('This Organization has no active CRM status.');
    }

    public function create(Organization $organization, string $label, ?string $color = null): CrmStatus
    {
        $label = $this->cleanLabel($label);
        $this->guardLabelUnique($organization, $label);

        $next = (int) CrmStatus::forOrganization($organization)->max('sort_order') + 1;

        return CrmStatus::create([
            'organization_id' => $organization->id,
            'label' => $label,
            'sort_order' => $next,
            'is_active' => true,
            'is_default' => false,
            'color' => $this->cleanColor($color),
        ]);
    }

    /**
     * Renommer ne reecrit pas l'histoire : les evenements passes portent les
     * libelles de leur epoque dans leur payload.
     */
    public function rename(CrmStatus $status, string $label): CrmStatus
    {
        $label = $this->cleanLabel($label);
        $this->guardLabelUnique($status->organization, $label, $status);

        $status->update(['label' => $label]);

        return $status;
    }

    /**
     * TASK-1419 — libelle et couleur en un geste (l'UI les edite ensemble).
     * Une couleur est « #rrggbb » ou null ; le libelle passe par `rename`.
     */
    public function edit(CrmStatus $status, string $label, ?string $color): CrmStatus
    {
        $this->rename($status, $label);
        $status->update(['color' => $this->cleanColor($color)]);

        return $status;
    }

    /** Une couleur est « #rrggbb » (rangee en minuscules) ou null. */
    private function cleanColor(?string $color): ?string
    {
        $color = trim((string) $color);

        if ($color === '') {
            return null;
        }

        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new LogicException('A CRM status color must be #rrggbb.');
        }

        return strtolower($color);
    }

    /**
     * L'ordre complet est fourni par l'Organization ; un id etranger a
     * l'Organization fait echouer TOUT le reordonnancement (rien n'est ecrit).
     */
    public function reorder(Organization $organization, array $orderedIds): Collection
    {
        $own = CrmStatus::forOrganization($organization)->pluck('id');
        $foreign = collect($orderedIds)->diff($own);

        if ($foreign->isNotEmpty()) {
            throw new LogicException('Cannot reorder statuses that do not belong to this Organization.');
        }

        DB::transaction(function () use ($orderedIds, $organization) {
            foreach (array_values($orderedIds) as $index => $id) {
                CrmStatus::forOrganization($organization)->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return CrmStatus::forOrganization($organization)->ordered()->get();
    }

    public function deactivate(CrmStatus $status): CrmStatus
    {
        if ($status->is_default) {
            throw new LogicException('The default CRM status cannot be deactivated; choose another default first.');
        }

        $status->update(['is_active' => false]);

        return $status;
    }

    public function activate(CrmStatus $status): CrmStatus
    {
        $status->update(['is_active' => true]);

        return $status;
    }

    public function setDefault(CrmStatus $status): CrmStatus
    {
        if (! $status->is_active) {
            throw new LogicException('An inactive CRM status cannot be the default.');
        }

        DB::transaction(function () use ($status) {
            CrmStatus::forOrganization($status->organization_id)->whereKeyNot($status->id)->update(['is_default' => false]);
            $status->update(['is_default' => true]);
        });

        return $status;
    }

    /**
     * Change le statut d'un Contact et l'ecrit dans la timeline. Retourne
     * l'evenement, ou null si le Contact portait deja ce statut (rien n'est
     * ecrit : un « changement » vers soi-meme n'est pas un fait).
     */
    public function changeStatus(CrmContact $contact, CrmStatus $status, ?User $actor = null): ?CrmContactEvent
    {
        if ($status->organization_id !== $contact->organization_id) {
            throw new LogicException('A CRM status can only be applied to a contact of the same Organization.');
        }

        if (! $status->is_active) {
            throw new LogicException('An inactive CRM status cannot be applied.');
        }

        if ($contact->status_id === $status->id) {
            return null;
        }

        return DB::transaction(function () use ($contact, $status, $actor) {
            $previous = $contact->status_id ? CrmStatus::find($contact->status_id) : null;

            $contact->status_id = $status->id;
            $contact->save();

            return CrmContactEvent::create([
                'organization_id' => $contact->organization_id,
                'crm_contact_id' => $contact->id,
                'type' => CrmContactEvent::TYPE_STATUS_CHANGED,
                'author_user_id' => $actor?->id,
                'occurred_at' => now(),
                'payload' => [
                    'from_status_id' => $previous?->id,
                    'from_label' => $previous?->label,
                    'to_status_id' => $status->id,
                    'to_label' => $status->label,
                ],
            ]);
        });
    }

    private function guardLabelUnique(Organization $organization, string $label, ?CrmStatus $except = null): void
    {
        $query = CrmStatus::forOrganization($organization)->where('label', $label);

        if ($except !== null) {
            $query->whereKeyNot($except->id);
        }

        if ($query->exists()) {
            throw new LogicException("A CRM status labelled [{$label}] already exists in this Organization.");
        }
    }

    private function cleanLabel(string $label): string
    {
        $label = trim($label);

        if ($label === '' || mb_strlen($label) > 60) {
            throw new LogicException('A CRM status label must be 1 to 60 characters.');
        }

        return $label;
    }

    private function localeOf(Organization $organization): string
    {
        $locale = trim((string) $organization->locale);

        return $locale !== '' ? $locale : (string) config('app.fallback_locale', 'fr');
    }
}
