<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopMessage;
use App\Models\LoopRoadmapItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les Decisions d'une Boucle.
 *
 * Cinq regles, chacune venue d'un defaut paye ailleurs dans cette serie :
 *
 * 1. **Aucune copie.** Promouvoir un message pose une **reference** ; le
 *    message corrige se corrige partout.
 * 2. **Le cout ne croit pas** avec le nombre de Decisions : la lecture est
 *    bornee et les relations chargees en une fois.
 * 3. **Une permission ne suffit pas — la transition doit etre valide.** On ne
 *    remplace pas une Decision d'une autre Boucle, ni une Decision par
 *    elle-meme, ni une Decision deja remplacee.
 * 4. **L'ecriture est une ecriture** : elle a sa propre permission, et une
 *    Boucle archivee la refuse.
 * 5. **Rien ne disparait.** Remplacer conserve ; seul un retrait explicite
 *    efface, et il n'emporte jamais les actions engagees.
 */
class LoopDecisionService
{
    /** Ce qu'une Card affiche sans devenir une liste. */
    private const PAGE = 30;

    /**
     * Consigner une Decision.
     *
     * La date de la decision est **distincte** de celle de l'ecriture : on
     * consigne souvent apres coup, et une Card qui ne sait dire que « quand ca
     * a ete tape » ne raconte pas l'histoire d'un projet.
     */
    public function record(
        Loop $loop,
        User $author,
        string $title,
        ?string $rationale = null,
        ?string $decidedOn = null,
    ): LoopDecision {
        return LoopDecision::create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'author_id' => $author->id,
            'title' => $this->cleanTitle($title),
            'rationale' => $this->cleanRationale($rationale),
            'decided_on' => $this->parseDate($decidedOn) ?? now()->toDateString(),
        ]);
    }

    /**
     * Promouvoir un message du ChatLoop en Decision.
     *
     * C'est le geste que le North Star decrit : « une Interaction peut devenir
     * […] une decision ». Le titre reste **saisi a la main** : le corps d'un
     * message n'est pas un titre, et le laisser en tenir lieu aurait donne une
     * liste de Decisions illisible.
     */
    public function promote(
        Loop $loop,
        User $author,
        LoopMessage $message,
        string $title,
        ?string $rationale = null,
        ?string $decidedOn = null,
    ): LoopDecision {
        // Un message d'une autre Boucle n'a rien a faire ici, meme si
        // l'identifiant est reel.
        if ($message->loop_id !== $loop->id) {
            throw ValidationException::withMessages([
                'loop_message_id' => __('loops.cards.decisions.message_not_in_loop'),
            ]);
        }

        $title = $this->cleanTitle($title);
        $rationale = $this->cleanRationale($rationale);
        $date = $this->parseDate($decidedOn)
            // Par defaut, la date du message : c'est **quand ca s'est decide**,
            // pas quand on l'a remarque.
            ?? $message->created_at?->toDateString()
            ?? now()->toDateString();

        return DB::transaction(function () use ($loop, $author, $message, $title, $rationale, $date) {
            $deja = LoopDecision::where('loop_id', $loop->id)
                ->where('loop_message_id', $message->id)
                ->first();

            // Deux promotions du meme message feraient deux Decisions pour un
            // seul choix. L'unicite est tenue par la base — cette lecture n'est
            // qu'un raccourci qui evite une exception previsible.
            if ($deja) {
                return $deja;
            }

            return LoopDecision::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'author_id' => $author->id,
                'title' => $title,
                'rationale' => $rationale,
                'decided_on' => $date,
                'loop_message_id' => $message->id,
            ]);
        });
    }

    /** Corriger une Decision. */
    public function update(
        LoopDecision $decision,
        string $title,
        ?string $rationale = null,
        ?string $decidedOn = null,
    ): LoopDecision {
        // Une Decision remplacee est de l'histoire. La reecrire changerait ce
        // que le collectif lit de son passe, sans que personne ne l'ait
        // decide.
        if ($decision->isSuperseded()) {
            throw ValidationException::withMessages([
                'title' => __('loops.cards.decisions.superseded_is_read_only'),
            ]);
        }

        $decision->update([
            'title' => $this->cleanTitle($title),
            'rationale' => $this->cleanRationale($rationale),
            'decided_on' => $this->parseDate($decidedOn) ?? $decision->decided_on,
        ]);

        return $decision->fresh();
    }

    /**
     * Remplacer une Decision par une autre.
     *
     * **La remplacee reste lisible.** C'est le point : une Card Decisions qui
     * effacerait ce qui a ete decide avant priverait le collectif de son
     * histoire, qui est precisement ce qu'elle conserve.
     *
     * Une permission ne suffit pas — trois transitions sont refusees, chacune
     * pour une raison qui se lit :
     */
    public function supersede(LoopDecision $ancienne, LoopDecision $nouvelle): LoopDecision
    {
        // 1. Une Decision ne se remplace pas elle-meme : cela la sortirait du
        //    present sans que rien ne prenne le relais.
        if ($ancienne->id === $nouvelle->id) {
            throw ValidationException::withMessages([
                'superseded_by_id' => __('loops.cards.decisions.cannot_supersede_itself'),
            ]);
        }

        // 2. Ni par une Decision d'une autre Boucle : l'histoire d'un projet ne
        //    se reecrit pas depuis un autre projet, et le cloisonnement en
        //    depend.
        if ($ancienne->loop_id !== $nouvelle->loop_id) {
            throw ValidationException::withMessages([
                'superseded_by_id' => __('loops.cards.decisions.not_in_same_loop'),
            ]);
        }

        // 3. Ni deux fois : une Decision deja remplacee est passee. La
        //    rebrancher ailleurs referait l'histoire a rebours.
        if ($ancienne->isSuperseded()) {
            throw ValidationException::withMessages([
                'superseded_by_id' => __('loops.cards.decisions.already_superseded'),
            ]);
        }

        // 4. Ni par une Decision qu'elle a elle-meme remplacee : cela fermerait
        //    une boucle ou chacune renvoie a l'autre, et plus rien ne ferait
        //    foi.
        if ($nouvelle->superseded_by_id === $ancienne->id) {
            throw ValidationException::withMessages([
                'superseded_by_id' => __('loops.cards.decisions.would_make_a_cycle'),
            ]);
        }

        $ancienne->update(['superseded_by_id' => $nouvelle->id]);

        return $ancienne->fresh();
    }

    /**
     * Lancer une action depuis une Decision.
     *
     * « Une decision n'est pas transformee en action » est nommement la perte
     * que cette Card existe pour eviter. L'action est un item de Roadmap
     * ordinaire — **pas un second systeme de taches** — qui garde seulement le
     * lien vers ce qui l'a decidee.
     */
    public function startAction(LoopDecision $decision, User $author, string $title): LoopRoadmapItem
    {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages([
                'action_title' => __('loops.cards.decisions.action_title_required'),
            ]);
        }

        // Une Decision remplacee ne lance plus rien : ce qui la remplace fait
        // foi, et engager une action au nom d'un choix abandonne serait le
        // contraire du service rendu.
        if ($decision->isSuperseded()) {
            throw ValidationException::withMessages([
                'action_title' => __('loops.cards.decisions.superseded_starts_nothing'),
            ]);
        }

        $position = LoopRoadmapItem::query()
            ->where('organization_id', $decision->organization_id)
            ->where('loop_id', $decision->loop_id)
            ->where('status', LoopRoadmapItem::STATUS_TODO)
            ->max('position');

        return LoopRoadmapItem::create([
            'organization_id' => $decision->organization_id,
            'loop_id' => $decision->loop_id,
            'loop_decision_id' => $decision->id,
            'title' => mb_substr($title, 0, 255),
            'status' => LoopRoadmapItem::STATUS_TODO,
            'position' => $position === null ? 0 : $position + 1,
            'created_by' => $author->id,
        ]);
    }

    /**
     * Retirer une Decision.
     *
     * **Les actions engagees ne sont jamais emportees** : quelqu'un les a
     * peut-etre deja faites. Le lien tombe a `null`, l'action reste. Et un
     * message promu n'est pas touche — retirer une Decision, c'est cesser de la
     * consigner, pas effacer ce qui a ete dit.
     */
    public function delete(LoopDecision $decision): void
    {
        $decision->delete();
    }

    /**
     * Les Decisions, de la plus recente a la plus ancienne.
     *
     * Les relations sont chargees **avec** : sans cela, chaque Decision promue
     * payait une requete pour son message, une pour son auteur et une pour ce
     * qui la remplace — le cout suivait alors le nombre de Decisions.
     *
     * @return Collection<int, LoopDecision>
     */
    public function decisionsFor(Loop $loop, int $limit = self::PAGE): Collection
    {
        return LoopDecision::where('loop_id', $loop->id)
            ->with([
                'author:id,first_name,name,email,organization_id,banned_at',
                // `deleted_at` fait partie de la selection : sans lui,
                // `isDeleted()` ne verrait rien et la Card afficherait en clair
                // un message que le ChatLoop a retire.
                'message:id,body,created_at,deleted_at',
                'supersededBy:id,title,decided_on',
                'actions:id,loop_decision_id,title,status',
            ])
            ->orderByDesc('decided_on')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /** Un message deja consigne ne se propose plus a la promotion. */
    public function isPromoted(Loop $loop, LoopMessage $message): bool
    {
        return LoopDecision::where('loop_id', $loop->id)
            ->where('loop_message_id', $message->id)
            ->exists();
    }

    private function cleanTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => __('loops.cards.decisions.title_required'),
            ]);
        }

        // La colonne fait 255 : tronquer ici plutot que laisser la base
        // trancher, ce qui rend 500 sous PostgreSQL et coupe en silence
        // ailleurs.
        return mb_substr($title, 0, 255);
    }

    private function cleanRationale(?string $rationale): ?string
    {
        $rationale = trim((string) $rationale);

        return $rationale === '' ? null : $rationale;
    }

    /**
     * La date saisie, ou `null` si rien n'a ete saisi.
     *
     * **`null` et non `now()`** : c'est a l'appelant de choisir son defaut. En
     * rendant `now()` ici, le repli de `promote()` sur la date du message ne
     * s'appliquerait jamais — exactement le defaut trouve dans le Journal.
     */
    private function parseDate(?string $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        try {
            // **Strict**, et non `Carbon::parse` : celui-ci accepte
            // « 2026-02-30 » et rend le 2 mars, ou « tomorrow ». La date saisie
            // serait donc silencieusement *changee*.
            $lue = Carbon::createFromFormat('!Y-m-d', $date);

            if (! $lue || $lue->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('date impossible');
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'decided_on' => __('loops.cards.decisions.date_invalid'),
            ]);
        }

        // Carbon accepte « 0000-00-00 » et rend une date negative. La borne est
        // large : elle refuse l'absurde, pas le passe.
        if ($lue->year < 1900 || $lue->year > 2200) {
            throw ValidationException::withMessages([
                'decided_on' => __('loops.cards.decisions.date_invalid'),
            ]);
        }

        return $lue->toDateString();
    }
}
