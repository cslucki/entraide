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
     * TASK-1762 point 18 — chaque type porte desormais son PROPRE pictogramme
     * (`icone`, le contenu d'un `<svg>`) et `remplie` dit comment le rendre :
     * `true` pour un logo de marque plein (Instagram, X, Facebook — recopies
     * depuis simple-icons.org, memes traces que le site officiel), `false`
     * pour une icone de TRAIT au gabarit Heroicons deja utilise partout
     * ailleurs dans l'application (website, blog, app).
     *
     * `twitter` garde sa CLEF — renommer la clef aurait orphelin les lignes
     * deja enregistrees (`assainir()` les aurait jetees au prochain
     * enregistrement). Seuls le libelle (« X (ex Twitter) », demande de
     * Cyril) et l'icone changent, pour le nouveau logo.
     *
     * `facebook` est un ajout de ma part (Cyril a demande : « vois s'il faut
     * ajouter un autre type de lien ») — tres repandu chez les associations
     * et petites structures, le public meme de la Structure du point 1.
     *
     * @return array<string, array{label: string, couleur: string, icone: string, remplie: bool}>
     */
    public static function types(): array
    {
        return [
            'instagram' => [
                'label' => 'profile.link_type_instagram',
                'couleur' => 'text-pink-600 dark:text-pink-400',
                'remplie' => true,
                'icone' => '<path d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077"/>',
            ],
            'twitter' => [
                'label' => 'profile.link_type_twitter',
                'couleur' => 'text-gray-900 dark:text-gray-100',
                'remplie' => true,
                // Le logo X (2023), pas l'oiseau Twitter : la mue de marque
                // que le libelle annonce desormais, « X (ex Twitter) ».
                'icone' => '<path d="M14.234 10.162 22.977 0h-2.072l-7.591 8.824L7.251 0H.258l9.168 13.343L.258 24H2.33l8.016-9.318L16.749 24h6.993zm-2.837 3.299-.929-1.329L3.076 1.56h3.182l5.965 8.532.929 1.329 7.754 11.09h-3.182z"/>',
            ],
            'facebook' => [
                'label' => 'profile.link_type_facebook',
                'couleur' => 'text-blue-600 dark:text-blue-400',
                'remplie' => true,
                'icone' => '<path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/>',
            ],
            'website' => [
                'label' => 'profile.link_type_website',
                'couleur' => 'text-indigo-600 dark:text-indigo-400',
                'remplie' => false,
                // Le MEME globe que le champ « Site web » au-dessus.
                'icone' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>',
            ],
            'blog' => [
                'label' => 'profile.link_type_blog',
                'couleur' => 'text-amber-600 dark:text-amber-400',
                'remplie' => false,
                'icone' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
            ],
            'app' => [
                'label' => 'profile.link_type_app',
                'couleur' => 'text-emerald-600 dark:text-emerald-400',
                'remplie' => false,
                'icone' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"/>',
            ],
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

    /** Le contenu brut d'un `<svg>` — un ou plusieurs `<path>`, sans la balise `<svg>` elle-meme. */
    public static function icone(string $type): string
    {
        return self::types()[$type]['icone'] ?? self::types()['website']['icone'];
    }

    /**
     * `true` : logo de marque plein (`fill="currentColor"`, sans `stroke`).
     * `false` : icone de trait, au gabarit Heroicons du reste de l'application.
     */
    public static function iconeRemplie(string $type): bool
    {
        return self::types()[$type]['remplie'] ?? false;
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
