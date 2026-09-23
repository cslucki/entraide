<?php

namespace Tests\Feature\Knowledge;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Knowledge\LoopClaimCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1615 — l'empreinte de source ne depend pas de l'ordre physique des lignes.
 *
 * ## Le defaut
 *
 * `loop_messages.created_at` est un `timestamp` de precision **0** : la seconde
 * pleine, sans sous-seconde. Trois messages ecrits dans la meme requete portent
 * donc EXACTEMENT le meme `created_at`.
 *
 * `LoopClaimCompiler::sourceMessages()` ordonnait par `created_at` SEUL. A
 * egalite, PostgreSQL rend un ordre INDEFINI — et libre de varier entre deux
 * requetes pourtant identiques, selon l'etat du tas.
 *
 * `empreinteSource()` hache l'ORDRE (`implode('|', ['<id>:<ts>', ...])`). Deux
 * ordres rendent donc deux empreintes pour une source INCHANGEE, et la garde
 * de cout de TASK-1541 cede : `hash_equals` echoue, le compilateur appelle le
 * modele, et le balayeur automatique refacture toutes les dix minutes une
 * conversation endormie.
 *
 * ## Ce que ce fichier mesure, et pourquoi PAS « en rejouant jusqu'a ce que ca
 * casse »
 *
 * Un ordre nondeterministe ne se reproduit pas a volonte : un test qui
 * relancerait la requete en esperant la malchance serait vert la plupart du
 * temps et ne prouverait rien. On mesure donc l'INVARIANT, en forçant
 * l'egalite de `created_at` puis en faisant varier ce que le moteur peut
 * legitimement changer — l'ordre physique des lignes.
 *
 * Le premier test est le plus direct : il lit l'ORDER BY reel. A egalite, une
 * seule colonne ne peut pas departager, et c'est exactement ce que la requete
 * doit refuser.
 */
