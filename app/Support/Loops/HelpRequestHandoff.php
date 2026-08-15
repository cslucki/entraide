<?php

namespace App\Support\Loops;

use App\Models\Loop;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Transfert de la clarification entre le POST « Qui peut m'aider ? » et
 * l'ecran de la Boucle qui la rend (TASK-1211).
 *
 * Pourquoi pas un flash de session : la page d'une Boucle interroge le serveur
 * toutes les 3 s (`wire:poll` de ChatLoop). Un flash ne vit que jusqu'a la
 * sauvegarde de la requete SUIVANTE — et la suivante est presque toujours un
 * poll servi entre la redirection et son GET, pas l'ecran attendu. La
 * clarification reussie disparaissait alors sans la moindre erreur (recette
 * Emergence, 2026-08-15). Une session ecrite en dernier-gagnant sous requetes
 * concurrentes n'est pas non plus un depot fiable pour ce transfert : il vit
 * hors session, borne a l'utilisateur et a la Boucle, et n'est lu qu'une fois
 * par l'ecran qui l'affiche.
 */
final class HelpRequestHandoff
{
    private const TTL_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $analysis
     */
    public function store(User $user, Loop $loop, array $analysis, string $intention): void
    {
        Cache::put(
            $this->key($user, $loop),
            ['analysis' => $analysis, 'intention' => $intention],
            now()->addMinutes(self::TTL_MINUTES),
        );
    }

    /**
     * Lecture unique : l'ecran qui affiche la clarification la consomme.
     *
     * @return array{analysis: array<string, mixed>, intention: string}|null
     */
    public function pull(User $user, Loop $loop): ?array
    {
        $key = $this->key($user, $loop);
        $handoff = Cache::get($key);

        // Chemin courant (aucun transfert en attente) : une seule lecture,
        // pas de suppression a vide a chaque affichage de Boucle.
        if ($handoff === null) {
            return null;
        }

        Cache::forget($key);

        if (! is_array($handoff) || ! is_array($handoff['analysis'] ?? null)) {
            return null;
        }

        return [
            'analysis' => $handoff['analysis'],
            'intention' => (string) ($handoff['intention'] ?? ''),
        ];
    }

    public function has(User $user, Loop $loop): bool
    {
        return Cache::has($this->key($user, $loop));
    }

    private function key(User $user, Loop $loop): string
    {
        return 'loops:help-request-handoff:'.$user->id.':'.$loop->id;
    }
}
