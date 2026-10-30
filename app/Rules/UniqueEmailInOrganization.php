<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TASK-1729 (A4) — une adresse est unique DANS une Organization, sans
 * distinction de casse.
 *
 * ## Pourquoi une regle a part, et non cinq `Rule::unique()` parametres
 *
 * Cinq surfaces posent cette question : inscription WEB, inscription API,
 * modification de profil, creation et modification depuis `/admin/users`.
 * Avec `Rule::unique()`, chacune aurait du composer a la main la portee de
 * tenant, l'insensibilite a la casse et l'exception du compte courant — trois
 * subtilites, cinq fois. Une condition de securite recopiee cinq fois est une
 * condition qui diverge : c'est exactement ce qui est arrive a la regle
 * `lowercase`, presente sur trois portes et absente des deux de
 * `/admin/users`.
 *
 * `Rule::unique()` ne pouvait d'ailleurs pas le faire seul : il ajoute
 * `where email = <valeur>`, donc une comparaison EXACTE. Y greffer un
 * `whereRaw('LOWER(email) = ?')` aurait produit les deux conditions a la fois,
 * et n'aurait rien trouve.
 *
 * ## Ce que cette regle N'EST PAS
 *
 * Elle n'est pas la garantie. La garantie, ce sont les deux index uniques
 * poses par la migration — et elle tient meme si une surface oublie cette
 * regle. Ici on produit un MESSAGE lisible au bon champ, pour qu'une
 * collision ordinaire ne se presente pas comme une erreur 500.
 *
 * ## Les comptes sans Organization — la regle est ASYMETRIQUE (revue F1)
 *
 * | paire | autorise ? |
 * |---|---|
 * | membre(orgA) / membre(orgB) | **oui** — c'est tout l'objet de A4 |
 * | membre / membre, meme Organization | non — index composite |
 * | orphelin / orphelin | non — index partiel |
 * | **orphelin / membre** | **NON** — et c'etait le defaut F1 |
 *
 * La derniere ligne n'est pas une symetrie oubliee, c'est une consequence de
 * T1728 : un compte sans Organization n'a **qu'une porte**, le login GLOBAL,
 * et cette porte refuse une adresse ambigue par conception. Un orphelin qui
 * partage son adresse avec n'importe quel autre compte est donc un compte
 * MUET — il ne peut ni se connecter, ni demander un lien de reinitialisation.
 *
 * J'avais d'abord ecrit un test AFFIRMANT que cette paire etait permise, en
 * raisonnant sur ce que l'index laisse passer plutot que sur ce que la
 * personne peut faire ensuite. La revue MASTER l'a releve : le schema
 * l'autorise, le produit ne doit pas.
 *
 * ## Ce que la BASE garantit, et ce qu'elle ne peut pas garantir
 *
 * Les deux index couvrent « orphelin/orphelin » et « meme Organization ». La
 * paire « orphelin/membre » n'est PAS exprimable par un index unique — elle
 * porte sur deux lignes dont l'une a `organization_id` nul et l'autre non.
 * Elle est donc tenue par l'APPLICATION, en quatre endroits et pas un de
 * moins :
 *
 *   - cette regle, sur les quatre portes de creation/modification d'email ;
 *   - le preflight de la migration, pour l'etat deja en base ;
 *   - la garde de suppression d'Organization (D1), qui refuse de FABRIQUER un
 *     orphelin ambigu ;
 *   - la garde de rattachement en masse, pour le sens inverse.
 *
 * Dire que la base l'empeche serait faux ; ces quatre-la sont la liste
 * complete, et aucun autre chemin ne cree d'orphelin.
 */
class UniqueEmailInOrganization implements ValidationRule
{
    public function __construct(
        private readonly ?string $organizationId,
        private readonly ?string $ignoreUserId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (self::estPrise($value, $this->organizationId, $this->ignoreUserId)) {
            $fail('validation.unique')->translate(['attribute' => $attribute]);
        }
    }

