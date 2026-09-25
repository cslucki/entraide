<?php

namespace Tests\Support\ScenarioManifest;

/**
 * TASK-1641 — l'exemple AMT de la section 16 de la spec, base de TOUTES les
 * mutations.
 *
 * L'exemple est livre comme fixture VERSIONNEE, et non lu dans la spec, pour
 * une raison operationnelle : `TODO/` est gitignore. La spec est un document
 * de travail local, absent du depot et donc absent de la CI ; un test qui la
 * lirait serait vert sur un poste et rouge sur chaque runner.
 *
 * L'objection reste juste pour autant : une copie peut diverger de l'original.
 * La reponse n'est pas de renoncer a la copie mais de la SURVEILLER —
 * `specJson()` rend le bloc de la spec quand elle est presente, et
 * `ScenarioManifestValidatorTest` compare les deux octet par octet. La garde
 * s'execute donc exactement la ou la derive peut naitre : sur un poste qui a
 * la spec et peut la modifier. En CI, ou la spec n'existe pas, il n'y a rien a
 * surveiller et le test le dit au lieu de rougir.
 *
 * Les mutations partent toutes du meme document decode : un test de mutation
 * ne prouve quelque chose que si sa BASE est exactement la reference verte.
 */
final class AmtReferenceManifest
{
    private const FIXTURE = 'tests/Fixtures/ScenarioManifest/amt-formation-ia.json';

    private const SPEC = 'TODO/SPECS/260920-11h10-CDC-scenario-manifest.md';

    private const SECTION = '## 16. Example AMT manifest';

    public static function json(): string
    {
        $path = self::basePath().'/'.self::FIXTURE;

        if (! is_file($path)) {
            throw new \RuntimeException('The AMT reference fixture is missing from the repository.');
        }

        return (string) file_get_contents($path);
    }

    /**
     * Le bloc JSON de la section 16 de la spec, ou `null` quand la spec n'est
     * pas distribuee (CI, clone frais).
     */
    public static function specJson(): ?string
    {
        $path = self::basePath().'/'.self::SPEC;

        if (! is_file($path)) {
            return null;
        }

        $markdown = (string) file_get_contents($path);
        $section = strpos($markdown, self::SECTION);

        if ($section === false) {
            return null;
        }

        $open = strpos($markdown, '```json', $section);

        if ($open === false) {
            return null;
        }

        $start = $open + strlen("```json\n");
        $close = strpos($markdown, "\n```", $start);

        return $close === false ? null : substr($markdown, $start, $close - $start);
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

    private static function basePath(): string
    {
        return dirname(__DIR__, 3);
    }
}
