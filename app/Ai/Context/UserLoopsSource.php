<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Models\Loop;

/**
 * Source `user.loops` (TASK-1209 / IA P3).
 *
 * Prepare TASK-1210 (« Qui peut m'aider ? » doit suggerer une Boucle) : elle
 * est implementee et testee, mais branchee a AUCUNE capability aujourd'hui.
 *
 * ## Pourquoi elle ne reutilise pas `getAccessibleLoopsQuery`
 *
 * Le catalogue de `LoopController` retourne toutes les Boucles actives de
 * l'Organization et se contente d'annoter `is_member` : un humain doit pouvoir
 * decouvrir une Boucle qu'il pourrait demander a rejoindre.
 *
 * Une suggestion IA n'a pas ce droit-la. La spec produit T074.2 est explicite —
 * l'IA « choisit parmi une liste de Loops dont le membre est membre » et
 * « utilise uniquement les Loops fournies par le serveur ». On retient donc le
 * perimetre strict : membre ACTIF, Boucle ACTIVE, Organization du contexte.
 *
 * La visibilite n'a pas a etre testee en plus : un membre actif peut utiliser
 * sa Boucle, publique ou privee. Le membership la subsume.
 *
 * ## Ce qu'elle expose
 *
 * Le minimum utile a une suggestion de cercle : identifiant, nom, type, et
 * `tagline` — le seul champ descriptif court que le modele possede deja.
 * `description` est un `text` potentiellement volumineux : hors sujet ici, on
 * ne charge pas un manifeste pour choisir une destination.
 */
/*
 * Non `final` a dessein : une source est un point d'extension du Context
 * Builder, et doit pouvoir etre doublee en test sans passer par un conteneur
 * d'abstractions. Les DTO et le builder, eux, restent fermes.
 */
class UserLoopsSource implements ContextSource
{
    public const NAME = 'user.loops';

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        if ($contexte->userId === null) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_NO_USER_IN_CONTEXT);
        }

        $loops = Loop::query()
            ->where('organization_id', $contexte->organizationId)
            ->where('status', 'active')
            ->whereHas('members', function ($query) use ($contexte): void {
                $query->where('user_id', $contexte->userId)->where('status', 'active');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'tagline', 'organization_id']);

        $lines = [];
        $provenance = [];
        $length = 0;

        foreach ($loops as $loop) {
            $line = $this->describe($loop);

            if ($length > 0 && $length + mb_strlen($line) + 1 > $charBudget) {
                break;
            }

            $lines[] = $line;
            $provenance[] = [
                'source' => self::NAME,
                'id' => (string) $loop->id,
                'type' => 'direct',
                'extrait' => (string) $loop->name,
            ];

            $length += mb_strlen($line) + 1;
        }

        if ($lines === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment(
            "--- BOUCLES AUTORISÉES ---\n".implode("\n", $lines)."\n--- FIN DES BOUCLES ---",
            $provenance,
        );
    }

    private function describe(Loop $loop): string
    {
        $line = '- '.$loop->id.' | '.$loop->name;

        if (is_string($loop->type) && trim($loop->type) !== '') {
            $line .= ' | '.trim($loop->type);
        }

        if (is_string($loop->tagline) && trim($loop->tagline) !== '') {
            $line .= ' | '.trim($loop->tagline);
        }

        return $line;
    }
}
