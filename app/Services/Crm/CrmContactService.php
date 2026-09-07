<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\Organization;
use App\Models\User;
use LogicException;

/**
 * TASK-1413 — CRM-1 : la seule porte d'ecriture des Contacts.
 *
 * Deux regles que tout le Mini-CRM herite d'ici :
 *
 * 1. TENANT. Chaque recherche commence par `organization_id`. Aucune
 *    deduplication ne regarde `users.email` (unique PLATEFORME) ni une autre
 *    Organization : on ne revele jamais qu'un email existe ailleurs.
 *
 * 2. PAS DE FAUX USER. Un Contact est relie a un compte seulement quand ce
 *    compte existe, dans la MEME Organization, et jamais cree pour l'occasion.
 */
class CrmContactService
{
    public function __construct(
        private readonly CrmStatusService $statuses,
        private readonly CrmTimelineService $timeline,
    ) {}

    /**
     * Trouve ou cree le Contact d'une Organization.
     *
     * Deduplication, dans l'ordre : email normalise ; puis telephone normalise
     * SEULEMENT s'il est explicitement international (« + ») — sans pays
     * d'autorite, « 0612… » n'est pas comparable a « +33612… » (MASTER Q3).
     * Un Contact supprime (soft delete) qui correspond est RESTAURE plutot que
     * doublonne : l'historique ne disparait pas en silence.
     *
     * Les attributs fournis ne remplissent que les champs encore vides d'un
     * Contact retrouve : ce que l'OrgAdmin a saisi n'est pas ecrase par une
     * provenance automatique.
     */
    public function findOrCreate(Organization $organization, array $attributes, ?User $actor = null): CrmContact
    {
        $email = CrmContact::normalizeEmail($attributes['email'] ?? null);
        $phone = $this->clean($attributes['phone'] ?? null);
        $phoneNormalized = CrmContact::normalizePhone($phone);

        $existing = $this->match($organization, $email, $phoneNormalized);

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->fill(array_filter([
                'first_name' => $existing->first_name ?? $this->clean($attributes['first_name'] ?? null),
                'last_name' => $existing->last_name ?? $this->clean($attributes['last_name'] ?? null),
                'email' => $existing->email ?? $email,
                'phone' => $existing->phone ?? $phone,
                'phone_normalized' => $existing->phone_normalized ?? $phoneNormalized,
                'company' => $existing->company ?? $this->clean($attributes['company'] ?? null),
            ], fn ($value) => $value !== null));
            $existing->save();

            return $existing;
        }

        $source = $attributes['source'] ?? CrmContact::SOURCE_MANUAL;

        if (! in_array($source, CrmContact::SOURCES, true)) {
            throw new LogicException("Unknown CRM contact source [{$source}].");
        }

        // TASK-1414 — un Contact nait avec le statut par defaut de SON
        // Organization (arbitrage MASTER Q7) : une liste sans statut serait
        // ambigue des la premiere ligne.
        return CrmContact::create([
            'organization_id' => $organization->id,
            'created_by_user_id' => $actor?->id,
            'status_id' => $this->statuses->defaultStatus($organization)->id,
            'first_name' => $this->clean($attributes['first_name'] ?? null),
            'last_name' => $this->clean($attributes['last_name'] ?? null),
            'email' => $email,
            'phone' => $phone,
            'phone_normalized' => $phoneNormalized,
            'company' => $this->clean($attributes['company'] ?? null),
            'source' => $source,
            'source_ref' => $this->clean($attributes['source_ref'] ?? null),
        ]);
    }

    public function findByEmail(Organization $organization, ?string $email): ?CrmContact
    {
        $email = CrmContact::normalizeEmail($email);

        if ($email === null) {
            return null;
        }

        return CrmContact::forOrganization($organization)->where('email', $email)->first();
    }

    /**
     * Relie un Contact a un membre de la MEME Organization. Idempotent pour le
     * meme membre ; refuse un membre d'ailleurs et refuse de « voler » un
     * Contact deja relie a quelqu'un d'autre.
     */
    public function linkToUser(CrmContact $contact, User $user): CrmContact
    {
        if ($contact->organization_id !== $user->organization_id) {
            throw new LogicException('A CRM contact can only be linked to a member of the same Organization.');
        }

        if ($contact->user_id !== null && $contact->user_id !== $user->id) {
            throw new LogicException('This CRM contact is already linked to another member.');
        }

        $contact->user_id = $user->id;
        $contact->first_name ??= $this->clean($user->first_name);
        $contact->last_name ??= $this->clean($user->name);
        $contact->save();

        return $contact;
    }

    /**
     * Inscription : RELIER seulement (MASTER Q1). On cherche, dans
     * l'Organization du nouveau membre, le Contact non relie qui porte son
     * email ; s'il n'y en a pas, on ne cree RIEN — un membre n'est un Contact
     * que par decision de l'OrgAdmin ou par provenance commerciale.
     */
    public function linkOnRegistration(User $user): ?CrmContact
    {
        if ($user->organization_id === null) {
            return null;
        }

        $email = CrmContact::normalizeEmail($user->email);

        if ($email === null) {
            return null;
        }

        $contact = CrmContact::forOrganization($user->organization_id)
            ->where('email', $email)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))
            ->first();

        if ($contact === null) {
            return null;
        }

        $linked = $this->linkToUser($contact, $user);

        // TASK-1415 — le fait « compte cree » entre dans la timeline, une fois.
        $this->timeline->recordOnce($linked, CrmContactEvent::TYPE_ACCOUNT_CREATED, ['user_id' => $user->id]);

        if ($user->hasVerifiedEmail()) {
            $this->timeline->recordOnce($linked, CrmContactEvent::TYPE_EMAIL_VERIFIED);
        }

        return $linked;
    }

    private function match(Organization $organization, ?string $email, ?string $phoneNormalized): ?CrmContact
    {
        $scope = CrmContact::withTrashed()->forOrganization($organization);

        if ($email !== null) {
            $byEmail = (clone $scope)->where('email', $email)->first();

            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        if ($phoneNormalized !== null && str_starts_with($phoneNormalized, '+')) {
            return (clone $scope)->where('phone_normalized', $phoneNormalized)->orderBy('created_at')->first();
        }

        return null;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
