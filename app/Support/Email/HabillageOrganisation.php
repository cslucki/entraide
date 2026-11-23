<?php

namespace App\Support\Email;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1894 — l'habillage commun des emails, AU NOM DE L'ORGANISATION
 * (arbitrages Cyril du 23/11) :
 *
 * - en-tete et pied : le nom de l'organisation, jamais « BouclePro » ;
 * - signature : « Prenom pour Organisation » (l'administrateur designe,
 *   `organizations.admin_id`) ; sans administrateur : « L'equipe Organisation » ;
 * - « Pour ne plus recevoir cet email, allez dans Reglages des notifications. »
 *   SEULEMENT sur les emails qu'on peut couper : jamais sur l'inscription, le
 *   mot de passe, une invitation ou la liaison de comptes — on y promettrait
 *   un reglage qui n'existe pas. La newsletter a son propre lien de
 *   desinscription : pas de seconde mention.
 */
final class HabillageOrganisation
{
    /** Gabarits qu'aucun reglage de notification ne coupe. */
    public const NON_DESABONNABLES = [
        'affectation-points-suite-inscription',
        'confirmation-email-suite-inscription',
        'liaison-comptes-confirmation',
        'liaison-comptes-creee',
        'organization_invitation',
        'loop_invitation',
        'referral_invitation',
        'blog_contribution_invitation',
        'ai_budget_exceeded',
        'weekly_help_digest',
        'password_reset',
        'verify_email',
    ];

    public static function desabonnable(string $slug): bool
    {
        return ! in_array($slug, self::NON_DESABONNABLES, true);
    }

    /**
     * @return array{marque: string, marqueUrl: string, signature: string, reglagesUrl: ?string, langue: string}
     */
    public static function pour(?Organization $organisation, string $slug, ?string $langue = null, ?string $reglagesUrl = null): array
    {
        $langue = ($langue ?? app()->getLocale()) === 'en' ? 'en' : 'fr';
        $nom = trim((string) $organisation?->name);

        if ($nom === '') {
            // Aucun tenant (cas marginal : compte sans organisation). On ne
            // fabrique pas un nom : l'habillage historique reste en place.
            $nom = (string) config('app.name');
        }

        $admin = $organisation?->admin_id ? User::query()->find($organisation->admin_id) : null;
        $prenom = trim((string) ($admin?->first_name ?: $admin?->publicDisplayName()));

        $signature = $prenom !== ''
            ? ($langue === 'en' ? "{$prenom} for {$nom}" : "{$prenom} pour {$nom}")
            : ($langue === 'en' ? "The {$nom} team" : "L’équipe {$nom}");

        if (self::desabonnable($slug) && $reglagesUrl === null && $organisation !== null && Route::has('organization.notifications.preferences.edit')) {
            $reglagesUrl = route('organization.notifications.preferences.edit', ['organization' => $organisation]);
        }

        return [
            'marque' => $nom,
            'marqueUrl' => $organisation !== null && Route::has('organization.home')
                ? route('organization.home', ['organization' => $organisation])
                : (string) config('app.url'),
            'signature' => $signature,
            'reglagesUrl' => self::desabonnable($slug) ? $reglagesUrl : null,
            'langue' => $langue,
        ];
    }
}
