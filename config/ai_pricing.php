<?php

/*
|--------------------------------------------------------------------------
| Catalogue tarifaire IA — TASK-1132 / IA P1-2
|--------------------------------------------------------------------------
|
| Configuration VERSIONNEE (pas de table metier, pas de quatrieme registre).
| Ce catalogue repond a une seule question : « quel est le tarif courant connu
| de ce couple provider + modele ? ».
|
| Ce que ce catalogue N'EST PAS :
| - il ne reproduit pas la facture du fournisseur ;
| - il n'est pas une source d'autorisation ni un garde economique ;
| - il ne remplace aucun tenant scope (Organization = Tenant).
|
| La cible est `usage observe x tarif courant connu`. Rien de plus.
|
| REGLE ABSOLUE
| Une entree absente = tarif INCONNU, jamais gratuit. Le lecteur
| (App\Support\Ai\AiPricingCatalog) ne renvoie JAMAIS 0 silencieusement : il
| renvoie un cout inconnu explicite.
|
| REGLE DU TARIF NUL
| Un tarif reellement nul doit etre declare `'free' => true`. Une entree a 0.0
| SANS ce marqueur est traitee comme INVALIDE (donc inconnue), afin qu'une
| coquille ne puisse jamais rendre un modele payant silencieusement gratuit.
|
| COMMENT ETENDRE
| Ajouter une entree sous `models.<provider>.<modele>` avec les deux taux en
| USD par million de tokens, puis avancer `version` a la date du releve. Le
| couple provider/modele est celui passe au client HTTP, pas la chaine
| `provider/modele` stockee dans `ai_interactions.model`.
|
*/

return [

    /*
    | Date du releve des tarifs ci-dessous. Tracee dans les diagnostics pour
    | qu'un cout mesure soit toujours rattachable a une version de catalogue.
    */
    'version' => '2026-08-17',

    'currency' => 'USD',

    /*
    | Taux en USD par million de tokens.
    |
    | La clef `'*'` declare un provider dont le modele economique est connu
    | pour TOUS ses modeles. Reservee aux cas ou l'absence de facturation est
    | une propriete du provider lui-meme, pas une hypothese sur un modele :
    | execution locale (ollama) ou reponse produite sans aucun appel LLM
    | (rule_based).
    */
    'models' => [

        'openai' => [
            // Tarif public OpenAI releve le 2026-08-12.
            'gpt-4o-mini' => ['input_per_1m' => 0.15, 'output_per_1m' => 0.60],
            // TASK-1222 : tarif public OpenAI des embeddings, releve le
            // 2026-08-17. Un embedding n'a pas de tokens de sortie : le taux
            // output est un VRAI zero de structure, pas un tarif inconnu.
            'text-embedding-3-small' => ['input_per_1m' => 0.02, 'output_per_1m' => 0.0],
        ],

        'openrouter' => [
            // OpenRouter revend ce modele au tarif de liste OpenAI.
            // Les autres modeles OpenRouter restent volontairement absents :
            // leur tarif varie par modele et n'a pas ete releve. Ils valent
            // donc `cost_unknown`, jamais 0.
            'openai/gpt-4o-mini' => ['input_per_1m' => 0.15, 'output_per_1m' => 0.60],
            // TASK-1222 : meme regle de passthrough que gpt-4o-mini ci-dessus,
            // pour la famille d'embedding reellement active sur le banc. La
            // clef porte l'identifiant OpenRouter REEL du modele (prefixe
            // `openai/`, cf. ai.providers.openrouter.models.embeddings).
            'openai/text-embedding-3-small' => ['input_per_1m' => 0.02, 'output_per_1m' => 0.0],
        ],

        'ollama' => [
            // Execution locale : aucun appel facture, quel que soit le modele.
            '*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true],
        ],

        'rule_based' => [
            // Reponse deterministe sans LLM : il n'y a pas d'appel a facturer.
            '*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true],
        ],

    ],

    /*
    | Surcharge operateur, par provider, appliquee a tous ses modeles.
    |
    | Preserve le contrat `.env` existant (OPENAI_INPUT_PRICE_PER_1M /
    | OPENAI_OUTPUT_PRICE_PER_1M) sans reintroduire de defaut fabrique : sans
    | valeur, la surcharge est simplement inerte et le catalogue s'applique.
    | Une surcharge partielle (un seul des deux taux) est ignoree, car un tarif
    | a moitie declare n'est pas un tarif connu.
    */
    'overrides' => [
        'openai' => [
            'input_per_1m' => env('OPENAI_INPUT_PRICE_PER_1M'),
            'output_per_1m' => env('OPENAI_OUTPUT_PRICE_PER_1M'),
        ],
    ],

];
