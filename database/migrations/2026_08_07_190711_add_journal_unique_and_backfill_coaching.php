<?php

use App\Models\Loop;
use App\Models\LoopCard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deux corrections apportees a la Card Journal apres revue.
 *
 * 1. **L'unicite d'un message garde n'etait tenue par rien.** La garde de
 *    `promote()` est un `SELECT … FOR UPDATE` sur une ligne **inexistante** :
 *    PostgreSQL ne pose pas de verrou d'intervalle, et `lockForUpdate()` est un
 *    no-op sous SQLite. Deux promotions concurrentes du meme message inseraient
 *    donc toutes les deux. L'invariant reposait sur l'absence de concurrence ;
 *    il repose maintenant sur la base.
 *
 * 2. **La Card n'atteignait aucune Boucle Coaching existante.** Ajouter une
 *    Card au preset ne suffit pas : `activeCardsFor()` ne retombe sur le preset
 *    que si la Boucle n'a **aucune** ligne `loop_cards`, et une migration
 *    anterieure les a materialisees pour tout le parc. La livraison etait donc
 *    morte sur les Boucles deja creees — meme defaut, non traite, sur les trois
 *    Cards de Formation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Les doublons eventuels partent avant la contrainte : sans cela elle
        // ne pourrait pas se poser. On garde la **plus ancienne** — c'est celle
        // que quelqu'un a voulu creer.
        $doublons = DB::table('loop_journal_entries')
            ->select('loop_id', 'loop_message_id', DB::raw('MIN(created_at) as premiere'))
            ->whereNotNull('loop_message_id')
            ->groupBy('loop_id', 'loop_message_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($doublons as $groupe) {
            DB::table('loop_journal_entries')
                ->where('loop_id', $groupe->loop_id)
                ->where('loop_message_id', $groupe->loop_message_id)
                ->where('created_at', '>', $groupe->premiere)
                ->delete();
        }

        Schema::table('loop_journal_entries', function (Blueprint $table) {
            // Plusieurs NULL ne s'egalent ni en SQLite ni en PostgreSQL : les
            // entrees ecrites directement gardent donc leur liberte.
            $table->unique(['loop_id', 'loop_message_id']);
        });

        // Le rattrapage. `firstOrCreate` plutot qu'une insertion en masse : une
        // Boucle qui aurait deja la Card ne doit pas recevoir de doublon.
        Loop::query()
            ->whereIn('type', ['coaching', 'peer_support'])
            ->whereHas('cards')
            ->each(function (Loop $loop) {
                LoopCard::firstOrCreate(
                    ['loop_id' => $loop->id, 'card_key' => 'core.journal'],
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
        // Les lignes ajoutees par le rattrapage ne sont pas retirees : elles
        // sont indistinguables de celles qu'un administrateur aurait posees a
        // la main, et en supprimer une desactiverait sa Card.
        Schema::table('loop_journal_entries', function (Blueprint $table) {
            $table->dropUnique(['loop_id', 'loop_message_id']);
        });
    }
};
