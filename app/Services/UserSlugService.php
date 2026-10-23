<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1693 bug Z — l'adresse publique d'un membre, a UN seul endroit.
 *
 * ## Pourquoi un slug
 *
 * `/org/ofsh/profile/01a15a3e-b8f3-718e-8a8a-c4a761a24f3d` ne se lit pas, ne
 * se retient pas et ne se dit pas a l'oral. `/org/ofsh/profile/marie-dupont`
 * fait les trois.
 *
 * A noter, parce que cela borne le benefice : la page de profil porte
 * `Authenticate` ET `EnsureOrganizationMember`. Le slug la rend donc LISIBLE,
 * pas PUBLIQUE — quelqu'un d'exterieur a l'Organization tombera toujours sur la
 * connexion puis sur un 404. Rouvrir les profils au public serait une autre
 * decision, que TASK-1479/1488 ont deliberement fermee.
 *
 * ## Les quatre regles, et ce qu'elles ferment
 *
 * **1. Un slug n'est JAMAIS recycle.** C'est la regle qui compte le plus.
 * Sans elle : Marie a `marie-dupont`, le partage, puis change pour `marie` ;
 * le slug se libere, Paul le prend, et TOUS les liens deja partages vers Marie
 * menent desormais a Paul. Une usurpation d'identite creee par la
 * fonctionnalite elle-meme. La table `user_slugs` garde donc chaque slug
 * ATTRIBUE a vie, et la resolution d'un ancien slug redirige vers le courant.
 *
 * **2. Unicite PAR ORGANIZATION**, jamais globale — Organization = Tenant.
 * Meme forme que `tags`, passe d'un unique global a un unique composite
 * `['organization_id', 'slug']` le 25/06/2026.
 *
 * **3. Des segments reserves.** `/profile/edit`, `/profile/ai-usage` et
 * `/profile/availability` partagent l'espace de `/profile/{user}`. Ils sont
 * declares AVANT (routes/web.php:430-437 contre 541) et gagnent donc la
 * resolution — mais quelqu'un dont le slug serait `edit` aurait un profil
 * INATTEIGNABLE. On les refuse a la saisie plutot que de laisser fabriquer une
 * adresse morte.
 *
 * **4. A la saisie, une collision se DIT.** Le suffixe automatique
 * (`marie-dupont-2`) ne vaut que pour la reprise des comptes existants, ou
 * personne n'est la pour arbitrer. Quand quelqu'un choisit, mieux vaut
 * « ce nom est deja pris » que de lui attribuer en douce une adresse qu'il n'a
 * pas voulue.
 */
class UserSlugService
{
    /**
     * Les segments qui appartiennent deja a l'espace `/profile/`, plus
     * quelques mots dont un usage futur serait pretant a confusion.
     *
     * Les trois premiers sont des ROUTES REELLES : un slug qui les porterait
     * rendrait le profil inatteignable.
     */
    public const RESERVES = [
        'edit', 'ai-usage', 'availability',
        'offers', 'agent-ia', 'me', 'new', 'create', 'update', 'destroy',
        'index', 'search', 'settings', 'admin', 'api', 'login', 'logout',
    ];

    public const MIN = 3;

    public const MAX = 60;

    /** La forme acceptee : minuscules, chiffres et tirets simples. */
    public const MOTIF = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Le slug derive d'un nom — `prenom-nom`, l'ordre dont on parle.
     *
     * Rend `null` quand aucun slug utilisable ne peut en sortir : un compte
     * sans nom exploitable GARDE son UUID, jusqu'a ce que la personne en
     * choisisse un. Fabriquer un `membre-1234` serait plus joli et ne voudrait
     * rien dire.
     */
    public function depuisLeNom(User $user): ?string
    {
        $candidat = Str::slug(trim(($user->first_name ?? '').' '.($user->name ?? '')));

        if ($candidat === '' || mb_strlen($candidat) < self::MIN) {
            return null;
        }

        return Str::limit($candidat, self::MAX, '');
    }

    /** La forme est-elle acceptable, independamment de toute disponibilite ? */
    public function formeValide(string $slug): bool
    {
        return preg_match(self::MOTIF, $slug) === 1
            && mb_strlen($slug) >= self::MIN
            && mb_strlen($slug) <= self::MAX
            && ! in_array($slug, self::RESERVES, true)
            // Un slug qui ressemble a un UUID brouillerait la resolution, qui
            // distingue les deux formes pour eviter un `22P02` PostgreSQL.
            && ! Str::isUuid($slug);
    }

    /**
     * Ce slug est-il disponible pour cette personne, dans son Organization ?
     *
     * Un slug qu'elle a DEJA porte lui reste acquis : elle peut y revenir.
     */
    public function disponiblePour(User $user, string $slug): bool
    {
        if (! $this->formeValide($slug)) {
            return false;
        }

        $proprietaire = UserSlug::where('organization_id', $user->organization_id)
            ->where('slug', $slug)
            ->value('user_id');

        return $proprietaire === null || $proprietaire === $user->id;
    }

    /**
     * Attribuer un slug, en RETIRANT l'ancien sans le liberer.
     *
     * L'ancien reste inscrit au registre, toujours au nom de la meme personne :
     * les liens deja partages continuent d'aboutir (par redirection), et
     * personne ne peut heriter de son adresse.
     */
    public function attribuer(User $user, string $slug): void
    {
        DB::transaction(function () use ($user, $slug) {
            UserSlug::where('user_id', $user->id)->update(['is_current' => false]);

            UserSlug::updateOrCreate(
                ['organization_id' => $user->organization_id, 'slug' => $slug],
                ['user_id' => $user->id, 'is_current' => true],
            );

            $user->forceFill(['slug' => $slug])->save();
        });
    }

    /**
     * Le slug derive du nom, suffixe tant qu'il est pris.
     *
     * Reserve a la REPRISE et a l'inscription, ou personne n'est la pour
     * arbitrer une collision.
     */
    public function attribuerDepuisLeNom(User $user): ?string
    {
        $base = $this->depuisLeNom($user);

        if ($base === null) {
            return null;
        }

        $candidat = $base;
        $n = 1;

        while (! $this->disponiblePour($user, $candidat)) {
            $n++;
            $candidat = Str::limit($base, self::MAX - mb_strlen((string) $n) - 1, '').'-'.$n;

            if ($n > 50) {
                return null;
            }
        }

        $this->attribuer($user, $candidat);

        return $candidat;
    }

    /**
     * Retrouver une personne depuis un segment d'URL, slug OU identifiant.
     *
     * Accepter les deux est ce qui permet au correctif de ne RIEN casser : les
     * 44 points du code qui construisent une URL de profil, les liens echanges
     * en message et les favoris continuent d'aboutir.
     *
     * `Str::isUuid()` AVANT toute comparaison : PostgreSQL leve `22P02` sur un
     * texte qui n'est pas un UUID compare a une colonne `uuid`, la ou SQLite
     * l'accepte sans broncher.
     *
     * @return array{0: ?User, 1: bool} la personne, et si le segment etait son
     *                                  slug COURANT
     */
    public function resoudre(string $segment, ?string $organizationId): array
    {
        if (Str::isUuid($segment)) {
            return [User::where('id', $segment)->first(), false];
        }

        if ($organizationId === null) {
            return [null, false];
        }

        $inscription = UserSlug::where('organization_id', $organizationId)
            ->where('slug', $segment)
            ->first();

        if ($inscription === null) {
            return [null, false];
        }

        return [User::where('id', $inscription->user_id)->first(), (bool) $inscription->is_current];
    }
}
