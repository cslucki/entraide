<?php

namespace App\Services\OpenSource;

/**
 * TASK-1612 — le plafond d'appels sortants d'un rafraichissement.
 *
 * L'API GitHub anonyme accorde 60 requetes par heure et par IP. En
 * production, cette IP est celle de la sortie de l'hebergeur : elle n'est
 * pas a nous seuls. Un compteur explicite vaut mieux qu'un raisonnement sur
 * la taille de la racine, qui changera sans prevenir.
 *
 * Le budget ne fait PAS echouer le rafraichissement : il le tronque. Un
 * appel refuse rend un dossier sans message de commit, jamais une racine
 * vide.
 */
class CallBudget
{
    private int $remaining;

    public function __construct(int $budget)
    {
        $this->remaining = max(0, $budget);
    }

    /**
     * Reserve un appel. `false` = plafond atteint, l'appel ne doit pas partir.
     */
    public function consume(): bool
    {
        if ($this->remaining <= 0) {
            return false;
        }

        $this->remaining--;

        return true;
    }

    public function remaining(): int
    {
        return $this->remaining;
    }
}
