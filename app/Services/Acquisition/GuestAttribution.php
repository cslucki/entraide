<?php

namespace App\Services\Acquisition;

use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\OrganizationShortcut;
use Illuminate\Support\Str;

/**
 * TASK-1447 — l'attribution CANONIQUE d'un premier geste Guest (Growth V2 §4/§5,
 * MASTER Q75). Le navigateur n'est jamais l'autorite : il ne transmet qu'un code
 * de Shortcut et des UTM externes bornes ; la Journey (version publiee EXACTE) et
 * la campagne viennent du Shortcut relu en base, dans la MEME Organization.
 * Modifier `?journey=` ou `?campaign=` dans l'URL ne change rien.
 */
final class GuestAttribution
{
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign'];

    public const MAX_UTM_CHARS = 100;

    public function __construct(private readonly AcquisitionJourneyResolver $journeys) {}

    /**
     * Les UTM externes autorises, bornes, sans rien d'executable — jamais un impact tenant.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function allowedUtm(array $input): array
    {
        $out = [];
        foreach (self::UTM_KEYS as $key) {
            $value = $input[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }
            $value = trim(preg_replace('/[^\p{L}\p{N} _\-.]/u', '', $value) ?? '');
            if ($value !== '') {
                $out[$key] = Str::limit($value, self::MAX_UTM_CHARS, '');
            }
        }

        return $out;
    }

    /**
     * TASK-1463 (audit OPUS final P1-1) — ce qu'un lien INTERNE peut transporter d'une page a l'autre pour que le
     * premier geste retrouve son atterrissage : le code de Shortcut (forme valide seulement) et les UTM autorises,
     * bornes. Jamais `journey` ni `campaign` : ils sont RELUS EN BASE par resolve() a partir du code — le
     * navigateur ne transporte qu'un code, jamais une autorite.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function carry(array $query): array
    {
        $code = $query[OrganizationShortcut::QUERY_PARAM] ?? null;
        $carry = OrganizationShortcut::isValidCode($code) ? [OrganizationShortcut::QUERY_PARAM => $code] : [];

        return $carry + self::allowedUtm($query);
    }

    /**
     * Ce qui sera fige sur le GuestVisitor a sa creation (first touch wins).
     *
     * @param  array<string, mixed>  $claimed  ce que le navigateur declare : shortcut + UTM
     * @return array{shortcut: string|null, acquisition_journey_id: string|null, utm_source: string|null, utm_medium: string|null, utm_campaign: string|null}
     */
    public function resolve(Organization $organization, array $claimed): array
    {
        $utm = self::allowedUtm($claimed);
        $code = $claimed[OrganizationShortcut::QUERY_PARAM] ?? null;
        $shortcut = OrganizationShortcut::isValidCode($code)
            ? OrganizationShortcut::query()->active()->forOrganization($organization)->where('code', $code)->first()
            : null;

        $journey = null;
        if ($shortcut !== null && $shortcut->acquisition_journey_key !== null) {
            $journey = $this->journeys->published($organization, $shortcut->acquisition_journey_key);
        }

        return [
            'shortcut' => $shortcut?->code,
            'acquisition_journey_id' => $journey instanceof AcquisitionJourney ? (string) $journey->getKey() : null,
            'utm_source' => $utm['utm_source'] ?? null,
            'utm_medium' => $utm['utm_medium'] ?? null,
            // La campagne canonique du Shortcut prime sur un utm_campaign externe.
            'utm_campaign' => $shortcut?->campaign ?? ($utm['utm_campaign'] ?? null),
        ];
    }
}
