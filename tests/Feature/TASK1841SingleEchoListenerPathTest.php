<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use Tests\TestCase;

/**
 * TASK-1841 (revue MASTER, 3e passe) — garantit par construction qu'il
 * n'existe qu'UN SEUL chemin d'ecoute effectif pour `.loop.message.created`.
 *
 * Avant cette TASK, `LoopChat::getListeners()` declarait une cle
 * `"echo-private:loop.{id},.loop.message.created" => 'pollChat'` : Livewire
 * posait ALORS sa propre liaison JS native, EN PLUS de celle que
 * `resources/js/chatloop-adaptive-polling.js` pose lui-meme sur le meme
 * canal. Le diagnostic du NO-GO MASTER a montre que la liaison native de
 * Livewire est peu fiable (course au chargement de `window.Echo`) — mais
 * rien ne garantissait l'inverse non plus : si Echo finissait par etre pret
 * a temps un jour, les DEUX liaisons auraient pu reussir en meme temps et
 * declencher DEUX requetes Livewire par message recu. `pollChat()` etant
 * idempotent cote resultat affiche mais pas cote NOMBRE de requetes HTTP,
 * aucun controle de doublons en base n'aurait jamais revele ce surcout.
 *
 * `getListeners()` a donc ete SUPPRIMEE de `LoopChat` plutot que laissee
 * « par prudence » a cote de l'ecoute directe du module Alpine. Ce test
 * verrouille cette suppression : si quelqu'un la rajoute un jour (ou
 * ajoute un autre mecanisme d'ecoute Echo cote PHP pour ce composant), ce
 * test rougit et force une decision explicite plutot qu'une regression
 * silencieuse.
 */
class TASK1841SingleEchoListenerPathTest extends TestCase
{
    public function test_loop_chat_ne_declare_pas_sa_propre_methode_getListeners(): void
    {
        $reflection = new \ReflectionMethod(LoopChat::class, 'getListeners');

        $this->assertNotSame(
            LoopChat::class,
            $reflection->getDeclaringClass()->getName(),
            'LoopChat surcharge a nouveau getListeners() : verifier que ce n est pas une resurrection de la '
            .'cle "echo-private:loop.{id},.loop.message.created" retiree lors de la revue MASTER du NO-GO '
            .'TASK-1841 — le declenchement temps reel de pollChat() doit rester le fait du SEUL module '
            .'resources/js/chatloop-adaptive-polling.js (ecoute directe du canal Echo), jamais d un second '
            .'chemin cote PHP jamais garanti inoffensif.'
        );
    }
}
