<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopJournalEntry;
use App\Models\LoopMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le Journal d'une Boucle : ce qui s'est passe, date et signe.
 *
 * Quatre regles, chacune venue d'un defaut paye ailleurs dans cette serie :
 *
 * 1. **Aucune copie.** Promouvoir un message pose une **reference** ; le
 *    message corrige se corrige partout, et il n'y a rien a garder d'accord.
 * 2. **Le cout ne croit pas** avec le nombre d'entrees : la lecture est bornee
 *    et les relations chargees en une fois.
 * 3. **Une transition doit etre valide** : on ne promeut que ce qui existe et
 *    appartient a cette Boucle.
 * 4. **L'ecriture est une ecriture** : elle a sa propre permission, et une
 *    Boucle archivee la refuse.
 */
class LoopJournalService
{
    /** Ce qu'une Card affiche sans devenir une liste. */
    private const PAGE = 30;

    /**
     * Ecrire une entree.
     *
     * La date de ce qui s'est passe est **distincte** de celle de l'ecriture :
     * on note souvent apres coup. Sans elle, un Journal ne saurait dire que
     * « quand ca a ete tape », ce qui ne raconte rien.
     */
    public function write(Loop $loop, User $author, string $body, ?string $occurredOn = null): LoopJournalEntry
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => __('loops.cards.journal.body_required'),
            ]);
        }

        return LoopJournalEntry::create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'author_id' => $author->id,
            'body' => $body,
            'occurred_on' => $this->parseDate($occurredOn) ?? now()->toDateString(),
        ]);
    }

    /**
     * Promouvoir un message du ChatLoop en entree de Journal.
     *
     * C'est le geste que le North Star decrit : « une Interaction peut devenir
     * une entree de Journal **apres validation humaine** ». La validation est
     * ce geste meme — rien ne promeut automatiquement, et l'IA encore moins.
     */
    public function promote(Loop $loop, User $author, LoopMessage $message, ?string $occurredOn = null): LoopJournalEntry
    {
        // Un message d'une autre Boucle n'a rien a faire dans ce Journal, meme
        // si l'identifiant est reel.
        if ($message->loop_id !== $loop->id) {
            throw ValidationException::withMessages([
                'loop_message_id' => __('loops.cards.journal.message_not_in_loop'),
            ]);
        }

        return DB::transaction(function () use ($loop, $author, $message, $occurredOn) {
            $deja = LoopJournalEntry::where('loop_id', $loop->id)
                ->where('loop_message_id', $message->id)
                ->lockForUpdate()
                ->first();

            // Deux promotions du meme message feraient deux entrees identiques,
            // et le Journal se lirait deux fois la meme chose.
            if ($deja) {
                return $deja;
            }

            return LoopJournalEntry::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'author_id' => $author->id,
                'loop_message_id' => $message->id,
                // Par defaut, la date du message : c'est **quand ca s'est
                // passe**, pas quand on l'a remarque.
                'occurred_on' => $this->parseDate($occurredOn)
                    ?? $message->created_at?->toDateString()
                    ?? now()->toDateString(),
            ]);
        });
    }

    /** Corriger le texte d'une entree ecrite. */
    public function update(LoopJournalEntry $entry, string $body, ?string $occurredOn = null): LoopJournalEntry
    {
        // Une entree promue n'a pas de texte a corriger : le sien est celui du
        // message. Le laisser modifier ferait diverger les deux.
        if ($entry->sourceType() === LoopJournalEntry::SOURCE_MESSAGE) {
            throw ValidationException::withMessages([
                'body' => __('loops.cards.journal.promoted_is_read_only'),
            ]);
        }

        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => __('loops.cards.journal.body_required'),
            ]);
        }

        $entry->update([
            'body' => $body,
            'occurred_on' => $this->parseDate($occurredOn) ?? $entry->occurred_on,
        ]);

        return $entry->fresh();
    }

    /**
     * Retirer une entree.
     *
     * **Le message reference n'est jamais touche** : retirer du Journal, c'est
     * cesser de garder, pas effacer ce qui a ete dit.
     */
    public function delete(LoopJournalEntry $entry): void
    {
        $entry->delete();
    }

    /**
     * Le Journal, du plus recent au plus ancien.
     *
     * Les relations sont chargees **avec** : sans cela, chaque entree promue
     * payait une requete pour son message et une pour son auteur — le cout
     * suivait alors le nombre d'entrees.
     *
     * @return Collection<int, LoopJournalEntry>
     */
    public function entriesFor(Loop $loop, int $limit = self::PAGE): Collection
    {
        return LoopJournalEntry::where('loop_id', $loop->id)
            ->with(['author:id,first_name,name,email,organization_id,banned_at', // `deleted_at` fait partie de la selection : sans lui, `isDeleted()`
                // ne voyait rien et le Journal affichait en clair un message
                // que le ChatLoop avait retire.
                'message:id,body,created_at,deleted_at'])
            ->orderByDesc('occurred_on')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /** Un message deja garde ne se propose plus a la promotion. */
    public function isPromoted(Loop $loop, LoopMessage $message): bool
    {
        return LoopJournalEntry::where('loop_id', $loop->id)
            ->where('loop_message_id', $message->id)
            ->exists();
    }

    /**
     * La date saisie, ou `null` si rien n'a ete saisi.
     *
     * **`null` et non `now()`** : c'est a l'appelant de choisir son defaut. En
     * rendant `now()` ici, le repli de `promote()` sur la date du message ne
     * s'appliquait jamais — une entree promue portait la date du jour au lieu
     * de celle de ce qui s'est passe.
     */
    private function parseDate(?string $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        try {
            // **Strict**, et non `Carbon::parse` : celui-ci accepte
            // « 2026-02-30 » et rend le 2 mars, ou « tomorrow », ou
            // « +10 years ». La date saisie etait donc silencieusement
            // *changee* — exactement ce que le commentaire ci-dessus interdit.
            $lue = Carbon::createFromFormat('!Y-m-d', $date);

            if (! $lue || $lue->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('date impossible');
            }
        } catch (\Throwable) {
            // Avaler l'exception ferait disparaitre la date saisie sans un mot.
            throw ValidationException::withMessages([
                'occurred_on' => __('loops.cards.journal.date_invalid'),
            ]);
        }

        // Carbon accepte « 0000-00-00 » et rend une date negative. La borne est
        // large : elle refuse l'absurde, pas le passe.
        if ($lue->year < 1900 || $lue->year > 2200) {
            throw ValidationException::withMessages([
                'occurred_on' => __('loops.cards.journal.date_invalid'),
            ]);
        }

        return $lue->toDateString();
    }
}
