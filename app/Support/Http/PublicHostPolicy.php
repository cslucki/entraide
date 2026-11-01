<?php

namespace App\Support\Http;

/**
 * TASK-1741 — « cet hote est-il une destination PUBLIQUE legitime ? »
 *
 * ## Pourquoi cette classe existe
 *
 * La regle vivait, en `private`, dans {@see \App\Services\UrlPreviewService}.
 * Elle y suffisait tant qu'un seul appelant s'en servait.
 *
 * Elle ne suffit plus des que `ExternalEgressGuard` doit, lui aussi, trancher
 * sur un hote — et il le doit : le service ne verifie ses redirections
 * qu'APRES COUP, en relisant `X-Guzzle-Redirect-History`. A ce moment la
 * requete vers l'hote interne A DEJA ETE FAITE. Seul le crochet `on_redirect`
 * du garde peut l'empecher de partir, et pour cela il lui faut la MEME regle.
 *
 * Deux copies de cette regle auraient diverge au premier correctif. Il n'y en
 * a donc qu'une, et les deux appelants la lisent.
 *
 * ## Ce qu'elle refuse
 *
 * Tout ce qui pourrait servir a faire parler le reseau interne depuis une URL
 * fournie par un tiers (SSRF) : les noms de bouclage, les adresses IPv6
 * litterales, les plages privees et reservees — et, surtout, les noms de
 * domaine PUBLICS qui RESOLVENT vers une adresse privee, qui sont la forme
 * utile de l'attaque.
 *
 * La resolution DNS est faite ici meme, avant la requete. Elle reste
 * sujette a une reliaison DNS entre ce controle et la connexion ; c'est une
 * limite connue, et le crochet `on_redirect` du garde la reduit en
 * re-controlant chaque saut.
 */
final class PublicHostPolicy
{
    /**
     * Noms de bouclage, refuses avant meme toute resolution.
     */
    private const HOTES_REFUSES = [
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
        '[::1]',
        '::1',
    ];

    /** Normaliser un hote : espaces, point final, forme litterale IPv6. */
    public static function normaliser(string $hote): string
    {
        $hote = rtrim(trim($hote), '.');

        return $hote;
    }

    /**
     * Cet hote est-il une destination publique acceptable ?
     *
     * Rend `false` des qu'un doute existe : un hote vide, un nom qui ne
     * resout pas vers une adresse publique, une IP privee ou reservee.
     */
    public static function estPublic(string $hote): bool
    {
        $hote = self::normaliser($hote);

        if ($hote === '') {
            return false;
        }

        $minuscule = mb_strtolower($hote);

        if (in_array($minuscule, self::HOTES_REFUSES, true)) {
            return false;
        }

        // Les IPv6 litterales sont refusees en bloc : leurs plages privees se
        // decrivent mal avec les outils dont nous disposons ici, et aucun
        // apercu legitime n'en a besoin.
        if (str_starts_with($minuscule, '[')) {
            return false;
        }

        $ip = null;

        if (filter_var($minuscule, FILTER_VALIDATE_IP)) {
            $ip = $minuscule;
        } else {
            $resolues = gethostbynamel($minuscule);

            if ($resolues !== false && count($resolues) > 0) {
                $ip = $resolues[0];
            }
        }

        if ($ip !== null) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }

            if (self::estPlagePrivee($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Les trois plages privees de la RFC 1918.
     *
     * `FILTER_FLAG_NO_PRIV_RANGE` les couvre deja ; ce controle reste en
     * ceinture, parce qu'une version de PHP ou une configuration exotique ne
     * doit pas suffire a rouvrir le reseau interne.
     */
    private static function estPlagePrivee(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return false;
        }

        $long = (float) sprintf('%u', $long);

        $dans = static fn (string $debut, string $fin): bool => $long >= (float) sprintf('%u', ip2long($debut))
            && $long <= (float) sprintf('%u', ip2long($fin));

        return $dans('10.0.0.0', '10.255.255.255')
            || $dans('172.16.0.0', '172.31.255.255')
            || $dans('192.168.0.0', '192.168.255.255');
    }
}
