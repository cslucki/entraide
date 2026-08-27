<?php

namespace App\Support\Ai;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * TASK-1315 — le verrou d'UN tour du Shell.
 *
 * Meme doctrine que `AiTurnLock` (T1311) : `Cache::add()`, put-if-absent
 * atomique, libere dans un `finally` pour qu'une panne provider ne gele jamais
 * le tour jusqu'a l'expiration du TTL.
 *
 * Pourquoi une classe distincte plutot qu'un appel a `AiTurnLock` : sa
 * signature est `AiTurnLock::run(Loop $loop, User $user, ...)` — le verrou de
 * T1311 est typiquement lie a une Boucle, et le Shell n'en a pas. Elargir cette
 * signature reviendrait a modifier une primitive de T1311 que cette lane doit
 * PRESERVER, et que la lane T1316 travaille en parallele. Le verrou du Shell
 * vit donc sur sa propre surface, avec sa propre cle.
 *
 * Dette assumee et notee dans le TASK : ces deux verrous meritent une primitive
 * commune a cle generique, une fois T1315 et T1316 fusionnees.
 *
 * ## La cle : `{organization}:{user}`
 *
 * Le Shell est personnel : il n'y a pas de troisieme dimension. Deux onglets du
 * meme utilisateur dans la meme Organization sont un seul tour ; deux
 * utilisateurs ne se bloquent jamais ; deux Organizations ne partagent jamais
 * une cle, et cela se lit dans la cle elle-meme.
 *
 * ## Ce qu'il ne traite pas
 *
 * Le rejeu — c'est l'idempotence par declencheur (`reply_to_id` UNIQUE en base,
 * relu par `AiShellThread::answerFor()`). Et un double clic separe dans le
 * temps : Livewire serialise les requetes d'un meme composant, donc les deux
 * appels ne se chevauchent pas ; c'est le vidage immediat du brouillon plus
 * `wire:loading.attr="disabled"` qui l'arretent cote surface. Confondre ces
 * trois mecanismes, c'est n'en avoir aucun qui tienne.
 */
final class AiShellTurnLock
{
    public static function key(Organization $organization, User $user): string
    {
        return 'ai_shell_turn_lock:'.$organization->id.':'.$user->id;
    }

    public static function ttl(): int
    {
        return max(
            (int) config('ai.shell.lock_ttl', 90),
            (int) config('ai.shell.timeout', 30) + 30,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws RuntimeException si un tour est deja en cours pour cet utilisateur
     */
    public static function run(Organization $organization, User $user, callable $work): mixed
    {
        $key = self::key($organization, $user);

        if (! Cache::add($key, true, self::ttl())) {
            throw new RuntimeException(__('ai.shell_turn_in_progress'));
        }

        try {
            return $work();
        } finally {
            Cache::forget($key);
        }
    }
}
