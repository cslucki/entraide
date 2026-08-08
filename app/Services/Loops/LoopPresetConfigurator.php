<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Composer une Boucle : quel preset, et quelles Cards dans ses trois
 * emplacements.
 *
 * Ce service **etend** ce que TASK-1083 et TASK-1086 ont livre, il ne le
 * remplace pas : toute ecriture passe encore par LoopCardCompositionService,
 * seul endroit qui touche `loop_cards.enabled`. Ce qui est nouveau ici, c'est ce
 * qu'on peut dire *avant* d'ecrire — l'apercu d'un changement de preset, les
 * dependances, et qui a le droit de faire quoi.
 *
 * Trois regles qui ne se negocient pas :
 *
 *   - **rien n'est supprime**, jamais : une Card qu'on retire est eteinte, ses
 *     donnees attendent ;
 *   - **aucune condition sur `$loop->type`** : les dependances sont declarees
 *     dans le catalogue et valent partout ;
 *   - **une requete forgee ne passe pas** : les blocages sont verifies ici, pas
 *     dans la vue.
 */
class LoopPresetConfigurator
{
    public function __construct(
        private LoopCardRegistry $registry,
        private LoopTypeRegistry $types,
        private LoopCardCompositionService $composition,
        private LoopPermissionResolver $permissions,
    ) {}

    // ── Qui a le droit ──────────────────────────────────────────────────────

    /**
     * Composer les Cards de cette Boucle.
     *
     * SuperAdmin et Admin d'Organization : toujours, par `loops.manage_cards`,
     * que le resolveur leur accorde a l'etape 3 ou 4. Proprietaire : seulement
     * si son Organization l'y autorise — et c'est une decision de
     * l'Organization, pas de la Boucle.
     */
    public function canConfigure(User $user, Loop $loop): bool
    {
        if ($this->permissions->can($user, $loop, 'loops.manage_cards')) {
            return true;
        }

        $organization = $loop->organization;

        if (! $organization instanceof Organization || ! $organization->allowsOwnerComposition()) {
            return false;
        }

        // Le proprietaire, et lui seul : un animateur anime, il ne recompose pas.
        return $this->permissions->can($user, $loop, 'loops.archive')
            && $user->organization_id === $loop->organization_id;
    }

    // ── Ce qu'on peut montrer avant d'ecrire ────────────────────────────────

    /**
     * La composition telle qu'un configurateur doit la presenter.
     *
     * @return array{
     *     preset: string,
     *     preset_label: string,
     *     slots: int,
     *     frame: array<int, array<string, mixed>>,
     *     grid: array<int, array<string, mixed>>,
     *     chat_actions: array<int, array<string, mixed>>,
     *     available: array<int, array<string, mixed>>,
     * }
     */
    public function describe(Loop $loop): array
    {
        $active = $this->types->activeCardsFor($loop);
        $rows = LoopCard::where('loop_id', $loop->id)->get()->keyBy('card_key');
        $preset = $this->types->cardsFor($loop->type);

        $entry = function (string $key) use ($loop, $active, $rows, $preset): array {
            $row = $rows->get($key);

            return [
                'key' => $key,
                'label' => $this->registry->label($key),
                'description' => $this->registry->description($key),
                'placement' => $this->registry->placementOf($key),
                'category' => $this->registry->categoryOf($key),
                'scope' => $this->registry->scopeOf($key),
                'enabled' => in_array($key, $active, true),
                'required' => $this->registry->isRequired($key),
                'replaceable' => $this->registry->isReplaceable($key),
                'in_preset' => in_array($key, $preset, true),
                'origin' => match (true) {
                    $row === null => 'available',
                    $row->added_by_preset !== null => 'preset',
                    default => 'local',
                },
                'requires' => $this->registry->requirementsOf($key),
                'incompatible_with' => $this->registry->incompatibilitiesOf($key),
                'blockers' => $this->registry->blockersFor($key, $active),
                'data_count' => $this->dataCountFor($loop, $key),
            ];
        };

        $byPlacement = fn (string $placement) => collect($this->registry->manageableKeys())
            ->filter(fn ($key) => $this->registry->placementOf($key) === $placement)
            ->map($entry)
            ->values()
            ->all();

        $grid = collect($byPlacement(LoopCardRegistry::PLACEMENT_GRID));

        return [
            'preset' => $this->types->resolve($loop->type),
            'preset_label' => $this->types->label($loop->type),
            'slots' => $this->registry->gridSlots(),
            'frame' => $byPlacement(LoopCardRegistry::PLACEMENT_FRAME),
            // Ce qui occupe les emplacements, et ce qui pourrait les occuper.
            'grid' => $grid->where('enabled', true)->values()->all(),
            'available' => $grid->where('enabled', false)->values()->all(),
            'chat_actions' => $byPlacement(LoopCardRegistry::PLACEMENT_CHAT_ACTION),
        ];
    }

