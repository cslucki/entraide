<?php

namespace App\Services\Users;

use App\Models\AccountLink;
use App\Models\User;
use App\Services\Users\Exceptions\AccountLinkRefused;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * TASK-1731 (A5b) — l'autorite unique des liens entre comptes.
 *
 * ## Pourquoi la verification des mots de passe est ICI et pas dans l'ecran
 *
 * « Un lien ne se cree qu'avec la preuve de controle des DEUX comptes » est
 * une regle de securite, pas une regle de formulaire. Laissee au controleur,
 * elle se serait perdue le jour ou un second chemin de creation apparait — une
 * commande, un ecran d'administration, un test de confort. En la posant dans
 * le service, aucun appelant ne peut creer un lien sans fournir les deux
 * preuves : la signature l'exige.
 *
 * ## UN SEUL SAUT, jamais de transitivite
 *
 * Rien dans le schema n'interdit qu'un lien A-B et un lien B-C fassent croire
 * a un lien A-C : c'est une propriete du CODE. Elle tient a une seule chose —
 * {@see lienVerrouilleEntre()} cherche le lien DIRECT entre deux comptes
 * nommes, et aucune methode de cette classe ne parcourt de chemin. Ne jamais
 * ajouter ici de fermeture transitive, meme « pour afficher les comptes
 * joignables ».
 */
class AccountLinkService
{
    /**
     * La paire, toujours rangee dans le meme ordre.
     *
     * C'est ce qui rend l'unique index suffisant : sans normalisation, (A,B)
     * et (B,A) seraient deux lignes acceptables pour un seul fait.
     *
     * @return array{0: string, 1: string}
     */
    public static function paireNormalisee(User $x, User $y): array
    {
        $a = (string) $x->getKey();
        $b = (string) $y->getKey();

        return $a <= $b ? [$a, $b] : [$b, $a];
    }

    /**
     * Cree le lien, en exigeant les deux preuves de controle.
     *
     * @throws AccountLinkRefused
     */
    public function creer(
        User $source,
        User $cible,
        string $motDePasseSource,
        string $motDePasseCible,
    ): AccountLink {
        if ((string) $source->getKey() === (string) $cible->getKey()) {
            // Interdit en base sur PostgreSQL par une contrainte CHECK ; tenu
            // ici sur les deux moteurs, et mesure par un test.
            throw AccountLinkRefused::autoLiaison();
        }

        if (! Hash::check($motDePasseSource, $source->password)
            || ! Hash::check($motDePasseCible, $cible->password)) {
            // UN seul refus pour les deux cotes : distinguer dirait lequel des
            // deux mots de passe est bon, donc transformerait cet ecran en
            // moyen d'eprouver le mot de passe d'un compte qu'on ne controle
            // pas. Meme raison qu'au login (T1728) et qu'a la bascule (T1730).
            throw AccountLinkRefused::preuveInsuffisante();
        }

        [$a, $b] = self::paireNormalisee($source, $cible);

        if ($this->lienEntre($source, $cible) !== null) {
            throw AccountLinkRefused::dejaLie();
        }

        return AccountLink::query()->create([
            'user_a_id' => $a,
            'user_b_id' => $b,
            'created_by_user_id' => $source->getKey(),
        ]);
    }

