<?php

namespace App\Support\Integrity;

/**
 * TASK-1632 — les tables qui portent une reference SANS cle etrangere, et ce
 * que cette absence signifie.
 *
 * ## Pourquoi cette liste est si courte, et pourquoi c'est le bon perimetre
 *
 * Exiger une classification metier des 137 tables portant `organization_id`,
 * `loop_id` ou `dossier_id` produirait environ 140 regles dont la quasi-
 * totalite dirait la meme chose : « FK presente, rien a verifier ». C'est le
 * contraire d'une garde utile — la meme erreur que le registre assign-data,
 * dans l'autre sens.
 *
 * La garde porte donc uniquement la ou une reference cassee est POSSIBLE :
 * les tables sans contrainte. Releve sur la base reelle —
 * **3 pour `organization_id`, 0 pour `loop_id`, 0 pour `dossier_id`**.
 *
 * ## Les trois, et ce qui les separe
 *
 * `ai_provider_invocations` est une exception DOCUMENTEE : la FK a ete
 * retiree par une migration dediee (`2026_08_19_150000`) pour que le ledger
 * economique survive a la disparition d'une Organization. Une invocation
 * facturee reste facturee ; son UUID d'Organization disparue est une trace,
 * pas un defaut.
 *
 * `referrals` et `referral_rewards` sont autre chose. Leurs migrations
 * (`2026_05_13_000001` et `_000002`) declarent
 * `$table->uuid('organization_id')->nullable()->index()` — un UUID indexe,
 * sans `foreign()` — alors que les colonnes voisines de la MEME migration
 * (`referrer_user_id`, `referred_user_id`) portent bien leur contrainte.
 * Aucun commentaire, aucune migration ne l'explique : c'est une omission, pas
 * une decision. Ce sont donc les deux seuls endroits du produit ou supprimer
 * une Organization peut laisser une reference pointant dans le vide sans que
 * rien ne le signale.
 *
 * L'outil le DIT. Il ne corrige rien : ajouter une FK serait un changement de
 * schema, et la decision appartient a MASTER.
 */
class UnprotectedReferenceRegistry
{
    /**
     * Trace volontaire : la reference survit a son parent, par decision.
     * Ne sera JAMAIS signalee comme une action a mener.
     */
    public const PROTECTED_HISTORY = 'protected_history';

    /**
     * Rien ne garantit la reference, et rien ne l'explique : une valeur
     * pointant dans le vide est un vrai defaut.
     */
    public const UNGUARDED = 'unguarded';

    /**
     * @return array<string, array<string, string>> colonne => table => nature
     */
    public function all(): array
    {
        return [
            'organization_id' => [
                'ai_provider_invocations' => self::PROTECTED_HISTORY,
                'referrals' => self::UNGUARDED,
                'referral_rewards' => self::UNGUARDED,
            ],
            // Aucune : les 24 tables a `loop_id` et les 6 a `dossier_id` sont
            // integralement couvertes par des FK. Les cles restent declarees
            // pour que la garde de couverture ait un point de comparaison
            // explicite plutot qu'un tableau absent.
            'loop_id' => [],
            'dossier_id' => [],
        ];
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, string> table => nature
     */
    public function forColumn(string $column): array
    {
        return $this->all()[$column] ?? [];
    }

    /**
     * Les tables dont une reference cassee serait un VRAI defaut.
     *
     * @return list<string>
     */
    public function unguardedTables(string $column): array
    {
        return array_keys(array_filter(
            $this->forColumn($column),
            fn (string $nature) => $nature === self::UNGUARDED
        ));
    }

    /**
     * Les tables dont une reference survivante est voulue.
     *
     * @return list<string>
     */
    public function protectedHistoryTables(string $column): array
    {
        return array_keys(array_filter(
            $this->forColumn($column),
            fn (string $nature) => $nature === self::PROTECTED_HISTORY
        ));
    }
}
