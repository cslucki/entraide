<?php

namespace App\Support\GuestShell;

use InvalidArgumentException;

/**
 * TASK-1440 — Guest PageContext V1 (Shell Welcome V3 §10, MASTER Q68) :
 * « OU se trouve exactement le visiteur ? » — l'equivalent Guest-safe du
 * PageContext membre. Un DTO IMMUABLE et borne : l'Organization, le genre de
 * surface, un identifiant public eventuel, un libelle public, un CTA public
 * eventuel (URL INTERNE generee cote serveur — jamais fournie par le
 * navigateur, jamais externe) et la provenance de route.
 *
 * PageContext != UsageReference (« a quoi sert cette surface ? ») != Runtime
 * != Constitution != Journey. `kind` est la surface METIER ou le Shell
 * apparait ; la UsageReference `shell_welcome` reste la fonctionnalite Shell
 * utilisee — deux dimensions distinctes (MASTER Q68).
 *
 * Jamais : Loop prive, Dossier, People prive, memoire membre, CRM,
 * credentials, autre Guest.
 */
final class GuestPageContext
{
    public const KIND_ORGANIZATION_HOME = 'organization_home';

    public const KIND_WORKSHOP_PAGE = 'workshop_page';

    public const KIND_WORKSHOP_SESSION = 'workshop_session';

    public const KIND_SIGNUP = 'signup';

    /** L'enum canonique (V3 §10). Les kinds Workshop existent ; aucune route ne les produit tant que la Phase B n'existe pas. */
    public const KINDS = [
        self::KIND_ORGANIZATION_HOME,
        self::KIND_WORKSHOP_PAGE,
        self::KIND_WORKSHOP_SESSION,
        self::KIND_SIGNUP,
    ];

    /**
     * @param  array{label: string, url: string}|null  $publicCta  URL interne absolue, generee cote serveur
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $kind,
        public readonly ?string $publicId,
        public readonly string $publicLabel,
        public readonly ?array $publicCta,
        public readonly string $routeName,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown guest page kind [{$kind}].");
        }

        if ($organizationId === '' || $routeName === '' || trim($publicLabel) === '') {
            throw new InvalidArgumentException('A guest page context requires an organization, a route name and a public label.');
        }

        if ($publicCta !== null) {
            $label = trim((string) ($publicCta['label'] ?? ''));
            $url = (string) ($publicCta['url'] ?? '');
            if ($label === '' || $url === '' || array_keys($publicCta) !== ['label', 'url']) {
                throw new InvalidArgumentException('A guest page CTA carries exactly a label and an url.');
            }
            if (! self::isInternalUrl($url)) {
                throw new InvalidArgumentException('A guest page CTA must point inside the platform.');
            }
        }
    }

    /** Interne = l'URL absolue de l'application (config app.url), jamais un hote arbitraire ni un schema exotique. */
    public static function isInternalUrl(string $url): bool
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base !== '' && ($url === $base || str_starts_with($url, $base.'/'));
    }
}
