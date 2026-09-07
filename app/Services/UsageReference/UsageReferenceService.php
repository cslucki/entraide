<?php

namespace App\Services\UsageReference;

use App\Models\UsageReference;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * TASK-1439 — les SEULES primitives d'ecriture d'une UsageReference (MASTER
 * Q66) : creer un brouillon, editer un brouillon, publier, retirer. Reserve a
 * un administrateur PLATEFORME (`is_admin`) : ni Guest, ni membre, ni OrgAdmin
 * n'ecrit jamais ici — la garde est dans le service, pas seulement dans la route.
 */
final class UsageReferenceService
{
    public function createDraft(string $surfaceKey, string $locale, string $title, string $content, User $actor): UsageReference
    {
        $this->guardPlatformActor($actor);
        [$surfaceKey, $locale, $title, $content] = $this->validated($surfaceKey, $locale, $title, $content);

        return DB::transaction(function () use ($surfaceKey, $locale, $title, $content, $actor): UsageReference {
            // Les ecrivains d'une meme (surface, locale) sont serialises : `max(version) + 1` sans course.
            UsageReference::query()->forSurface($surfaceKey)->forLocale($locale)->lockForUpdate()->get();
            $next = ((int) UsageReference::query()->forSurface($surfaceKey)->forLocale($locale)->max('version')) + 1;

            return UsageReference::query()->create([
                'surface_key' => $surfaceKey,
                'locale' => $locale,
                'title' => $title,
                'content' => $content,
                'version' => $next,
                'state' => UsageReference::STATE_DRAFT,
                'created_by' => $actor->id,
            ]);
        });
    }

    public function updateDraft(UsageReference $reference, string $title, string $content, User $actor): UsageReference
    {
        $this->guardPlatformActor($actor);

        if (! $reference->isDraft()) {
            throw new LogicException('Only a draft usage reference can be edited : publish a new version instead.');
        }

        [, , $title, $content] = $this->validated($reference->surface_key, $reference->locale, $title, $content);
        $reference->forceFill(['title' => $title, 'content' => $content])->save();

        return $reference;
    }

    /** Publie un brouillon ; la version publiee precedente de la meme (surface, locale) passe `retired`. */
    public function publish(UsageReference $reference, User $actor): UsageReference
    {
        $this->guardPlatformActor($actor);

        if (! $reference->isDraft()) {
            throw new LogicException('Only a draft usage reference can be published.');
        }

        return DB::transaction(function () use ($reference, $actor): UsageReference {
            $now = now();
            $current = UsageReference::query()
                ->forSurface($reference->surface_key)
                ->forLocale($reference->locale)
                ->published()
                ->lockForUpdate()
                ->get();

            foreach ($current as $previous) {
                $previous->forceFill(['state' => UsageReference::STATE_RETIRED, 'retired_at' => $now])->save();
            }

            $reference->forceFill([
                'state' => UsageReference::STATE_PUBLISHED,
                'published_by' => $actor->id,
                'published_at' => $now,
            ])->save();

            return $reference;
        });
    }

    /** Retire la version publiee : aucune reference ne sert plus pour cette (surface, locale) — jamais un repli vers un brouillon. */
    public function retire(UsageReference $reference, User $actor): UsageReference
    {
        $this->guardPlatformActor($actor);

        if (! $reference->isPublished()) {
            throw new LogicException('Only a published usage reference can be retired.');
        }

        $reference->forceFill(['state' => UsageReference::STATE_RETIRED, 'retired_at' => now()])->save();

        return $reference;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function validated(string $surfaceKey, string $locale, string $title, string $content): array
    {
        if (! in_array($surfaceKey, UsageReference::SURFACES, true)) {
            throw new InvalidArgumentException("Unknown usage reference surface [{$surfaceKey}].");
        }

        if (! in_array($locale, UsageReference::supportedLocales(), true)) {
            throw new InvalidArgumentException("Unsupported usage reference locale [{$locale}].");
        }

        $title = trim($title);
        $content = UsageReference::normalize($content);

        if ($title === '' || mb_strlen($title) > UsageReference::MAX_TITLE_CHARS) {
            throw new InvalidArgumentException('A usage reference requires a title of at most '.UsageReference::MAX_TITLE_CHARS.' characters.');
        }

        if ($content === '' || mb_strlen($content) > UsageReference::maxChars()) {
            throw new InvalidArgumentException('A usage reference requires a non-blank content of at most '.UsageReference::maxChars().' characters.');
        }

        return [$surfaceKey, $locale, $title, $content];
    }

    private function guardPlatformActor(User $actor): void
    {
        if (! $actor->is_admin) {
            throw new AuthorizationException('Only a platform administrator can write a usage reference.');
        }
    }
}