    /**
     * TASK-1734 — cree le lien par la preuve de la BOITE, sans mot de passe.
     *
     * ## Pourquoi une seconde methode, et pas un parametre de `creer()`
     *
     * `creer()` exige les deux mots de passe PAR SIGNATURE, et c'est son
     * contrat de securite : aucun appelant ne peut l'invoquer sans les deux
     * preuves. Affaiblir cette signature d'un parametre optionnel aurait
     * dissous le contrat. La preuve alternative a donc sa propre porte, dans
     * la MEME autorite — jamais un `AccountLink::create()` direct ailleurs.
     *
     * ## Ce que cette preuve-ci exige
     *
     * L'appelant (le controleur de confirmation) garantit : session
     * authentifiee du compte SOURCE + jeton a usage unique livre a la boite
     * ({@see AccountLinkEmailProof}). Cette methode re-exige ce que le jeton
     * ne transporte pas :
     *
     *  - la MEME adresse des deux cotes, comparee en `LOWER()` comme partout
     *    depuis A7, et comparee MAINTENANT — une adresse peut avoir change
     *    entre l'emission du jeton et sa consommation, et la preuve de la
     *    boite ne vaut que si les deux comptes la partagent encore ;
     *  - l'adresse VERIFIEE des deux cotes : un compte dont la boite n'a
     *    jamais confirme l'adresse n'a pas prouve qu'elle est la sienne.
     *
     * Les comptes d'adresses DIFFERENTES gardent leur porte : `creer()`,
     * avec les deux mots de passe.
     *
     * @throws AccountLinkRefused
     */
    public function creerParPreuveDeBoite(User $source, User $cible): AccountLink
    {
        if ((string) $source->getKey() === (string) $cible->getKey()) {
            throw AccountLinkRefused::autoLiaison();
        }

        if (mb_strtolower($source->email) !== mb_strtolower($cible->email)) {
            throw AccountLinkRefused::emailsDifferents();
        }

        if (! $source->hasVerifiedEmail() || ! $cible->hasVerifiedEmail()) {
            throw AccountLinkRefused::emailNonVerifie();
        }

        if ($this->lienEntre($source, $cible) !== null) {
            throw AccountLinkRefused::dejaLie();
        }

        [$a, $b] = self::paireNormalisee($source, $cible);

        return AccountLink::query()->create([
            'user_a_id' => $a,
            'user_b_id' => $b,
            'created_by_user_id' => $source->getKey(),
        ]);
    }

    /**
     * Le lien DIRECT entre ces deux comptes, ou `null`. Sans verrou.
     *
     * Pour une LECTURE seulement — affichage, controle preliminaire. La
     * decision de basculer passe par {@see lienVerrouilleEntre()}.
     */
    public function lienEntre(User $x, User $y): ?AccountLink
    {
        [$a, $b] = self::paireNormalisee($x, $y);

        return AccountLink::query()
            ->where('user_a_id', $a)
            ->where('user_b_id', $b)
            ->first();
    }

    /**
     * Le lien direct, VERROUILLE — a appeler DANS la transaction qui decide.
     *
     * ## Pourquoi le verrou ne suffit pas a lui seul (precision MASTER)
     *
     * `lockForUpdate()` ne prouve rien s'il est relache avant la decision :
     * entre le relachement et la bascule, une revocation concurrente
     * passerait. Le verrou doit donc etre pris ET RESTER DETENU jusqu'au point
     * ou l'autorisation est effectivement accordee — c'est-a-dire jusqu'a la
     * fin de la transaction de l'appelant. Cette methode ne commit rien et
     * n'ouvre pas sa propre transaction : elle serait sinon relachee en
     * sortant, et le verrou n'aurait servi a rien.
     */
    public function lienVerrouilleEntre(User $x, User $y): ?AccountLink
    {
        [$a, $b] = self::paireNormalisee($x, $y);

        return AccountLink::query()
            ->where('user_a_id', $a)
            ->where('user_b_id', $b)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Les liens de ce compte, quel que soit le cote de la paire ou il se
     * trouve.
     *
     * @return Collection<int, AccountLink>
     */
    public function liensDe(User $compte): Collection
    {
        return AccountLink::query()
            ->with(['userA.organization', 'userB.organization'])
            ->where(fn ($requete) => $requete
                ->where('user_a_id', $compte->getKey())
                ->orWhere('user_b_id', $compte->getKey()))
            ->get();
    }

    /**
     * Revoque un lien, depuis l'un OU l'autre des deux comptes.
     *
     * Arbitrage MASTER : la revocation retire le DROIT DE BASCULE, et rien de
     * plus. Elle ne deconnecte pas les appareils du compte lie — ce serait
     * disproportionne, et cela offrirait a chacun des deux un moyen de
     * deconnecter l'autre a volonte.
     *
     * @throws AccountLinkRefused
     */
    public function revoquer(User $demandeur, AccountLink $lien): void
    {
        if (! $lien->concerne($demandeur)) {
            // Un tiers ne revoque pas le lien de quelqu'un d'autre.
            throw AccountLinkRefused::etranger();
        }

        $lien->delete();
    }
}
