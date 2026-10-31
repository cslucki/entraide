<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * TASK-1734 — la preuve par la boite : le jeton de premiere association.
 *
 * ## Ce que ce jeton prouve, et ce qu'il ne prouve pas
 *
 * Il prouve que la personne qui confirme LIT la boite mail que les deux
 * comptes partagent. Combine a la session authentifiee du compte source
 * (exigee par le controleur), le niveau de preuve est celui du reset de mot
 * de passe — qui donne deja le controle de n'importe quel compte de cette
 * boite — PLUS une liaison de session que le broker de reset n'a pas. Il
 * n'ouvre donc aucune classe de prise de controle nouvelle.
 *
 * Il ne prouve RIEN a lui seul : consomme par une autre session que celle du
 * compte source, il est refuse — et il est deja brule, c'est voulu.
 *
 * ## Pourquoi le cache, et pas une table
 *
 * `CACHE_STORE=database` sur tous les environnements : le jeton survit aux
 * processus et aux workers. Une table dediee porterait des FK vers `users`,
 * donc DEUX declarations de completude obligatoires (registre de cycle de
 * vie + preflight Scenario) pour un etat qui vit quinze minutes. Le cache
 * n'entre dans aucun de ces perimetres, et une purge de cache ne coute qu'un
 * renvoi d'email.
 *
 * ## Les trois proprietes defendues ici
 *
 *  - **Jamais en clair au repos** : la cle est le SHA-256 du jeton. Qui lit
 *    la table `cache` ne peut pas reconstituer l'URL de confirmation.
 *  - **Usage unique** : {@see consommer()} tient un verrou le temps du
 *    lire-puis-effacer. Deux confirmations concurrentes — deux onglets, un
 *    double clic — n'en laissent passer qu'une.
 *  - **Un seul jeton vivant par paire** : chaque emission ECRASE la
 *    precedente via une cle d'index par paire. Renvoyer l'email n'accumule
 *    pas des jetons valides.
 *
 * `now()->timestamp` et jamais `time()` : l'horodatage d'emission doit obeir
 * au voyage dans le temps du banc de test (lecon T1731), sans quoi la
 * fenetre de quinze minutes serait inverifiable. L'expiration elle-meme est
 * portee par le TTL du cache, qui suit la meme horloge applicative dans le
 * banc (le store `array` des tests respecte `now()`).
 */
class AccountLinkEmailProof
{
    /** Quinze minutes : le temps d'ouvrir sa boite, pas celui de l'oublier. */
    public const TTL_SECONDES = 900;

    private const PREFIXE_JETON = 'mes-orgs:confirm:';

    private const PREFIXE_PAIRE = 'mes-orgs:confirm-paire:';

    /**
     * Emet un jeton pour la paire (source, cible) et rend sa valeur EN CLAIR.
     *
     * La valeur en clair ne vit que le temps de construire l'URL de l'email :
     * elle n'est ni journalisee, ni stockee ailleurs que hachee.
     */
    public function emettre(User $source, User $cible): string
    {
        $jeton = bin2hex(random_bytes(32));

        $clePaire = $this->clePaire($source, $cible);

        // Un seul jeton vivant par paire : on efface l'ancien AVANT de poser
        // le nouveau, pour qu'aucun instant n'en laisse deux valides.
        $ancienneCle = Cache::get($clePaire);

        if (is_string($ancienneCle)) {
            Cache::forget($ancienneCle);
        }

        $cle = $this->cleJeton($jeton);

        Cache::put($cle, [
            'source_id' => (string) $source->getKey(),
            'cible_id' => (string) $cible->getKey(),
            'emis_a' => now()->timestamp,
        ], self::TTL_SECONDES);

        Cache::put($clePaire, $cle, self::TTL_SECONDES);

        return $jeton;
    }

    /**
     * Lit la paire designee par un jeton, SANS le consommer.
     *
     * Pour le GET de confirmation uniquement : les scanners de boite
     * (SafeLinks et consorts) prechargent les liens des emails, et un GET qui
     * consommerait rendrait le bouton mort avant que la personne ne le voie.
     *
     * @return array{source_id: string, cible_id: string, emis_a: int}|null
     */
    public function lire(string $jeton): ?array
    {
        $valeur = Cache::get($this->cleJeton($jeton));

        return is_array($valeur) ? $valeur : null;
    }

    /**
     * Consomme le jeton : le lit ET l'efface, atomiquement.
     *
     * `null` couvre indistinctement : jeton inconnu, expire, deja consomme,
     * ou verrou indisponible. L'appelant n'a pas a les distinguer — le refus
     * est generique de toute facon.
     *
     * @return array{source_id: string, cible_id: string, emis_a: int}|null
     */
    public function consommer(string $jeton): ?array
    {
        $cle = $this->cleJeton($jeton);

        $verrou = Cache::lock($cle.':lock', 5);

        try {
            return $verrou->block(3, function () use ($cle): ?array {
                $valeur = Cache::get($cle);

                if (! is_array($valeur)) {
                    return null;
                }

                Cache::forget($cle);

                return $valeur;
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    private function cleJeton(string $jeton): string
    {
        return self::PREFIXE_JETON.hash('sha256', $jeton);
    }

    private function clePaire(User $source, User $cible): string
    {
        // La MEME normalisation que la table account_links : (A,B) et (B,A)
        // designent la meme paire, donc le meme emplacement d'index.
        [$a, $b] = AccountLinkService::paireNormalisee($source, $cible);

        return self::PREFIXE_PAIRE.$a.':'.$b;
    }
}
