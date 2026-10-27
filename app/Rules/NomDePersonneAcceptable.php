<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * TASK-1712 — un nom de personne, pas un support publicitaire.
 *
 * ## Ce que cette regle existe pour empecher
 *
 * Le 39e compte de la PROD s'etait inscrit sous le nom
 * `+2.84567197 BTC. GET ->>>  graph.org/...`. Aucune injection : 76 caracteres
 * ASCII, pas une balise formee, pas un guillemet, pas un motif SQL. Un leurre
 * TEXTUEL, dont le seul but est d'etre affiche a un humain qui cliquera.
 *
 * Et il a ete affiche : le sujet de son email de bienvenue le portait deux
 * fois. Les deux vecteurs qui atteignent des TIERS sont pires — le gabarit
 * `new_message` met `{{ sender_name }}` dans le sujet d'un email envoye au
 * destinataire du message, et `referral_invitation` l'envoie a une adresse
 * exterieure.
 *
 * Les sorties, elles, sont saines et le restent : liste blanche de variables et
 * `strip_tags()` dans les sujets, `e()` dans les corps, et Symfony encode les
 * en-tetes — une tentative d'injection par `\r\n` n'aboutit pas, mesure. Le
 * risque est donc SOCIAL, pas technique, et il se ferme a l'entree.
 *
 * ## Pourquoi des motifs STRUCTURELS et aucune liste de mots
 *
 * Une liste noire de mots (« BTC », « crypto », « gratuit ») se contourne en
 * changeant de monnaie et punit les noms qui contiendraient ces syllabes. Ce
 * qui distingue reellement un nom d'une publicite, c'est sa FORME : un nom ne
 * contient pas d'URL, ni de longue suite de chiffres, ni de caractere de
 * controle.
 *
 * ## Calibrage, mesure sur les 70 noms reels de la PROD
 *
 * | mesure | observe |
 * |---|---|
 * | longueur du plus long nom legitime | **32** (`Mendrikiniaina Henri Ratoveloson`) |
 * | caracteres non-alphabetiques legitimes | le trait d'union, **et lui seul** |
 * | noms legitimes contenant un chiffre | **aucun** |
 * | noms legitimes contenant un point | **aucun** |
 *
 * La borne de longueur est posee a 100 — trois fois le plus long nom observe —
 * parce qu'un nom compose malgache, portugais ou arabe translittere peut
 * depasser ce que cette base contient aujourd'hui. `max:255` restait une borne
 * de COLONNE, pas une borne de sens.
 *
 * Les chiffres isoles restent acceptes (un `II`, un `3` dans un nom d'usage) :
 * seules les suites de quatre et plus sont refusees, et une proportion de
 * chiffres qui ferait du nom autre chose qu'un nom.
 *
 * L'apostrophe, le trait d'union, le point et la virgule restent acceptes :
 * `O'Hara`, `Jean-Michel`, `J. Dupont`, `Dupont, fils`. Aucun n'est dans cette
 * base, et tous sont des noms reels — la lecon de TASK-1672 sur « Julien
 * O'Hara » est encore fraiche.
 */
class NomDePersonneAcceptable implements ValidationRule
{
    /** Trois fois le plus long nom observe en PROD. */
    public const LONGUEUR_MAX = 100;

    /** Au-dela, ce n'est plus un nom mais une reference. */
    public const CHIFFRES_CONSECUTIFS_MAX = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return; // `string` s'en charge et dira mieux que nous
        }

        $nom = trim($value);

        if (mb_strlen($nom) > self::LONGUEUR_MAX) {
            $fail('validation.nom_trop_long')->translate(['max' => self::LONGUEUR_MAX]);

            return;
        }

        // 1. Caracteres de controle et formatage invisible.
        //
        //    `\p{C}` couvre les caracteres de controle, mais aussi les
        //    caracteres de formatage invisibles (`U+200B` espace sans largeur,
        //    `U+200E` marque de direction) dont on se sert pour fabriquer un
        //    nom qui s'affiche autrement qu'il n'est stocke.
        if (preg_match('/\p{C}/u', $nom) === 1) {
            $fail('validation.nom_caracteres_invisibles')->translate();

            return;
        }

        // 2. Chevrons. Les sorties les echappent deja, mais un nom n'en a
        //    aucun usage, et les laisser entrer c'est dependre pour toujours de
        //    la vigilance de chaque gabarit.
        if (preg_match('/[<>]/u', $nom) === 1) {
            $fail('validation.nom_chevrons')->translate();

            return;
        }

        // 3. Une URL, sous les formes qui comptent.
        //
        //    `graph.org/bsjTOXAD` n'a ni `http` ni `www` : c'est un domaine
        //    suivi d'une barre oblique, et c'est deja cliquable pour qui le
        //    recopie. Les trois motifs sont donc cherches separement.
        $urls = [
            '~[a-z][a-z0-9+.\-]*://~iu',                  // un schema, quel qu'il soit
            '~(^|[\s.])www\.~iu',                          // la forme la plus courante
            '~[a-z0-9]([a-z0-9\-]*[a-z0-9])?\.[a-z]{2,24}\s*[/?#]~iu', // domaine.tld suivi d'un chemin
        ];

        foreach ($urls as $motif) {
            if (preg_match($motif, $nom) === 1) {
                $fail('validation.nom_adresse_web')->translate();

                return;
            }
        }

        // 4. Les suites de chiffres.
        //
        //    Un montant, un identifiant de campagne, un code de suivi : rien de
        //    tout cela n'est un nom. Les chiffres isoles passent.
        if (preg_match('/\d{'.(self::CHIFFRES_CONSECUTIFS_MAX + 1).',}/u', $nom) === 1) {
            $fail('validation.nom_suite_de_chiffres')->translate(['max' => self::CHIFFRES_CONSECUTIFS_MAX]);

            return;
        }

        // 5. La PROPORTION de chiffres.
        //
        //    `1 2 3 4 5 6 7 8` passerait le critere precedent, chaque chiffre
        //    etant isole. Ce n'est pourtant pas davantage un nom.
        $lettres = preg_match_all('/\p{L}/u', $nom);
        $chiffres = preg_match_all('/\d/u', $nom);

        if ($chiffres > 0 && $chiffres > $lettres) {
            $fail('validation.nom_trop_de_chiffres')->translate();
        }
    }
}
