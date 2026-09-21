<?php

namespace App\Support\Ai\Pricing;

/**
 * Source de tarif RESOLUE A L'APPEL, et le seul point d'extension
 * d'`AiPricingCatalog`. (TASK-1617)
 *
 * ## Pourquoi ce contrat existe
 *
 * `config/ai_pricing.php` est versionne : un tarif y est une affirmation
 * relue en revue et deployee. C'est ce qui rend `AiPricingCatalog` digne de
 * confiance, et ca ne change pas.
 *
 * Mais les modeles GRATUITS d'OpenRouter, eux, sont choisis par le SuperAdmin
 * depuis un ecran et peuvent cesser de l'etre sans qu'aucun humain ne
 * redeploie. Les declarer en configuration imposerait un commit a chaque
 * changement, et surtout : une entree versionnee ne saurait pas qu'un modele
 * est redevenu payant ce matin.
 *
 * ## Pourquoi PAS un ServiceProvider qui fusionne la config au boot
 *
 * Deux raisons dirimantes, et la seconde suffit :
 *
 * 1. `php artisan config:cache` SERIALISE la configuration dans un fichier.
 *    Une fusion faite au boot y serait FIGEE — le catalogue gele au moment du
 *    cache, donc une preuve de gratuite perimee servie indefiniment. C'est
 *    exactement l'inverse de la fraicheur exigee ;
 * 2. lire la base au boot rend `artisan migrate` (avant que la table existe),
 *    `config:cache` et tout demarrage avec une base indisponible dependants
 *    d'une requete SQL. Une commande Laravel ne doit pas mourir parce qu'une
 *    table metier manque.
 *
 * Resoudre A L'APPEL supprime les deux : rien n'est lu au boot, rien n'est
 * mis en cache par `config:cache`, et la fraicheur est evaluee au moment ou
 * la question est posee.
 *
 * ## Ce que ce contrat n'est PAS
 *
 * Ce n'est pas un second moteur de prix. L'implementation ne CALCULE aucun
 * cout : elle rend, ou non, une entree au format exact du catalogue, et c'est
 * `AiPricingCatalog` — seule — qui la valide et en tire un `AiCost`. Une
 * entree mal formee est rejetee par `validate()` comme n'importe quelle autre.
 */
interface DynamicPricingSource
{
    /**
     * Entree de tarif pour ce couple, ou `null` si cette source n'a rien a
     * dire.
     *
     * `null` n'est jamais « gratuit » : c'est « je ne sais pas », et
     * `AiPricingCatalog` en tirera un cout INCONNU. Toute incertitude — preuve
     * perimee, modele disparu, base injoignable — doit donc rendre `null`.
     *
     * @return array{input_per_1m: float, output_per_1m: float, free: bool}|null
     */
    public function entryFor(string $provider, ?string $model): ?array;
}
