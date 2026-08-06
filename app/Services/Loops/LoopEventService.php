<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\LoopMember;
use App\Models\User;
use App\Support\Loops\LoopPermissionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tout ce qu'on peut faire d'un Evenement — et rien d'autre.
 *
 * Le composant Livewire et la page d'agenda appellent ici ; ils n'ecrivent
 * jamais. Les regles qui comptent — une Boucle privee ne publie pas a
 * l'Organization, un Evenement avec reponses ne se supprime pas, une reponse ne
 * se duplique pas — doivent tenir sur une route appelee directement autant que
 * sur un clic, et elles ne le peuvent que si elles vivent d'un seul cote.
 *
 * **Les dates entrent en heure locale et sortent en UTC.** La conversion est
 * faite ici, une fois, avec le fuseau que l'Evenement declare. Aucun appelant
 * n'a a y penser.
 */
class LoopEventService
{
    public function __construct(private LoopPermissionResolver $permissions) {}

    // ── Autorisations ───────────────────────────────────────────────────────

    public function canView(User $user, Loop $loop): bool
    {
        return $this->permissions->can($user, $loop, 'events.view');
    }

    public function canCreate(User $user, Loop $loop): bool
    {
        return $this->permissions->can($user, $loop, 'events.create');
    }

    public function canRespond(User $user, Loop $loop): bool
    {
        return $this->permissions->can($user, $loop, 'events.respond');
    }

    /**
     * Publier a l'echelle de l'Organization.
     *
     * Deux conditions, et la seconde ne se negocie pas : la permission, **et**
     * une Boucle qui n'est pas privee. Une Boucle privee ne remonte jamais, quel
     * que soit le rang de celui qui demande.
     */
    public function canPublishToOrganization(User $user, Loop $loop): bool
    {
        return ! $loop->isPrivate()
            && $this->permissions->can($user, $loop, 'events.publish_organization');
    }

    /** Piloter *cet* Evenement : son auteur, ou qui gere tous ceux de la Boucle. */
    public function canManageEvent(User $user, LoopEvent $event, Loop $loop): bool
    {
        if ($this->permissions->can($user, $loop, 'events.manage')) {
            return true;
        }

        return $event->created_by === $user->id
            && $this->permissions->can($user, $loop, 'events.create');
    }

    // ── Ecritures ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws EventException
     */
    public function create(User $user, Loop $loop, array $data): LoopEvent
    {
        $this->assert($this->canCreate($user, $loop), 'events.error_not_allowed');
        $this->assert($this->isActiveMember($user, $loop), 'events.error_not_member');

        $clean = $this->validated($data, $user, $loop);

        return DB::transaction(fn () => LoopEvent::create($clean + [
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'created_by' => $user->id,
            'status' => LoopEvent::STATUS_SCHEDULED,
        ]));
    }

    /**
     * Modifier — sans jamais toucher aux reponses.
     *
     * Elles survivent a tout changement, y compris de date : reinitialiser les
     * participants serait leur retirer une parole qu'ils ont donnee. L'ecran
     * previent, il ne rembobine pas.
     *
     * @param  array<string, mixed>  $data
     * @return array{event: LoopEvent, notable: bool} `notable` dit si le
     *                                                changement merite un message ChatLoop
     *
     * @throws EventException
     */
    public function update(User $user, LoopEvent $event, Loop $loop, array $data): array
    {
        $this->assert($this->canManageEvent($user, $event, $loop), 'events.error_not_allowed');
        $this->assert(! $event->isCancelled(), 'events.error_cancelled');

        $clean = $this->validated($data, $user, $loop, $event);

        return DB::transaction(function () use ($event, $clean) {
            $fresh = LoopEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();

            $this->assert(! $fresh->isCancelled(), 'events.error_cancelled');

            // Ce qui merite d'etre annonce : la date, le lieu, le lien. Pas une
            // faute de frappe dans la description — une Boucle n'a pas besoin
            // d'un message a chaque virgule.
            $notable = false;
            foreach (['starts_at', 'ends_at', 'location', 'meeting_url', 'timezone', 'format'] as $field) {
                if ((string) ($fresh->{$field} ?? '') !== (string) ($clean[$field] ?? '')) {
                    $notable = true;
                    break;
                }
            }

            $fresh->update($clean);

            return ['event' => $fresh->fresh(), 'notable' => $notable];
        });
    }

