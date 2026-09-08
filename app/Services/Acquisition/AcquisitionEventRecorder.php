<?php

namespace App\Services\Acquisition;

use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\GuestConversation;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * TASK-1449 — L'UNIQUE porte d'ecriture du journal d'acquisition (Growth V3 §5,
 * MASTER Q77).
 *
 * Regles :
 *  - append-only : une ligne par fait, jamais reecrite ;
 *  - tenant-scoped : chaque dimension (Journey, visiteur, conversation, User)
 *    appartient a l'Organization du fait, sinon FAUTE DE CODE (LogicException) —
 *    jamais un fait rattache silencieusement au mauvais tenant ;
 *  - evenement borne aux 12 valeurs du CDC ; un nom inconnu = faute de code ;
 *  - faits de cycle de vie naturellement uniques dedupliques par `dedupe_key`
 *    (`guest_created:{visitor}`, `account_created:{user}`...) : le rejeu d'un
 *    evenement Laravel, un double-submit ou deux onglets n'ecrivent qu'une ligne
 *    (la contrainte unique tranche la course) ; faits repetables sans cle
 *    (`shortcut_opened` : une ligne par ouverture) ;
 *  - dimensions bornees (referrer 500, UTM 100, shortcut 32, locale 5), metadata
 *    scalaire, bornee en cles et en longueur ; aucune IP, aucun User-Agent.
 *
 * La telemetrie ne casse JAMAIS le geste produit : les producteurs appellent le
 * recorder sous `rescue()`. Le recorder lui-meme ne rattrape que la course de
 * deduplication. Non `final` : les tests substituent un journal en panne pour
 * prouver que le geste produit continue.
 */
class AcquisitionEventRecorder
{
    public const MAX_REFERRER_CHARS = 500;

    public const MAX_UTM_CHARS = 100;

    public const MAX_SHORTCUT_CHARS = 32;

    /**
     * @param  array{journey?: AcquisitionJourney|null, visitor?: GuestVisitor|null, conversation?: GuestConversation|null, user?: User|null, referrer?: string|null, utm_source?: string|null, utm_medium?: string|null, utm_campaign?: string|null, shortcut?: string|null, locale?: string|null}  $dimensions
     * @param  array<string, scalar|null>  $metadata
     * @return AcquisitionEvent|null null = fait deja journalise (deduplication)
     */
    public function record(Organization $organization, string $event, array $dimensions = [], array $metadata = [], ?string $dedupeKey = null): ?AcquisitionEvent
    {
        if (! AcquisitionEvent::isValidEvent($event)) {
            throw new LogicException("Unknown acquisition event [{$event}].");
        }

        $journey = $dimensions['journey'] ?? null;
        $visitor = $dimensions['visitor'] ?? null;
        $conversation = $dimensions['conversation'] ?? null;
        $user = $dimensions['user'] ?? null;

        $this->guardTenant($organization, $journey?->organization_id, 'journey');
        $this->guardTenant($organization, $visitor?->organization_id, 'visitor');
        $this->guardTenant($organization, $conversation?->organization_id, 'conversation');
        $this->guardTenant($organization, $user?->organization_id, 'user');

        if ($dedupeKey !== null && AcquisitionEvent::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            return AcquisitionEvent::query()->create([
                'organization_id' => $organization->getKey(),
                'event' => $event,
                'acquisition_journey_id' => $journey?->getKey(),
                'guest_visitor_id' => $visitor?->getKey(),
                'guest_conversation_id' => $conversation?->getKey(),
                'user_id' => $user?->getKey(),
                'referrer' => $this->bounded($dimensions['referrer'] ?? null, self::MAX_REFERRER_CHARS),
                'utm_source' => $this->bounded($dimensions['utm_source'] ?? null, self::MAX_UTM_CHARS),
                'utm_medium' => $this->bounded($dimensions['utm_medium'] ?? null, self::MAX_UTM_CHARS),
                'utm_campaign' => $this->bounded($dimensions['utm_campaign'] ?? null, self::MAX_UTM_CHARS),
                'shortcut' => $this->bounded($dimensions['shortcut'] ?? null, self::MAX_SHORTCUT_CHARS),
                'locale' => $this->bounded($dimensions['locale'] ?? null, 5),
                'metadata' => $this->boundedMetadata($metadata),
                'dedupe_key' => $dedupeKey === null ? null : mb_substr($dedupeKey, 0, 120),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Deux ecritures concurrentes du meme fait unique : la premiere a gagne, la seconde n'existe pas.
            return null;
        }
    }

    /**
     * Les dimensions d'attribution d'un visiteur — FIRST TOUCH, telles que figees a sa
     * creation (TASK-1447) : le journal les recopie sur chaque fait du visiteur pour
     * que la provenance survive au claim, a l'inscription et au CRM.
     *
     * @return array{journey: AcquisitionJourney|null, visitor: GuestVisitor, referrer: string|null, utm_source: string|null, utm_medium: string|null, utm_campaign: string|null, shortcut: string|null, locale: string|null}
     */
    public function visitorDimensions(GuestVisitor $visitor): array
    {
        return [
            'journey' => $visitor->acquisition_journey_id === null ? null : $visitor->acquisitionJourney,
            'visitor' => $visitor,
            'referrer' => $visitor->referrer,
            'utm_source' => $visitor->utm_source,
            'utm_medium' => $visitor->utm_medium,
            'utm_campaign' => $visitor->utm_campaign,
            'shortcut' => $visitor->shortcut,
            'locale' => $visitor->locale,
        ];
    }

    private function guardTenant(Organization $organization, ?string $organizationId, string $dimension): void
    {
        if ($organizationId !== null && $organizationId !== (string) $organization->getKey()) {
            throw new LogicException("An acquisition event cannot reference a {$dimension} of another organization.");
        }
    }

    private function bounded(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>|null
     */
    private function boundedMetadata(array $metadata): ?array
    {
        $bounded = [];
        foreach ($metadata as $key => $value) {
            if (count($bounded) >= AcquisitionEvent::METADATA_MAX_KEYS) {
                break;
            }
            if (! is_string($key) || $key === '' || ! (is_scalar($value) || $value === null)) {
                continue;
            }
            $bounded[mb_substr($key, 0, 40)] = mb_substr(trim((string) $value), 0, AcquisitionEvent::METADATA_MAX_VALUE_CHARS);
        }

        return $bounded === [] ? null : $bounded;
    }
}