    /**
     * Le MEME refus, mais leve — a employer sous verrou, juste avant l'ecriture.
     *
     * ## Pourquoi un second controle, et pourquoi il ne peut pas remplacer le premier
     *
     * La regle ci-dessus tourne dans `$request->validate()`, et c'est la bonne
     * place : elle rend les messages de tous les champs d'un coup, et elle
     * s'execute SANS verrou. Mais entre elle et l'ecriture il y a un trou que
     * rien ne ferme — `/register` est publique, deux requetes peuvent y passer
     * en meme temps, et en READ COMMITTED chaque instruction prend un
     * instantane neuf. Le controle de validation est donc un controle
     * d'ERGONOMIE ; celui-ci est le controle d'INTEGRITE.
     *
     * On ne pouvait pas simplement poser le verrou autour de
     * `$request->validate()` : l'inscription web y valide aussi le defi
     * Turnstile, qui appelle Cloudflare. Tenir `SHARE ROW EXCLUSIVE` sur
     * `users` pendant un aller-retour reseau aurait bloque toutes les
     * ecritures de comptes de l'application le temps d'une latence
     * exterieure. Le verrou doit entourer le controle et l'ecriture, et
     * RIEN d'autre.
     *
     * Les deux controles posent la MEME question — `estPrise()` est la seule
     * implementation. C'est ce qui garantit qu'une collision ordinaire se
     * presente comme un message de champ, jamais comme un 500 : si le premier
     * laisse passer, le second refuse avec le meme libelle au meme champ.
     */
    public static function refuserSiPrise(
        string $attribut,
        mixed $valeur,
        ?string $organizationId,
        ?string $ignoreUserId = null,
    ): void {
        if (! is_string($valeur) || $valeur === '') {
            return;
        }

        if (! self::estPrise($valeur, $organizationId, $ignoreUserId)) {
            return;
        }

        throw ValidationException::withMessages([
            $attribut => [trans('validation.unique', ['attribute' => $attribut])],
        ]);
    }

    /**
     * L'UNIQUE implementation de la question « cette adresse est-elle prise ? ».
     *
     * Partagee par la regle de validation et par le refus sous verrou. Les
     * avoir ecrites deux fois aurait donne deux reponses le jour ou l'une
     * change — et c'est la copie qu'on oublie de corriger.
     */
    private static function estPrise(string $value, ?string $organizationId, ?string $ignoreUserId): bool
    {
        return User::query()
            // `LOWER()` des deux cotes : la colonne est sensible a la casse
            // sous PostgreSQL, et c'est la forme exacte que portent les index
            // uniques de la migration. La regle et la base doivent repondre a
            // la MEME question, sinon l'une promet ce que l'autre refuse.
            ->whereRaw('LOWER(email) = ?', [Str::lower($value)])
            ->when(
                $organizationId === null,
                // Compte SANS Organization : toute homonymie le rend muet, y
                // compris avec un membre. Aucun filtre de portee, donc.
                fn ($query) => $query,
                // Compte D'UNE Organization : son homonyme dans un AUTRE tenant
                // est legitime (A4), mais un ORPHELIN homonyme ne l'est pas —
                // c'est lui qu'on rendrait muet. D'ou les deux branches.
                fn ($query) => $query->where(fn ($q) => $q
                    ->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id')),
            )
            ->when(
                $ignoreUserId !== null,
                fn ($query) => $query->whereKeyNot($ignoreUserId),
            )
            ->exists();
    }

    /**
     * Les adresses qui deviendraient ambigues si ces comptes etaient DETACHES.
     *
     * Sert la garde de suppression d'Organization (arbitrage MASTER D1).
     *
     * Sert la garde de suppression d'Organization (arbitrage MASTER D1) : avant
     * de detacher un membre, on doit savoir si son adresse est portee par un
     * AUTRE compte — orphelin ou non. Pose ici parce que c'est la meme question
     * que ci-dessus, a la portee pres : les deux doivent employer le meme
     * `LOWER()`, et le jour ou l'une changera l'autre devra suivre.
     *
     * @param  list<string>  $adresses
     * @return Collection<int, string> les adresses en conflit
     */
    public static function adressesEnConflitHorsOrganization(array $adresses, string $organizationId)
    {
        if ($adresses === []) {
            return collect();
        }

        $normalisees = collect($adresses)->map(fn (string $a) => Str::lower($a))->unique()->values();

        return User::query()
            ->selectRaw('LOWER(email) as adresse')
            ->whereIn(DB::raw('LOWER(email)'), $normalisees->all())
            ->where(fn ($query) => $query
                ->whereNull('organization_id')
                ->orWhere('organization_id', '!=', $organizationId))
            ->pluck('adresse')
            ->unique()
            ->values();
    }
}