    /**
     * Changer la portee.
     *
     * Isole de update() parce que la question posee n'est pas la meme : ici on
     * change *qui voit*, et cela demande sa propre permission et sa propre
     * confirmation a l'ecran.
     *
     * @throws EventException
     */
    public function changeVisibility(User $user, LoopEvent $event, Loop $loop, string $visibility): LoopEvent
    {
        $this->assert($this->canManageEvent($user, $event, $loop), 'events.error_not_allowed');
        $this->assert(in_array($visibility, LoopEvent::VISIBILITIES, true), 'events.error_visibility');
        $this->assert(! $event->isCancelled(), 'events.error_cancelled');

        if ($visibility === LoopEvent::VISIBILITY_ORGANIZATION) {
            // Le refus qui compte le plus de cette tache.
            $this->assert($this->canPublishToOrganization($user, $loop), 'events.error_private_loop');
        }

        return DB::transaction(function () use ($event, $visibility) {
            $fresh = LoopEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();

            // Les reponses ne bougent pas : quelqu'un qui perd l'acces garde sa
            // reponse dans l'historique, il cesse simplement de voir
            // l'Evenement.
            $fresh->update(['visibility' => $visibility]);

            return $fresh->fresh();
        });
    }

    /** @throws EventException */
    public function respond(User $user, LoopEvent $event, Loop $loop, string $response): LoopEventResponse
    {
        $this->assert(in_array($response, LoopEventResponse::RESPONSES, true), 'events.error_response');
        $this->assert($this->canRespondTo($user, $event, $loop), 'events.error_not_allowed');

        return DB::transaction(function () use ($user, $event, $response) {
            $fresh = LoopEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();

            // Relu sous verrou : une reponse qui part au moment de l'annulation
            // ne doit pas se glisser entre la lecture du statut et l'ecriture.
            $this->assert(! $fresh->isCancelled(), 'events.error_cancelled');

            // `updateOrCreate` sur la contrainte d'unicite : deux reponses
            // simultanees de la meme personne convergent vers un seul objet.
            return LoopEventResponse::updateOrCreate(
                ['event_id' => $fresh->id, 'user_id' => $user->id],
                ['organization_id' => $fresh->organization_id, 'response' => $response],
            );
        });
    }

    /** @throws EventException */
    public function cancel(User $user, LoopEvent $event, Loop $loop): array
    {
        $this->assert($this->canManageEvent($user, $event, $loop), 'events.error_not_allowed');

        return DB::transaction(function () use ($user, $event) {
            $fresh = LoopEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();

            // Une seconde annulation n'est pas une erreur : l'Evenement est deja
            // dans l'etat voulu. On dit seulement qu'il n'y a rien a annoncer.
            if ($fresh->isCancelled()) {
                return ['event' => $fresh, 'changed' => false];
            }

            $fresh->update([
                'status' => LoopEvent::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
            ]);

            return ['event' => $fresh->fresh(), 'changed' => true];
        });
    }

    /**
     * Supprimer un Evenement auquel personne n'a repondu.
     *
     * Des qu'une reponse existe, on annule : effacer effacerait la parole de
     * quelqu'un d'autre.
     *
     * @throws EventException
     */
    public function delete(User $user, LoopEvent $event, Loop $loop): void
    {
        $this->assert($this->canManageEvent($user, $event, $loop), 'events.error_not_allowed');

        DB::transaction(function () use ($event) {
            $fresh = LoopEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();

            $this->assert(! $fresh->hasResponses(), 'events.error_delete_answered');

            $fresh->delete();
        });
    }

    // ── Lectures ────────────────────────────────────────────────────────────

