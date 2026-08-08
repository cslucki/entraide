<?php

use App\Models\Loop;
use App\Models\LoopCard;
use App\Support\Loops\LoopCardRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * Le rattrapage des Dossiers sur les Boucles Projet existantes.
 *
 * **Ajouter une Card a un preset n'atteint aucune Boucle deja creee** :
 * `activeCardsFor()` ne retombe sur le preset que si la Boucle n'a aucune ligne
 * `loop_cards`, et `2026_08_04_090100_materialise_loop_cards_before_type_presets_change`
 * les a materialisees pour tout le parc. C'est un invariant de cette serie,
 * trouve deux fois par la revue avant d'etre ecrit d'avance.
 *
 * ## Ce qui a failli casser
 *
 * Le preset Projet porte desormais **exactement** trois Cards de grille pour
 * trois `grid_slots`. Ecrire la quatrieme sur une Boucle qui en portait deja
 * une ajoutee a la main — un Journal, des Sondages — n'aurait rien casse en
 * base, mais `workspaceCardsFor()` tronque a trois **sans un mot** : une Card
 * porteuse de donnees serait sortie de l'ecran.
 *
 * Le Journal n'a aucune route a lui : sa Card est sa seule surface. La donnee
 * aurait survecu, l'acces non. Et sur une Boucle archivee, la recomposition est
 * refusee : l'eviction y aurait ete irreversible sans desarchiver.
 *
 * **Ce rattrapage ne prend donc la place de personne.** Une Boucle deja au
 * plafond est laissee telle quelle : la Card reste disponible dans l'ecran de
 * composition, ou un humain choisira ce qu'il remplace. Ne rien faire est le
 * seul geste honnete quand le geste utile detruirait un acces.
 *
 * `firstOrCreate` : la Card ne se dedouble pas, et une Card **desactivee a la
 * main** reste desactivee. Rien n'est jamais retire.
 */
return new class extends Migration
{
    public function up(): void
    {
        $registre = app(LoopCardRegistry::class);
        $plafond = (int) config('loop_cards.grid_slots', 3);
        $catalogue = config('loop_cards.cards', []);

        Loop::query()
            ->where('type', 'project')
            ->whereHas('cards')
            ->each(function (Loop $loop) use ($plafond, $catalogue) {
                $lignes = LoopCard::where('loop_id', $loop->id)->get();

                // Deja la : rien a faire, et surtout rien a rallumer.
                if ($lignes->contains('card_key', 'core.dossiers')) {
                    return;
                }

                $enGrille = $lignes
                    ->filter(fn (LoopCard $c) => (bool) $c->enabled)
                    ->filter(fn (LoopCard $c) => ($catalogue[$c->card_key]['placement'] ?? null) === 'grid')
                    ->count();

                // Au plafond : ajouter la Card en chasserait une autre de
                // l'ecran. On s'abstient.
                if ($enGrille >= $plafond) {
                    return;
                }

                LoopCard::firstOrCreate(
                    ['loop_id' => $loop->id, 'card_key' => 'core.dossiers'],
                    [
                        'organization_id' => $loop->organization_id,
                        'enabled' => true,
                        'added_by_preset' => $loop->type,
                    ],
                );
            });
    }

    public function down(): void
    {
        // Rien n'est retire : une ligne posee par ce rattrapage est
        // indistinguable de celle qu'un administrateur aurait creee, et la
        // supprimer eteindrait sa Card sans qu'il l'ait demande.
    }
};
