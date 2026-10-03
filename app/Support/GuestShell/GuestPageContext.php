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
     * @param  array{label: string, url: string}|null  $publicCta  URL interne generee cote serveur :
     *                                                                   relative (`/org/...`) de preference, absolue sous `APP_URL` acceptee
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

    /**
     * Interne = cette plateforme, jamais un hote arbitraire ni un schema exotique.
     *
     * Deux formes sont acceptees, et seulement elles :
     *
     *   1. un CHEMIN RELATIF (`/org/main/register`) — la forme a preferer, car elle
     *      ne depend ni de `APP_URL` ni du host courant. C'est ce qui a motive
     *      TASK-1662 : l'application est servie sur plusieurs hosts legitimes
     *      (domaine public, domaine vanity Laravel Cloud, tunnel de developpement),
     *      et comparer une URL absolue au seul prefixe `APP_URL` faisait jeter la
     *      garde sur un host pourtant parfaitement valide ;
     *   2. une URL ABSOLUE sous `config('app.url')` — conservee par compatibilite.
     *
     * Ce qui reste refuse, et c'est le coeur de la garde :
     *
     *   - un autre hote : `https://evil.example/...`, `http://evil.example/...` ;
     *   - une URL SANS SCHEMA (`//evil.example/...`) : elle ressemble a un chemin
     *     mais le navigateur y voit un AUTRE HOTE, en heritant du schema courant.
     *     C'est le piege classique de ce genre de verification ;
     *   - toute URL contenant une CONTRE-OBLIQUE : les navigateurs normalisent `\`
     *     en `/`, donc `/\evil.example` vaut `//evil.example` pour eux. Refuser le
     *     caractere est plus sur que tenter de le normaliser ;
     *   - un schema exotique (`javascript:`, `data:`), qui ne commence ni par `/`
     *     ni par `APP_URL`.
     *
     * Le host de la requete courante n'est JAMAIS une preuve d'internalite : il est
     * fourni par le client. La forme relative rend cette question sans objet.
     */
    public static function isInternalUrl(string $url): bool
    {
        if ($url === '' || str_contains($url, '\\')) {
            return false;
        }

        if (str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        $base = rtrim((string) config('app.url'), '/');

        return $base !== '' && ($url === $base || str_starts_with($url, $base.'/'));
    }
}