    /**
     * Ce qu'un changement de preset ferait, sans le faire.
     *
     * C'est ce que l'ecran montre avant de demander confirmation. Le calcul est
     * fait ici et nulle part ailleurs, pour que l'apercu et l'application ne
     * puissent pas raconter deux histoires.
     *
     * @return array{
     *     from: string, to: string, to_label: string,
     *     added: array<int, string>, kept: array<int, string>,
     *     deactivated: array<int, string>, preserved_data: array<string, int>,
     * }
     */
    public function previewPresetChange(Loop $loop, string $targetType): array
    {
        $target = $this->types->resolve($targetType);
        $wanted = $this->types->cardsFor($target);
        $active = $this->types->activeCardsFor($loop);

        // Ce que le nouveau socle apporte et que la Boucle n'a pas.
        $added = array_values(array_diff($wanted, $active));

        // Ce qu'elle a et que le nouveau socle ne prevoit pas. Rien n'est
        // supprime : ces Cards seraient *eteintes*, et seulement si on le
        // demande explicitement.
        $deactivated = array_values(array_filter(
            array_diff($active, $wanted),
            fn ($key) => ! $this->registry->isRequired($key),
        ));

        $preserved = [];
        foreach ($deactivated as $key) {
            $count = $this->dataCountFor($loop, $key);
            if ($count !== null && $count > 0) {
                $preserved[$key] = $count;
            }
        }

        return [
            'from' => $this->types->resolve($loop->type),
            'to' => $target,
            'to_label' => $this->types->label($target),
            'added' => $added,
            'kept' => array_values(array_intersect($active, $wanted)),
            'deactivated' => $deactivated,
            'preserved_data' => $preserved,
        ];
    }

    // ── Ecritures ───────────────────────────────────────────────────────────

    /**
     * Activer une Card, si rien ne s'y oppose.
     *
     * @throws PresetException
     */
    public function enable(User $user, Loop $loop, string $key): void
    {
        $this->assertConfigurable($user, $loop);
        $this->assertManageable($key);

        $active = $this->types->activeCardsFor($loop);
        $blockers = $this->registry->blockersFor($key, $active);

        // Le refus est explique, pas subi : on nomme ce qui manque.
        if ($blockers['missing'] !== []) {
            throw new PresetException(__('loops.preset_error_requires', [
                'cards' => $this->labels($blockers['missing']),
            ]));
        }

        if ($blockers['conflicting'] !== []) {
            throw new PresetException(__('loops.preset_error_conflicts', [
                'cards' => $this->labels($blockers['conflicting']),
            ]));
        }

        // Le plafond ne vaut que pour la grille : le cadre permanent n'est pas
        // un emplacement qu'on se dispute.
        if ($this->registry->placementOf($key) === LoopCardRegistry::PLACEMENT_GRID) {
            $used = collect($active)
                ->filter(fn ($k) => $this->registry->placementOf($k) === LoopCardRegistry::PLACEMENT_GRID)
                ->count();

            if ($used >= $this->registry->gridSlots()) {
                throw new PresetException(__('loops.preset_error_slots_full', [
                    'slots' => $this->registry->gridSlots(),
                ]));
            }
        }

        $this->composition->enable($loop, $key);
    }

    /**
     * Eteindre une Card. Rien n'est supprime.
     *
     * @throws PresetException
     */
    public function disable(User $user, Loop $loop, string $key): void
    {
        $this->assertConfigurable($user, $loop);
        $this->assertManageable($key);

        // Eteindre une Card dont une autre depend la laisserait sans objet :
        // on refuse plutot que de casser en silence.
        $active = $this->types->activeCardsFor($loop);

        $dependents = array_values(array_filter(
            $active,
            fn ($k) => $k !== $key && in_array($key, $this->registry->requirementsOf($k), true),
        ));

        if ($dependents !== []) {
            throw new PresetException(__('loops.preset_error_required_by', [
                'cards' => $this->labels($dependents),
            ]));
        }

        try {
            $this->composition->disable($loop, $key);
        } catch (\RuntimeException $e) {
            throw new PresetException($e->getMessage());
        }
    }

    /**
     * Echanger une Card contre une autre, dans le meme emplacement.
     *
     * Une seule transaction : sans cela, un echec au milieu laisserait la Boucle
     * avec un emplacement vide et l'ancienne Card perdue de vue.
     *
     * @throws PresetException
     */
    public function replace(User $user, Loop $loop, string $outgoing, string $incoming): void
    {
        $this->assertConfigurable($user, $loop);
        $this->assertManageable($outgoing);
        $this->assertManageable($incoming);

        if (! $this->registry->isReplaceable($outgoing)) {
            throw new PresetException(__('loops.preset_error_not_replaceable'));
        }

        DB::transaction(function () use ($user, $loop, $outgoing, $incoming) {
            $this->disable($user, $loop, $outgoing);
            $this->enable($user, $loop->fresh(), $incoming);
        });
    }

