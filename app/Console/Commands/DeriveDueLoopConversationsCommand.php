<?php

namespace App\Console\Commands;

use App\Services\Knowledge\LoopConversationKnowledgeDispatcher;
use Illuminate\Console\Command;

/**
 * TASK-1539 — le declencheur automatique.
 *
 * Il ne compile rien lui-meme : il choisit les Boucles posees et porteuses de
 * nouveaute, et leur dispatche un job. Tout le discernement vit dans
 * `LoopConversationKnowledgeDispatcher`, et tout le refus dans le deriver.
 *
 * `knowledge:derive-loop-conversations` reste disponible pour une compilation
 * DEMANDEE, sur une Boucle precise, sans attendre la fenetre d'inactivite.
 */
class DeriveDueLoopConversationsCommand extends Command
{
    protected $signature = 'knowledge:derive-due';

    protected $description = 'Compile les conversations des Boucles qui se sont posees et ont du nouveau.';

    public function handle(LoopConversationKnowledgeDispatcher $dispatcher): int
    {
        $n = $dispatcher->dispatchDue();

        $this->info($n === 0
            ? 'Aucune Boucle a compiler.'
            : "{$n} Boucle(s) mise(s) en file sur « ".LoopConversationKnowledgeDispatcher::DEDICATED_QUEUE." ».");

        return self::SUCCESS;
    }
}
