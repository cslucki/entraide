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
 * `referrals` et `referral_rewards` l'ont ete jusqu'a TASK-1633. Leur
 * migration de creation omettait la contrainte, et celle qui la posait etait
 * conditionnee a une colonne LEGACY (`community_id`) : la PROD l'avait, une
 * installation fraiche non. `2026_09_24_190000_converge_referral_organization_foreign_keys`
 * a ferme cette divergence — elles sortent donc de cette liste.
 *
 * Il reste qu'en **SQLite** la contrainte ne peut pas etre ajoutee apres coup
 * (le moteur refuse `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY`, mesure).
 * Sur ce moteur seulement, les deux tables restent sans cle etrangere. C'est
 * une limite d'outillage, pas une decision produit : d'ou une liste SEPAREE,
 * `enginePendingTables()`, et surtout pas un retour dans les exceptions
 * volontaires.
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
     * Les tables dont la cle etrangere existe en PostgreSQL mais que SQLite
     * ne peut pas porter.
     *
     * TASK-1633 a pose les deux contraintes manquantes sur `referrals` et
     * `referral_rewards`. SQLite refuse `ALTER TABLE ... ADD CONSTRAINT ...
     * FOREIGN KEY` : il n'accepte une cle etrangere qu'a la creation de la
     * table. Sur ce moteur, ces deux-la restent donc sans contrainte.
     *
     * Cette liste est volontairement SEPAREE des exceptions volontaires : ce
     * n'est pas une decision produit, c'est une limite d'outillage, et elle
     * doit disparaitre le jour ou ces tables seraient recreees. Elle est aussi
     * volontairement NOMMEE plutot que deduite : une troisieme table sans
     * contrainte apparaitrait dans la garde, sur les deux moteurs.
     *
     * @return list<string>
     */
    public function enginePendingTables(string $column): array
    {
        return match ($column) {
            'organization_id' => ['referral_rewards', 'referrals'],
            default => [],
        };
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