class TASK1615SourceOrderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
        ]);
    }

    /**
     * PREMISSE — la colonne ne porte PAS la sous-seconde.
     *
     * Si un jour `created_at` gagnait une precision sous-seconde, l'egalite
     * deviendrait rare sans disparaitre, et ce fichier devrait etre relu
     * plutot que supprime : un ordre total reste un ordre total.
     */
    public function test_premisse_created_at_n_a_pas_de_sous_seconde_et_l_egalite_est_donc_possible(): void
    {
        $messages = $this->troisMessagesALaMemeSeconde();

        $dates = array_map(
            static fn (LoopMessage $m): string => (string) $m->fresh()->created_at?->format('Y-m-d H:i:s.u'),
            $messages,
        );

        $this->assertCount(1, array_unique($dates),
            'PREMISSE : les trois messages doivent porter le MEME created_at, sinon ce fichier ne mesure rien');
    }

    /**
     * La garde elle-meme : l'ORDER BY doit departager.
     *
     * Mesure la requete, pas la chance. `created_at` seul ne peut pas
     * departager trois lignes qui portent la meme valeur — c'est structurel,
     * et c'est donc structurellement que ca se verifie.
     */
    public function test_la_requete_de_source_porte_un_ordre_total(): void
    {
        $sql = $this->sqlDeSourceMessages();

        $this->assertStringContainsString('order by', $sql,
            'la requete de source doit etre ordonnee');

        $ordre = substr($sql, strpos($sql, 'order by'));

        $this->assertStringContainsString('"id"', $ordre,
            'A egalite de seconde, `created_at` seul ne departage rien et PostgreSQL rend un ordre '
            ."indefini. L'ordre canonique du depot est (created_at, id) — ClaimResurrectionGuard, "
            .'GuestConversation. La requete doit le porter.');
    }

    /**
     * L'invariant : la MEME source rend la MEME empreinte, quel que soit
     * l'ordre dans lequel le moteur rend les lignes.
     *
     * L'ordre physique est ce que le moteur peut legitimement changer entre
     * deux requetes a egalite. On le fait donc varier pour de vrai — en
     * reecrivant les lignes dans un ordre different — et on exige que
     * l'empreinte, elle, ne bouge pas.
     */
    public function test_l_empreinte_ne_bouge_pas_quand_l_ordre_physique_des_lignes_change(): void
    {
        $messages = $this->troisMessagesALaMemeSeconde();

        $avant = $this->empreinte();

        // Reecrire les lignes dans un ordre different : sur un tas, une ligne
        // supprimee puis reinserree change de place. C'est exactement ce que
        // subit une table au fil des tests voisins, et c'est ce qui a fait
        // basculer le shard 4 en CI.
        foreach (array_reverse($messages) as $message) {
            $ligne = (array) DB::table('loop_messages')->where('id', $message->id)->first();
            DB::table('loop_messages')->where('id', $message->id)->delete();
            DB::table('loop_messages')->insert($ligne);
        }

        $apres = $this->empreinte();

        $this->assertSame($avant, $apres,
            'Une source INCHANGEE doit rendre la MEME empreinte. Sinon la garde de cout de '
            .'TASK-1541 cede et le balayeur refacture une conversation endormie.');
    }

    /** L'ordre rendu est stable d'un appel a l'autre. */
    public function test_l_ordre_rendu_est_stable_d_un_appel_a_l_autre(): void
    {
        $this->troisMessagesALaMemeSeconde();

        $premier = $this->idsDeSource();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($premier, $this->idsDeSource(),
                'deux lectures consecutives de la meme source doivent rendre le meme ordre');
        }
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    /**
     * Trois messages qui portent EXACTEMENT le meme `created_at`.
     *
     * La date est posee en SQL, pas par les timestamps du modele : il faut
     * l'egalite exacte, pas « probablement la meme seconde ».
     *
     * @return list<LoopMessage>
     */
    private function troisMessagesALaMemeSeconde(): array
    {
        $instant = '2026-09-21 10:00:00';
        $messages = [];

        foreach (['Le budget est de 486 000 euros.', 'Le plan est attendu le 15 novembre.', 'Vaucanson realise la charpente.'] as $corps) {
            $messages[] = LoopMessage::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $this->loop->id,
                'sender_id' => $this->alice->id,
                'body' => $corps,
                'type' => 'user',
            ]);
        }

        DB::table('loop_messages')
            ->whereIn('id', array_map(static fn (LoopMessage $m): string => (string) $m->id, $messages))
            ->update(['created_at' => $instant, 'updated_at' => $instant]);

        return $messages;
    }

    /** L'empreinte que le compilateur calculerait sur cette source. */
    private function empreinte(): string
    {
        return $this->invoquer('empreinteSource', $this->invoquer('sourceMessages', $this->loop->fresh()));
    }

    /** @return list<string> */
    private function idsDeSource(): array
    {
        return array_map(
            static fn (LoopMessage $m): string => (string) $m->id,
            $this->invoquer('sourceMessages', $this->loop->fresh()),
        );
    }

    private function sqlDeSourceMessages(): string
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->invoquer('sourceMessages', $this->loop->fresh());

        $journal = DB::getQueryLog();
        DB::disableQueryLog();

        $requete = collect($journal)
            ->pluck('query')
            ->first(static fn (string $q): bool => str_contains($q, 'loop_messages'));

        $this->assertNotNull($requete, 'la requete de source n\'a pas ete observee');

        return strtolower($requete);
    }

    /** Appelle une methode privee de LoopClaimCompiler : c'est elle qu'on mesure. */
    private function invoquer(string $methode, mixed ...$arguments): mixed
    {
        $compiler = app(LoopClaimCompiler::class);
        $reflet = new \ReflectionMethod($compiler, $methode);
        $reflet->setAccessible(true);

        return $reflet->invoke($compiler, ...$arguments);
    }
}
