<?php

namespace App\Support;

/**
 * TASK-1750 — le catalogue des types de liens de profil, en UN exemplaire.
 *
 * ## Pourquoi une classe et pas un tableau dans la vue
 *
 * Trois endroits ont besoin de la MEME liste : la validation (quels types sont
 * admis), le formulaire d'edition (le menu deroulant) et le profil public
 * (l'icone et la couleur de chaque pastille). Recopier l'enumeration trois fois
 * garantirait qu'elles divergent — ajouter un type en oubliant la validation
 * donnerait un menu qui propose une valeur que le serveur refuse.
 *
 * ## Les schemas admis, et pourquoi c'est une LISTE BLANCHE
 *
 * Ces URL finissent dans un `href` rendu a des visiteurs. `javascript:` et
 * `data:` y sont des vecteurs d'injection, et `url` de Laravel les ACCEPTE
 * tous les deux. Interdire au cas par cas laisserait passer le suivant
 * (`vbscript:`, `file:`…) : seule une liste blanche de deux schemas tient.
 */
final class ProfileLinks
{
    /** Le plafond : au-dela, la rangee publique cesse d'etre lisible. */
    public const MAXIMUM = 6;

    /** Les SEULS schemas qui peuvent sortir dans un `href`. */
    public const SCHEMAS = ['http', 'https'];

    /**
     * Les types, dans l'ordre ou Cyril les a nommes.
     *
     * `website` figure dans la liste alors qu'un champ « Site web » existe
     * au-dessus : c'est voulu — le champ du haut reste le site PRINCIPAL, et
     * celui-ci sert un second site.
     *
     * @return array<string, array{label: string, couleur: string}>
     */
    public static function types(): array
    {
        return [
            'instagram' => ['label' => 'profile.link_type_instagram', 'couleur' => 'text-pink-600 dark:text-pink-400'],
            'twitter' => ['label' => 'profile.link_type_twitter', 'couleur' => 'text-sky-600 dark:text-sky-400'],
            'website' => ['label' => 'profile.link_type_website', 'couleur' => 'text-indigo-600 dark:text-indigo-400'],
            'blog' => ['label' => 'profile.link_type_blog', 'couleur' => 'text-amber-600 dark:text-amber-400'],
            'app' => ['label' => 'profile.link_type_app', 'couleur' => 'text-emerald-600 dark:text-emerald-400'],
        ];
    }

    /** @return list<string> */
    public static function clefs(): array
    {
        return array_keys(self::types());
    }

    public static function libelle(string $type): string
    {
        return __(self::types()[$type]['label'] ?? 'profile.link_type_website');
    }

    public static function couleur(string $type): string
    {
        return self::types()[$type]['couleur'] ?? self::types()['website']['couleur'];
    }

    /**
     * Le nom d'hote, pour l'affichage de la pastille.
     *
     * `parse_url()` rend `null` sur une URL sans schema — et la pastille
     * afficherait alors l'URL brute. La validation impose deja `http(s)`, mais
     * les lignes ECRITES AVANT une evolution de regle, elles, sont deja en
     * base : le repli sur l'URL complete est la seule reponse sure.
     */
    public static function hote(string $url): string
    {
        $hote = parse_url($url, PHP_URL_HOST);

        return is_string($hote) && $hote !== ''
            ? preg_replace('/^www\./', '', $hote)
            : $url;
    }

    /**
     * Ne garde que les lignes COMPLETES et de type connu.
     *
     * Le formulaire envoie ses lignes telles que l'utilisateur les a laissees :
     * une ligne ajoutee puis abandonnee arrive vide, et une ligne vide
     * enregistree telle quelle produirait une pastille sans texte ni
     * destination sur le profil public.
     *
     * @param  mixed  $brut
     * @return list<array{type: string, url: string}>
     */
    public static function assainir($brut): array
    {
        if (! is_array($brut)) {
            return [];
        }

        $propres = [];

        foreach ($brut as $ligne) {
            if (! is_array($ligne)) {
                continue;
            }

            $type = is_string($ligne['type'] ?? null) ? $ligne['type'] : '';
            $url = is_string($ligne['url'] ?? null) ? trim($ligne['url']) : '';

            if ($url === '' || ! in_array($type, self::clefs(), true)) {
                continue;
            }

            $propres[] = ['type' => $type, 'url' => $url];
        }

        return array_slice($propres, 0, self::MAXIMUM);
    }
}
