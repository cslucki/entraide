<?php

namespace Tests\Support\ScenarioManifest;

/**
 * TASK-1641 — l'exemple AMT est lu DANS la spec, jamais recopie.
 *
 * La spec dit : "elle accepte l'exemple AMT ci-dessus" (critere d'acceptation
 * 1). Une copie du JSON dans `tests/Fixtures` repondrait a une question
 * differente — "le Validator accepte-t-il cette copie" — et resterait verte le
 * jour ou la spec et la copie divergeraient. C'est le document normatif
 * lui-meme qui sert d'entree ; si quelqu'un modifie l'exemple de la spec sans
 * bouger le Validator, le test le dit.
 *
 * Les mutations partent toutes du meme document decode : un test de mutation
 * ne prouve quelque chose que si sa BASE est exactement la reference verte.
 */
final class AmtReferenceManifest
{
    private const SPEC = 'TODO/SPECS/260920-11h10-CDC-scenario-manifest.md';

    private const SECTION = '## 16. Example AMT manifest';

    public static function json(): string
    {
        $specPath = dirname(__DIR__, 3).'/'.self::SPEC;

        if (! is_file($specPath)) {
            throw new \RuntimeException('Scenario Manifest specification not found; the reference example cannot be read.');
        }

        $markdown = (string) file_get_contents($specPath);
        $section = strpos($markdown, self::SECTION);

        if ($section === false) {
            throw new \RuntimeException('Section 16 of the specification not found.');
        }

        $open = strpos($markdown, '```json', $section);

        if ($open === false) {
            throw new \RuntimeException('The AMT example fence was not found in section 16.');
        }

        $start = $open + strlen("```json\n");
        $close = strpos($markdown, "\n```", $start);

        if ($close === false) {
            throw new \RuntimeException('The AMT example fence is not closed.');
        }

        return substr($markdown, $start, $close - $start);
    }

    /**
     * Document decode en objets, pret a etre mute.
     */
    public static function decoded(): \stdClass
    {
        return json_decode(self::json(), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Applique une mutation a une copie PROFONDE de la reference et rend le
     * JSON correspondant.
     *
     * La copie profonde compte : une mutation qui modifierait la reference
     * partagee ferait dependre chaque test de l'ordre d'execution des autres,
     * et le premier defaut introduit contaminerait tous les suivants.
     */
    public static function mutate(callable $mutation): string
    {
        $manifest = self::decoded();
        $mutation($manifest);

        return json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
