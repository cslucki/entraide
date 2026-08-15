<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;

/**
 * Une source de contexte autorisee (TASK-1209 / IA P3).
 *
 * Interface volontairement minuscule : deux sources existent aujourd'hui, et le
 * seul point commun qu'on veuille leur imposer est « produis ton fragment de
 * contexte dans ce budget, et dis d'ou il vient ».
 *
 * Ce n'est ni un pipeline, ni un systeme de plugins, ni un bus d'evenements :
 * le `ContextBuilder` connait ses sources par un tableau, et une source ignore
 * tout des autres.
 */
interface ContextSource
{
    /**
     * Identifiant declare dans `CapabilityDefinition::$allowedSources`.
     */
    public function name(): string;

    /**
     * Fragment de contexte, dans la limite du budget restant.
     *
     * Une source qui n'a rien a dire rend un fragment vide — ce n'est pas un
     * refus. Un refus se signale en levant `SourceDenied`.
     *
     * @throws SourceDenied
     */
    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment;
}
