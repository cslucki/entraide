<?php

namespace App\Support\Ai;

use App\Models\Loop;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * TASK-1311 : le verrou d'UN tour IA de Boucle.
 *
 * ## Ce qu'il traite, et ce qu'il ne traite pas
 *
 * Ce verrou traite la **course** : double clic, deux onglets, deux requetes
 * concurrentes sur le meme tour. Il ne traite PAS le **rejeu** d'un tour deja
 * repondu — c'est le role de la garde d'idempotence par message declencheur
 * (`AiTurnIdempotency`). Les deux sont necessaires ; ils ne se remplacent pas.
 *
 * ## Ce n'est pas un mecanisme nouveau
 *
 * `ChatLoopAiService` portait deja exactement cette forme — `Cache::add()`,
 * meme TTL, meme message de refus — **copiee-collee a quatre endroits**. Cette
 * classe l'EXTRAIT ; elle ne l'invente pas. Le service documentaire
 * (`LoopKnowledgeAnswerService`) n'avait, lui, aucun verrou : ce n'etait pas
 * une decision d'architecture, seulement une garde jamais reportee.
 *
 * ## La cle : `{organization}:{loop}:{user}`
 *
 * | Cle | Double clic | Deux onglets | Deux membres simultanes |
 * |---|---|---|---|
 * | `{loop}` (l'ancienne) | bloque | bloque | **bloque — a tort** |
 * | `{org, loop, user}` | bloque | bloque | passe |
 *
 * Deux membres d'une meme Boucle sont deux tours REELLEMENT differents : les
 * bloquer l'un l'autre etait un effet de bord d'une cle trop grossiere, pas une
 * intention. L'`organization_id` en tete rend la frontiere de tenant visible et
 * prouvable — deux Organizations ne partagent jamais une cle, et cela se lit
 * dans la cle elle-meme plutot que de reposer sur l'unicite des ULID.
 *
 * Le **mode est volontairement absent** de la cle : un meme membre ne doit pas
 * pouvoir lancer IA dans un onglet et Dossiers dans un autre. Ce serait deux
 * depenses pour une seule personne — exactement ce que cette TASK combat.
 *
 * ## Reentrance dans une meme requete
 *
 * Le contrat produit exige qu'un double clic ne produise **qu'un** message
 * humain. Or ce message est cree AVANT l'appel IA : un verrou pose seulement
 * dans le service laisserait passer le second message humain tout en bloquant
 * la seconde generation. Le composeur doit donc pouvoir prendre le verrou en
 * amont — et le service, qui le reprend ensuite, ne doit pas echouer sur
 * lui-meme.
 *
 * D'ou `$heldInThisRequest` : un verrou deja tenu **par cette requete** est
 * reentrant. Deux requetes HTTP concurrentes sont deux processus distincts et
 * ne partagent pas ce tableau — la course reste arbitree par `Cache::add()`,
 * qui seul fait autorite.
 *
 * ## Atomicite
 *
 * `Cache::add()` est un put-if-absent atomique. En dev et en production le
 * store est `database` (`CACHE_STORE=database`). En test, `phpunit.xml` force
 * le store `array`, **par processus** : il ne prouve rien sur la concurrence.
 * La preuve reelle est faite ailleurs, multi-processus, sur le store partage.
 */
final class AiTurnLock
{
    /**
     * Les cles tenues par CETTE requete. Sert uniquement la reentrance
     * intra-requete : deux requetes concurrentes ne partagent pas ce tableau.
     *
     * @var array<string, true>
     */
    private static array $heldInThisRequest = [];

    public static function key(Loop $loop, User $user): string
    {
        return 'chatloop_ai_lock:'.$loop->organization_id.':'.$loop->id.':'.$user->id;
    }

    /**
     * TTL identique a celui que `ChatLoopAiService` utilisait deja : jamais
     * en dessous du timeout provider + 30 s, faute de quoi le verrou pourrait
     * expirer pendant que la generation tourne encore.
     */
    public static function ttl(): int
    {
        return max(
            (int) config('ai.chatloop.lock_ttl', 90),
            (int) config('ai.chatloop.timeout', 30) + 30,
        );
    }

    /**
     * Execute `$work` sous verrou, et le libere quoi qu'il arrive.
     *
     * Le `finally` n'est pas un detail : sans lui, une panne provider gelerait
     * le tour de ce membre jusqu'a l'expiration du TTL. Un tour ne doit jamais
     * devenir irrecuperable.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws RuntimeException si un autre tour est deja en cours
     */
    public static function run(Loop $loop, User $user, callable $work): mixed
    {
        $key = self::key($loop, $user);

        // Deja tenu par cette requete : le composeur l'a pris avant de publier
        // le message humain, le service le reprend ici. Un seul proprietaire,
        // une seule liberation — celle du plus englobant.
        if (isset(self::$heldInThisRequest[$key])) {
            return $work();
        }

        if (! Cache::add($key, true, self::ttl())) {
            throw new RuntimeException(__('loops.ai_generation_in_progress'));
        }

        self::$heldInThisRequest[$key] = true;

        try {
            return $work();
        } finally {
            unset(self::$heldInThisRequest[$key]);
            Cache::forget($key);
        }
    }

    /**
     * Uniquement pour les tests : repart d'une requete vierge.
     *
     * Le tableau de reentrance est statique et survit donc d'un test a l'autre
     * dans un meme processus PHPUnit. `run()` le nettoie dans son `finally`,
     * mais un test qui manipule la primitive a la main doit pouvoir le remettre
     * a zero sans dependre de cet invariant.
     */
    public static function forgetRequestState(): void
    {
        self::$heldInThisRequest = [];
    }
}