    /**
     * Appliquer un preset a cette Boucle.
     *
     * `$deactivateAbsent` n'est vrai que si l'ecran l'a demande explicitement,
     * apres avoir montre l'apercu. Par defaut, changer de preset **ajoute** et ne
     * retire rien — c'est la regle additive de TASK-1086, qui n'a jamais failli.
     *
     * @return array{added: array<int, string>, deactivated: array<int, string>}
     *
     * @throws PresetException
     */
    public function applyPreset(User $user, Loop $loop, string $targetType, bool $deactivateAbsent = false): array
    {
        $this->assertConfigurable($user, $loop);

        if (! $this->types->exists($targetType)) {
            throw new PresetException(__('loops.preset_error_unknown_type'));
        }

        // **Un type retire des choix ne s'assigne pas**, quel que soit
        // l'appelant. La regle vivait dans un seul controleur ; les deux autres
        // chemins ne l'avaient pas, et un POST forge suffisait a poser un type
        // que le produit n'offre pas.
        //
        // Garder celui que la Boucle porte deja reste permis : ce n'est pas une
        // assignation, et l'interdire empecherait d'enregistrer quoi que ce
        // soit d'autre sur le formulaire.
        if (! $this->types->isAssignableTo($targetType, $loop->type)) {
            throw new PresetException(__('loops.type_unavailable'));
        }

        $preview = $this->previewPresetChange($loop, $targetType);

        return DB::transaction(function () use ($loop, $targetType, $preview, $deactivateAbsent) {
            $fresh = Loop::whereKey($loop->id)->lockForUpdate()->firstOrFail();

            $fresh->update(['type' => $this->types->resolve($targetType)]);

            // Additif : applyPreset() ajoute ce qui manque et ne rallume jamais
            // une Card eteinte a la main.
            $added = $this->types->applyPreset($fresh);

            $deactivated = [];

            if ($deactivateAbsent) {
                foreach ($preview['deactivated'] as $key) {
                    // Eteindre, jamais supprimer : les donnees attendent le
                    // retour de la Card.
                    $this->composition->disable($fresh, $key);
                    $deactivated[] = $key;
                }
            }

            return ['added' => $added, 'deactivated' => $deactivated];
        });
    }

    /**
     * Revenir au socle du type : rallumer ce que le preset prevoit.
     *
     * Ne retire rien non plus. « Restaurer » veut dire « remets ce qui devrait
     * etre la », pas « efface ce que j'ai ajoute ».
     *
     * @return array<int, string> les cles reactivees
     *
     * @throws PresetException
     */
    public function restorePreset(User $user, Loop $loop): array
    {
        $this->assertConfigurable($user, $loop);

        $wanted = $this->types->cardsFor($loop->type);
        $active = $this->types->activeCardsFor($loop);
        $restored = [];

        DB::transaction(function () use ($loop, $wanted, $active, &$restored) {
            foreach (array_diff($wanted, $active) as $key) {
                if (! in_array($key, $this->registry->manageableKeys(), true)) {
                    continue;
                }

                $this->composition->enable($loop, $key);
                $restored[] = $key;
            }
        });

        return array_values($restored);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** @throws PresetException */
    private function assertConfigurable(User $user, Loop $loop): void
    {
        if (! $this->canConfigure($user, $loop)) {
            throw new PresetException(__('loops.preset_error_not_allowed'));
        }

        // Une Boucle archivee ne se recompose pas. La regle vient du resolveur
        // depuis TASK-1086 ; elle est redite ici parce que la politique
        // proprietaire ouvre un second chemin d'entree.
        if ($loop->isArchived()) {
            throw new PresetException(__('loops.archive_read_only'));
        }
    }

    /** @throws PresetException */
    private function assertManageable(string $key): void
    {
        if (! in_array($key, $this->registry->manageableKeys(), true)) {
            throw new PresetException(__('loops.preset_error_unknown_card'));
        }
    }

    /** @param array<int, string> $keys */
    private function labels(array $keys): string
    {
        return collect($keys)->map(fn ($k) => $this->registry->label($k))->implode(', ');
    }

    /**
     * Combien de donnees une Card porte deja.
     *
     * Delegue au service de composition, qui tient le seul recensement de ces
     * compteurs — en ajouter un second ici les ferait diverger.
     */
    private function dataCountFor(Loop $loop, string $key): ?int
    {
        return collect($this->composition->compositionFor($loop))
            ->firstWhere('key', $key)['data_count'] ?? null;
    }
}
