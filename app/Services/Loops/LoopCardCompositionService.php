<?php

namespace App\Services\Loops;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopJournalEntry;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
use App\Services\Loops\LoopMarketplaceService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes loop_cards.enabled.
 *
 * Two screens use it — the platform admin and the Organization admin — and
 * neither touches LoopCard directly, so the rules below cannot be bypassed by a
 * forgotten caller.
 *
 * The three states the engine already distinguishes are preserved exactly:
 *
 *   no row              never added
 *   enabled = true      active
 *   enabled = false     deliberately switched off here
 *
 * That last one is what makes local composition survive: applyPreset() compares
 * card keys without filtering on `enabled`, so a card switched off is never
 * switched back on by a preset synchronisation. This service must never break
 * that by deleting the row instead of flagging it.
 */
class LoopCardCompositionService
{
    public function __construct(private LoopTypeRegistry $types) {}

    /**
     * Cards the workspace can actually render.
     *
     * ChatLoop is absent by construction: it is not a card, it is never
     * switchable, and a Loop without conversation does not exist.
     *
     * @return array<int, string>
     */
    public function manageableKeys(): array
    {
        return app(LoopCardRegistry::class)->manageableKeys();
    }

    /**
     * The composition of one Loop, ready to display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function compositionFor(Loop $loop): array
    {
        $registry = app(LoopCardRegistry::class);
        // Dans la portee de la Boucle : le socle qui fait baseline ici est
        // celui que son Organization a regle, pas celui de la Plateforme —
        // sans quoi le badge « socle du type » ment des le premier preset
        // surcharge. Meme famille de dette que TASK-1120/1121.
        $preset = $this->types->cardsFor($loop->type, $loop->organization);
        $rows = LoopCard::where('loop_id', $loop->id)->get()->keyBy('card_key');
        $catalogue = config('loop_cards.cards', []);

        $out = [];

        foreach ($this->manageableKeys() as $key) {
            $row = $rows->get($key);
            $inPreset = in_array($key, $preset, true);

            $out[] = [
                'key' => $key,
                'label' => __($catalogue[$key]['label_key']),
                'description' => __($catalogue[$key]['description_key']),
                'enabled' => (bool) ($row?->enabled),
                'exists' => $row !== null,
                // Where it comes from, so an administrator can tell a type's
                // baseline from a deliberate local addition.
                'origin' => match (true) {
                    $row === null => 'available',
                    $row->added_by_preset !== null => 'preset',
                    default => 'local',
                },
                'in_preset' => $inPreset,
                // Never disable the last thing that makes a workspace usable.
                // Declared by the catalogue, not hard-coded here: a second
                // required Card would otherwise have to be remembered twice.
                'protected' => $registry->isRequired($key),
                'data_count' => $this->dataCount($loop, $key),
            ];
        }

        return $out;
    }

    /** Turn a card on, creating its row if it never existed. */
    public function enable(Loop $loop, string $key): void
    {
        $this->assertManageable($key);

        DB::transaction(function () use ($loop, $key) {
            $row = LoopCard::where('loop_id', $loop->id)->where('card_key', $key)->lockForUpdate()->first();

            if ($row) {
                // Only flip the flag: recreating the row would lose
                // added_by_preset and, with it, the card's origin.
                $row->forceFill(['enabled' => true])->save();

                return;
            }

            LoopCard::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'card_key' => $key,
                'enabled' => true,
                // Added here, not by a preset. Saying otherwise would make a
                // local decision look like the type's doing.
                'added_by_preset' => null,
            ]);
        });
    }

    /**
     * Turn a card off. Nothing is deleted, ever.
     *
     * The card leaves the workspace — TASK-1081 made the workspace read the
     * effective composition — and `requires_card` keeps refusing direct access.
     * Its content waits, untouched, for the card to come back.
     */
    public function disable(Loop $loop, string $key): void
    {
        $this->assertManageable($key);

        if (app(LoopCardRegistry::class)->isRequired($key)) {
            throw new \RuntimeException('This card is required and cannot be switched off.');
        }

        DB::transaction(function () use ($loop, $key) {
            $row = LoopCard::where('loop_id', $loop->id)->where('card_key', $key)->lockForUpdate()->first();

            // A card that was never added is already off. Creating a disabled
            // row would only add noise.
            $row?->forceFill(['enabled' => false])->save();
        });
    }

    // ── Outils principaux (TASK-1124) ───────────────────────────────────────

    /** Combien d'outils une Boucle peut mettre en avant. */
    public const MAX_PRIMARY = 3;

    /**
     * Les outils **mis en avant** de cette Boucle, dans l'ordre.
     *
     * **La regle se lit au niveau de la Boucle, jamais ligne a ligne.** Une
     * Boucle sans aucun rang explicite est en mode *derive* : ses principaux
     * sont ses premieres Cards actives dans l'ordre du catalogue — le
     * comportement d'avant TASK-1124, rendu explicite. Des qu'un seul rang est
     * pose, elle bascule en mode *explicite* et `NULL` y signifie
     * « secondaire ».
     *
     * Lire `NULL = secondaire` sans ce niveau de lecture aurait fait perdre
     * leurs trois outils principaux a toutes les Boucles historiques.
     *
     * @return array<int, string> cles de Cards, principales d'abord
     */
    public function primaryKeysFor(Loop $loop): array
    {
        $actives = app(LoopCardRegistry::class)->activeGridKeysFor($loop);

        $rangs = LoopCard::where('loop_id', $loop->id)
            ->whereNotNull('primary_rank')
            ->orderBy('primary_rank')
            ->pluck('card_key')
            ->all();

        // Mode explicite : les rangs font foi. Filtres sur les actives —
        // un outil desactive n'est plus mis en avant, sans qu'on ait eu a
        // nettoyer son rang.
        if ($rangs !== []) {
            return array_values(array_slice(
                array_values(array_filter($rangs, fn ($k) => in_array($k, $actives, true))),
                0,
                self::MAX_PRIMARY,
            ));
        }

        // Mode derive : l'historique, dit a voix haute.
        return array_slice($actives, 0, self::MAX_PRIMARY);
    }

    /**
     * Les autres outils actifs — accessibles, jamais masques.
     *
     * @return array<int, string>
     */
    public function secondaryKeysFor(Loop $loop): array
    {
        $actives = app(LoopCardRegistry::class)->activeGridKeysFor($loop);

        return array_values(array_diff($actives, $this->primaryKeysFor($loop)));
    }

    /**
     * Mettre un outil actif en avant.
     *
     * Ne touche **jamais** `enabled` : promouvoir n'active pas, retrograder ne
     * desactive pas. Si trois outils sont deja en avant, le refus est
     * explicite — jamais un remplacement silencieux.
     *
     * @throws \RuntimeException
     */
    public function promote(Loop $loop, string $key): void
    {
        $this->assertManageable($key);

        DB::transaction(function () use ($loop, $key) {
            $actuels = $this->primaryKeysFor($loop);

            if (in_array($key, $actuels, true)) {
                return;
            }

            if (! in_array($key, app(LoopCardRegistry::class)->activeGridKeysFor($loop), true)) {
                throw new \RuntimeException(__('loops.tools_error_not_active'));
            }

            if (count($actuels) >= self::MAX_PRIMARY) {
                throw new \RuntimeException(__('loops.tools_error_primary_full', ['max' => self::MAX_PRIMARY]));
            }

            // La bascule derive -> explicite : on materialise l'etat courant
            // avant d'y ajouter le nouveau, sinon poser un seul rang ferait
            // disparaitre les principaux derives des Boucles historiques.
            $this->writeRanks($loop, [...$actuels, $key]);
        });
    }

    /** Retirer un outil des principaux. Il reste **actif** et accessible. */
    public function demote(Loop $loop, string $key): void
    {
        $this->assertManageable($key);

        DB::transaction(function () use ($loop, $key) {
            $actuels = $this->primaryKeysFor($loop);

            if (! in_array($key, $actuels, true)) {
                return;
            }

            $restants = array_values(array_diff($actuels, [$key]));

            // **Au moins un outil en avant tant qu'il en reste un d'actif.**
            // Sans cette borne, retirer le dernier principal viderait la
            // colonne, la Boucle retomberait en mode derive et retrouverait
            // aussitot celui qu'on vient de retirer — un geste sans effet, ce
            // qui est pire qu'un refus. Le seul autre moyen de distinguer
            // « aucun choix » de « choix : aucun » serait une valeur
            // sentinelle, c'est-a-dire un second etat metier.
            if ($restants === []) {
                throw new \RuntimeException(__('loops.tools_error_last_primary'));
            }

            // Materialiser avant de retirer : poser les rangs des restants
            // fait basculer la Boucle en mode explicite.
            $this->writeRanks($loop, $restants);
        });
    }

    /**
     * Ecrire l'ordre des principaux, et lui seul.
     *
     * Tout ce qui n'est pas dans la liste repasse a `NULL` — secondaire, pas
     * desactive. `enabled` n'est pas dans le `forceFill` : c'est la garantie
     * mecanique que ce chemin ne peut pas eteindre un outil.
     *
     * @param  array<int, string>  $keys
     */
    private function writeRanks(Loop $loop, array $keys): void
    {
        $keys = array_values(array_slice(array_unique($keys), 0, self::MAX_PRIMARY));

        // Jamais appele avec une liste vide : promote() ajoute, demote()
        // refuse de retirer le dernier. Une colonne entierement `NULL` veut
        // dire « cette Boucle n'a jamais choisi », et rien d'autre.
        LoopCard::where('loop_id', $loop->id)->update(['primary_rank' => null]);

        foreach ($keys as $rang => $cle) {
            LoopCard::where('loop_id', $loop->id)->where('card_key', $cle)->update(['primary_rank' => $rang]);
        }
    }

    /**
     * How much a card already holds, so switching it off is never a surprise.
     *
     * Bounded on purpose: a per-card lookup, not a generic introspection
     * engine. A card the catalogue gains later simply reports null until
     * someone adds its case here.
     */
    /** Combien d'elements le Dossier racine de cette Boucle contient. */
    private function dossierItemCount(Loop $loop): int
    {
        $dossier = Dossier::where('loop_id', $loop->id)->first();

        if (! $dossier) {
            return 0;
        }

        return $dossier->articles()->count() + $dossier->files()->count();
    }

    private function dataCount(Loop $loop, string $key): ?int
    {
        return match ($key) {
            'core.members' => LoopMember::where('loop_id', $loop->id)->where('status', 'active')->count(),
            'core.roadmap' => LoopRoadmapItem::where('loop_id', $loop->id)->count(),
            'core.ai_summary' => $loop->messages()->where('type', 'ai')->count(),
            'core.manifesto' => $this->rootDocumentExists($loop) ? 1 : 0,
            'core.polls' => LoopPoll::where('loop_id', $loop->id)->count(),
            'core.events' => LoopEvent::where('loop_id', $loop->id)->count(),
            // Articles + fichiers du Dossier racine, et rien d'autre.
            //
            // Les Series ne s'ajoutent pas : DossierSeriesController::addAnnex()
            // exige qu'une annexe soit deja un Article du Dossier avant de creer
            // son ArticleSeriesItem. Les compter reviendrait a compter deux fois
            // les memes elements.
            'core.dossiers' => $this->dossierItemCount($loop),
            // Sans ce compteur, on eteint la Card ou l'on change de preset sans
            // etre prevenu qu'elle porte quarante Decisions, la ou Roadmap,
            // Sondages et Evenements previennent. Le Journal a la meme omission
            // depuis TASK-1104 : elle est traitee ici aussi, le compteur etant
            // le meme geste.
            'core.decisions' => LoopDecision::where('loop_id', $loop->id)->count(),
            'core.journal' => LoopJournalEntry::where('loop_id', $loop->id)->count(),
            // **Le meme compte que la Card**, filtre compris : sinon la
            // composition annonçait « 2 elements » pour une Card qui en montre
            // un, les liens orphelins etant invisibles et donc irretirables.
            'core.marketplace' => app(LoopMarketplaceService::class)->countFor($loop),
            default => null,
        };
    }

    private function rootDocumentExists(Loop $loop): bool
    {
        $id = Dossier::where('loop_id', $loop->id)->value('root_blog_post_id') ?? $loop->manifesto_blog_post_id;

        return $id !== null && BlogPost::whereKey($id)->exists();
    }

    private function assertManageable(string $key): void
    {
        if (! in_array($key, $this->manageableKeys(), true)) {
            throw new \RuntimeException('Unknown or unmanageable card key.');
        }
    }
}