    /**
     * Les Evenements d'une Boucle.
     *
     * @return Collection<int, LoopEvent>
     */
    public function forLoop(Loop $loop): Collection
    {
        return LoopEvent::where('loop_id', $loop->id)
            ->with('creator')
            ->withCount(['responses as going_count' => fn ($q) => $q->where('response', LoopEventResponse::GOING)])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * L'agenda d'une personne dans son Organization.
     *
     * Deux ensembles reunis, jamais plus : les Evenements remontes au niveau
     * Organization, et ceux des Boucles dont elle est membre. Un Evenement
     * `loop` d'une Boucle etrangere n'apparait pas, meme si son identifiant est
     * connu.
     *
     * @return Collection<int, LoopEvent>
     */
    public function agendaFor(User $user, string $organizationId): Collection
    {
        $memberLoopIds = LoopMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('loop_id');

        return LoopEvent::where('loop_events.organization_id', $organizationId)
            ->where(function ($q) use ($memberLoopIds) {
                $q->where('visibility', LoopEvent::VISIBILITY_ORGANIZATION)
                    ->orWhereIn('loop_id', $memberLoopIds);
            })
            ->with(['loop', 'creator'])
            ->withCount(['responses as going_count' => fn ($q) => $q->where('response', LoopEventResponse::GOING)])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Qui vient, qui hesite, qui ne vient pas.
     *
     * @return array{going: int, maybe: int, not_going: int}
     */
    public function counts(LoopEvent $event): array
    {
        $rows = LoopEventResponse::where('event_id', $event->id)
            ->selectRaw('response, count(*) as total')
            ->groupBy('response')
            ->pluck('total', 'response');

        return [
            'going' => (int) ($rows[LoopEventResponse::GOING] ?? 0),
            'maybe' => (int) ($rows[LoopEventResponse::MAYBE] ?? 0),
            'not_going' => (int) ($rows[LoopEventResponse::NOT_GOING] ?? 0),
        ];
    }

    /**
     * Les noms, par reponse. Charge a la demande seulement.
     *
     * @return array<string, array<int, string>>
     */
    public function respondents(LoopEvent $event): array
    {
        $out = [LoopEventResponse::GOING => [], LoopEventResponse::MAYBE => [], LoopEventResponse::NOT_GOING => []];

        foreach (LoopEventResponse::where('event_id', $event->id)->with('user')->get() as $row) {
            $out[$row->response][] = $row->user?->publicDisplayName() ?? __('events.unknown_person');
        }

        foreach ($out as &$names) {
            sort($names);
        }

        return $out;
    }

    public function responseOf(User $user, LoopEvent $event): ?string
    {
        return LoopEventResponse::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->value('response');
    }

    /**
     * Peut-on repondre a cet Evenement ?
     *
     * Un Evenement remonte au niveau Organization se repond par tout membre
     * actif de l'Organization, meme s'il n'est pas dans la Boucle — c'est le
     * sens meme de l'avoir remonte. Mais la Boucle doit rester ecrivable :
     * archivee, plus personne ne repond.
     */
    public function canRespondTo(User $user, LoopEvent $event, Loop $loop): bool
    {
        if ($event->isCancelled() || $loop->isArchived()) {
            return false;
        }

        if ($this->canRespond($user, $loop)) {
            return true;
        }

        return $event->isOrganizationWide()
            && $user->organization_id === $event->organization_id
            && ! $user->isDeactivated();
    }

    /** Cette personne a-t-elle le droit de voir cet Evenement ? */
    public function canViewEvent(User $user, LoopEvent $event, Loop $loop): bool
    {
        if ($this->canView($user, $loop)) {
            return true;
        }

        return $event->isOrganizationWide()
            && $user->organization_id === $event->organization_id
            && ! $user->isDeactivated();
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function isActiveMember(User $user, Loop $loop): bool
    {
        return LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Nettoyer et convertir ce qui arrive du formulaire.
     *
     * C'est ici que l'heure locale devient de l'UTC, une fois, avec le fuseau
     * declare. Les appelants n'ont jamais a convertir eux-memes — c'est ainsi
     * qu'on evite qu'un chemin oublie de le faire.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws EventException
     */
    private function validated(array $data, User $user, Loop $loop, ?LoopEvent $existing = null): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $this->assert($title !== '', 'events.error_title_required');

        $format = (string) ($data['format'] ?? LoopEvent::FORMAT_IN_PERSON);
        $this->assert(in_array($format, LoopEvent::FORMATS, true), 'events.error_format');

        // Le fuseau est requis : une chaine vide n'est pas un lieu, et un
        // formulaire qui n'en envoie pas est un formulaire casse — pas une
        // occasion de deviner.
        $timezone = trim((string) ($data['timezone'] ?? ''));
        $this->assert($this->isValidTimezone($timezone), 'events.error_timezone');

        $startsAt = $this->toUtc($data['starts_at'] ?? null, $timezone);
        $this->assert($startsAt !== null, 'events.error_start_required');

        $endsAt = $this->toUtc($data['ends_at'] ?? null, $timezone);
        // Egal ne suffit pas : un evenement de duree nulle n'a pas de fin, il
        // n'en a pas.
        $this->assert($endsAt === null || $endsAt->greaterThan($startsAt), 'events.error_end_before_start');

        $location = $this->trimOrNull($data['location'] ?? null);
        $meetingUrl = $this->trimOrNull($data['meeting_url'] ?? null);

        if (in_array($format, [LoopEvent::FORMAT_IN_PERSON, LoopEvent::FORMAT_HYBRID], true)) {
            $this->assert($location !== null, 'events.error_location_required');
        }

        if (in_array($format, [LoopEvent::FORMAT_ONLINE, LoopEvent::FORMAT_HYBRID], true)) {
            $this->assert($meetingUrl !== null, 'events.error_meeting_url_required');
            $this->assert($this->isSafeUrl($meetingUrl), 'events.error_meeting_url_invalid');
        }

        $visibility = (string) ($data['visibility'] ?? $existing?->visibility ?? LoopEvent::VISIBILITY_LOOP);
        $this->assert(in_array($visibility, LoopEvent::VISIBILITIES, true), 'events.error_visibility');

        if ($visibility === LoopEvent::VISIBILITY_ORGANIZATION) {
            $this->assert($this->canPublishToOrganization($user, $loop), 'events.error_private_loop');
        }

        return [
            'title' => Str::limit($title, 255, ''),
            'description' => $this->trimOrNull($data['description'] ?? null),
            'format' => $format,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
            'location' => $location !== null ? Str::limit($location, 255, '') : null,
            'meeting_url' => $meetingUrl,
            'visibility' => $visibility,
        ];
    }

    /**
     * « 2026-08-12 19:00 » a Paris devient l'instant UTC correspondant.
     *
     * Passer par le fuseau plutot que par un decalage fixe est ce qui fait que
     * les changements d'heure se resolvent tout seuls.
     */
    private function toUtc(mixed $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, $timezone)->utc();
        } catch (\Throwable) {
            throw new EventException(__('events.error_date_invalid'));
        }
    }

    /**
     * Un identifiant IANA reconnu par ce PHP.
     *
     * Delegue au modele, qui porte la meme regle pour le formulaire : une seule
     * definition de ce qu'est un fuseau valide. Le navigateur propose, PHP
     * dispose — un decalage brut (`+02:00`), un sigle (`CST`) ou une chaine
     * forgee sont refuses ici, pas plus loin.
     */
    private function isValidTimezone(string $timezone): bool
    {
        return LoopEvent::isValidTimezone($timezone);
    }

    /**
     * Un lien de reunion, pas n'importe quelle chaine.
     *
     * `http` et `https` seulement : `javascript:` et `data:` n'ont rien a faire
     * dans un attribut `href` qu'on rendra cliquable.
     */
    private function isSafeUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /** @throws EventException */
    private function assert(bool $condition, string $translationKey): void
    {
        if (! $condition) {
            throw new EventException(__($translationKey));
        }
    }
}
