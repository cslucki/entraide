<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use App\Support\Notifications\GabaritEmail;

/**
 * TASK-1772 — le courriel « on vous a mentionné ».
 *
 * ## Le texte ne vit PAS ici
 *
 * Il est posé en base par migration (Global, FR et EN) et le SuperAdmin peut
 * le réécrire depuis `/admin/system-email-templates`. Ce fichier ne porte que
 * les **variables offertes** et le **repli**.
 *
 * ## Tout se lit sans les scopes globaux, puis se borne à la main
 *
 * Ce code tourne dans un **worker**. Il n'y a alors aucune
 * `current_organization`, donc `BelongsToOrganizationScope` applique son
 * fail-closed et les objets ressortent INTROUVABLES — en silence. C'est le
 * défaut que `TransactionProposalMailer` a payé en recette le 04/11 : le
 * titre de l'annonce arrivait vide dans le courriel, sans la moindre erreur.
 *
 * La frontière de tenant n'est pas affaiblie pour autant : le
 * `where('organization_id', …)` est posé explicitement sous chaque retrait,
 * et c'est lui seul qui décide.
 */
final class LoopMentionMailer
{
    public const SLUG = 'loop_mention_created';

    /**
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function compose(
        LoopMessage $message,
        User $destinataire,
        ?User $acteur,
        string $url,
        string $langue,
    ): array {
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
            $boucle = Loop::query()
                ->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->whereKey($message->loop_id)
                ->where('organization_id', $message->organization_id)
                ->first();

            $organization = Organization::query()
                ->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->whereKey($message->organization_id)
                ->first();

            return app(GabaritEmail::class)->rendre(
                slug: self::SLUG,
                organizationId: (string) $message->organization_id,
                langue: $langue,
                sender: $acteur,
                vars: [
                    'recipient_name' => $destinataire->publicDisplayName(),
                    'recipient_email' => $destinataire->email,
                    'author_name' => $acteur?->publicDisplayName() ?? '',
                    'organization_name' => $organization?->name ?? '',
                    'loop_name' => $boucle?->name ?? '',
                    'message_url' => $url,
                    'app_name' => config('app.name'),
                ],
                // Le repli reprend le texte que la suite de cohérence exige
                // déjà dans `lang/{locale}/notifications.php`, plutôt qu'une
                // vue Blade de plus — ce serait une troisième version du même
                // message à tenir alignée.
                repli: fn () => [
                    'subject' => __('notifications.email.'.self::SLUG.'.subject'),
                    'html' => str_replace(':url', $url, __('notifications.email.'.self::SLUG.'.body')),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }
    }
}
