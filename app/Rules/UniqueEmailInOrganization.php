<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * ## Le cas des comptes sans Organization
 *
 * `organizationId` a `null` vise les orphelins, et la comparaison porte alors
 * sur `organization_id IS NULL` — jamais sur « n'importe quelle
 * Organization ». C'est le pendant exact de l'index partiel : deux orphelins
 * ne peuvent pas partager une adresse, mais un orphelin et un membre peuvent.
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

        $existe = User::query()
            // `LOWER()` des deux cotes : la colonne est sensible a la casse
            // sous PostgreSQL, et c'est la forme exacte que portent les index
            // uniques de la migration. La regle et la base doivent repondre a
            // la MEME question, sinon l'une promet ce que l'autre refuse.
            ->whereRaw('LOWER(email) = ?', [Str::lower($value)])
            ->when(
                $this->organizationId === null,
                fn ($query) => $query->whereNull('organization_id'),
                fn ($query) => $query->where('organization_id', $this->organizationId),
            )
            ->when(
                $this->ignoreUserId !== null,
                fn ($query) => $query->whereKeyNot($this->ignoreUserId),
            )
            ->exists();

        if ($existe) {
            $fail('validation.unique')->translate(['attribute' => $attribute]);
        }
    }

    /**
     * Les doublons LOGIQUES d'une adresse, toutes Organizations confondues.
     *
     * Sert la garde de suppression d'Organization (arbitrage MASTER D1) : avant
     * de detacher un membre, on doit savoir si son adresse est portee par un
     * AUTRE compte — orphelin ou non. Pose ici parce que c'est la meme question
     * que ci-dessus, a la portee pres : les deux doivent employer le meme
     * `LOWER()`, et le jour ou l'une changera l'autre devra suivre.
     *
     * @param  list<string>  $adresses
     * @return \Illuminate\Support\Collection<int, string> les adresses en conflit
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
