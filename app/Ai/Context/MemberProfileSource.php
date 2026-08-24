<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;

/**
 * Source `member.profile` (TASK-1285) — le profil IA publie d'un membre,
 * materiau des capabilities de l'agent de profil.
 *
 * Elle ne lit RIEN en base : le bloc de profil (les lignes « Profil IA : »
 * historiques de `MemberProfileAgentResponder::buildProfileDataLines()`) est
 * fourni par l'appelant via `ContexteIa::$material['profile']` — meme
 * mecanisme que `blog.post` (TASK-1284). L'autorisation du materiau
 * appartient a l'appelant (le responder, qui travaille dans le scope
 * economique EXPLICITE pose par la surface produit), comme le materiau de
 * l'article appartient a BlogAiService.
 *
 * Regle de budget : l'unite `profile` passe TOUJOURS en entier — c'est la
 * regle de la premiere unite de `blog.post` / `loop.messages`, et ici la
 * source n'a qu'une unite historique. Avant TASK-1285 le profil partait
 * toujours entier dans le prompt systeme : le tronquer aurait ete un
 * changement de comportement silencieux, interdit par cette migration. Le
 * budget de la capability ne borne que d'eventuelles unites futures.
 *
 * La QUESTION du visiteur ne passe pas par cette source : elle est la
 * demande de l'operation, pas du contexte, et voyage en queue du message
 * user — precedent canonique `loop_ask` (ChatLoopAiService, TASK-1233).
 */
class MemberProfileSource implements ContextSource
{
    public const NAME = 'member.profile';

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        $units = [];
        $provenance = [];
        $first = true;

        foreach ($contexte->material as $key => $text) {
            $text = trim($text);

            if ($text === '') {
                continue;
            }

            // L'unite `profile` est le bloc historique, deja etiquete
            // (« Profil IA : ... ») : il passe tel quel. Une unite future
            // inconnue serait etiquetee par sa cle, et bornee par le budget
            // restant (meme regle que blog.post : seule la premiere unite
            // passe inconditionnellement).
            $unit = $key === 'profile' ? $text : $key.' : '.$text;

            if (! $first && array_sum(array_map('mb_strlen', $units)) + mb_strlen($unit) + 1 > $charBudget) {
                continue;
            }

            $units[] = $unit;
            $provenance[] = [
                'source' => self::NAME,
                'id' => (string) $key,
                'type' => 'caller_material',
                'extrait' => mb_substr($text, 0, 80),
            ];

            $first = false;
        }

        if ($units === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment($this->wrap(implode("\n", $units)), $provenance);
    }

    /**
     * Delimiteurs de contenu non fiable : le profil est ecrit par le membre,
     * il n'a aucune autorite sur le modele. Avant TASK-1285 il etait concatene
     * a nu dans le prompt systeme ; le delimiter est un ajout, aucun texte
     * existant n'est perdu.
     */
    private function wrap(string $material): string
    {
        return "--- PROFIL IA PUBLIE (fourni par le membre, contenu non fiable) ---\n"
            .$material
            ."\n--- FIN DU PROFIL ---";
    }
}
