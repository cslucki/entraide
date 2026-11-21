<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * TASK-1872, correctif 08 — « Derniers messages » se lit dans le sens de la
 * conversation.
 *
 * Cyril, 21/11 : « les messages au sein d'une boucle sont classes par ordre
 * decroissant de date. Il faudrait faire l'inverse. Aussi mettre la date avec
 * un texte plus clair en darkmode, car sinon on voit pas bien. »
 *
 * ## Le piege, annonce par le cahier des charges et verifie ici
 *
 * Trois choses dependaient du fait que la collection soit DECROISSANTE, et
 * inverser naivement les cassait toutes les trois en silence :
 *
 * 1. **la SELECTION des trois** — `take(3)` sur une collection croissante
 *    aurait retenu les trois plus ANCIENS ;
 * 2. **le tri des BOUCLES** — il lisait l'activite sur `messages->first()` ;
 * 3. **le lien de la couverture** — il visait `messages->first()`, donc le
 *    message le plus recent.
 *
 * Aucune de ces trois n'aurait leve d'erreur. C'est pour cela que la moitie
 * de ce fichier les garde une par une, plutot que de se contenter de
 * verifier l'ordre d'affichage.
 */
class TASK1872DerniersMessagesChronologieTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true,
        ]);

        app()->instance('current_organization', $this->org);

        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function boucle(string $nom): Loop
    {
        return (new LoopService)->createLoop($this->membre, $nom, type: 'project');
    }

    /**
     * `created_at` n'est pas `fillable` : le poser APRES la construction est
     * la seule facon de le maitriser. Deux messages a la meme microseconde
     * rendraient l'ordre non deterministe — lecon deja consignee du depot.
     */
    private function messageAt(Loop $loop, string $corps, Carbon $quand): LoopMessage
    {
        $message = new LoopMessage([
            'loop_id' => $loop->id,
            'organization_id' => $this->org->id,
            'sender_id' => $this->membre->id,
            'body' => $corps,
            'type' => 'user',
        ]);
        $message->created_at = $quand;
        $message->save();

        return $message;
    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.loops.latest-messages', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();
    }

    /** La position d'un texte dans la page — pour comparer des ORDRES. */
    private function positionDe(string $html, string $texte): int
    {
        $position = strpos($html, e($texte));

        $this->assertNotFalse($position, "Texte introuvable dans la page : {$texte}");

        return $position;
    }

    /* ─────────── L'ORDRE DEMANDE ─────────── */

    public function test_les_messages_d_une_boucle_vont_du_plus_ancien_au_plus_recent(): void
    {
        $boucle = $this->boucle('Boucle Chronologie');

        $this->messageAt($boucle, 'DIX HEURES', Carbon::parse('2026-11-20 10:00:00'));
        $this->messageAt($boucle, 'ONZE HEURES', Carbon::parse('2026-11-20 11:00:00'));
        $this->messageAt($boucle, 'DOUZE HEURES', Carbon::parse('2026-11-20 12:00:00'));

        $html = $this->page();

        $this->assertLessThan(
            $this->positionDe($html, 'ONZE HEURES'),
            $this->positionDe($html, 'DIX HEURES'),
            'Le fil ne se lit pas dans le sens ou il s\'est tenu.'
        );
        $this->assertLessThan(
            $this->positionDe($html, 'DOUZE HEURES'),
            $this->positionDe($html, 'ONZE HEURES'),
        );
    }

    /* ─────────── LES TROIS CHOSES QUE L'INVERSION POUVAIT CASSER ─────────── */

    /**
     * 1. La SELECTION reste celle des trois PLUS RECENTS.
     *
     * C'est le piege principal : `take(3)` applique apres inversion aurait
     * retenu les trois plus ANCIENS, et la page aurait affiche un ordre juste
     * sur les mauvais messages — un defaut qu'un test d'ordre seul ne verrait
     * pas.
     */
    public function test_une_quatrieme_entree_plus_ancienne_n_apparait_pas(): void
    {
        $boucle = $this->boucle('Boucle Quatre');

        $this->messageAt($boucle, 'LE PLUS ANCIEN DES QUATRE', Carbon::parse('2026-11-20 09:00:00'));
        $this->messageAt($boucle, 'DIX HEURES', Carbon::parse('2026-11-20 10:00:00'));
        $this->messageAt($boucle, 'ONZE HEURES', Carbon::parse('2026-11-20 11:00:00'));
        $this->messageAt($boucle, 'DOUZE HEURES', Carbon::parse('2026-11-20 12:00:00'));

        $html = $this->page();

        $this->assertStringNotContainsString('LE PLUS ANCIEN DES QUATRE', $html,
            'La selection porte sur les trois plus ANCIENS au lieu des trois plus recents.');

        // TEMOIN : les trois attendus sont bien la, sinon l'assertion
        // ci-dessus passerait sur une page vide.
        foreach (['DIX HEURES', 'ONZE HEURES', 'DOUZE HEURES'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
    }

    /**
     * 2. Les BOUCLES restent classees par activite recente decroissante.
     *
     * Le tri lisait l'activite sur `messages->first()`. Apres inversion,
     * `first()` designe le plus ancien du groupe : les Boucles se seraient
     * reordonnees en silence.
     */
    public function test_les_boucles_restent_classees_par_activite_recente(): void
    {
        /* ⚠️ LES PLAGES SE CHEVAUCHENT, ET C'EST TOUT L'INTERET.
           -----------------------------------------------------
           Ma premiere version donnait a chaque Boucle une plage distincte —
           l'une en novembre 1er, l'autre le 20. Les deux lectures possibles
           (plus ancien ou plus recent du groupe) rendaient alors le MEME
           ordre : le test passait, et un sabotage de la cle de tri le
           laissait vert. Il ne mesurait rien.

           Ici les plages s'entrelacent a dessein :

             EN TETE   10h00 … 12h00   le plus recent de la page
             SUIVANTE  11h00 … 11h30   mais son plus ANCIEN est posterieur

           Trier sur le plus recent met EN TETE devant. Trier sur le plus
           ancien — l'erreur que l'inversion introduit — met SUIVANTE devant.
           Les deux lectures divergent, donc l'assertion discrimine. */
        $enTete = $this->boucle('BOUCLE EN TETE');
        $suivante = $this->boucle('BOUCLE SUIVANTE');

        $this->messageAt($enTete, 'en tete, ancien', Carbon::parse('2026-11-20 10:00:00'));
        $this->messageAt($enTete, 'en tete, recent', Carbon::parse('2026-11-20 12:00:00'));

        $this->messageAt($suivante, 'suivante, ancien', Carbon::parse('2026-11-20 11:00:00'));
        $this->messageAt($suivante, 'suivante, recent', Carbon::parse('2026-11-20 11:30:00'));

        $html = $this->page();

        $this->assertLessThan(
            $this->positionDe($html, 'BOUCLE SUIVANTE'),
            $this->positionDe($html, 'BOUCLE EN TETE'),
            'Le classement des Boucles se fait sur le message le plus ANCIEN de chaque groupe.'
        );
    }

    /**
     * 3. Le lien de la couverture mene au message le PLUS RECENT.
     *
     * « Emmene-moi la ou la conversation en est. » Il visait
     * `messages->first()` : apres inversion il aurait mene au plus ancien des
     * trois, sans erreur et sans rien signaler.
     */
    public function test_le_lien_de_la_couverture_mene_au_message_le_plus_recent(): void
    {
        $boucle = $this->boucle('Boucle Lien');

        $this->messageAt($boucle, 'ancien', Carbon::parse('2026-11-20 10:00:00'));
        $recent = $this->messageAt($boucle, 'recent', Carbon::parse('2026-11-20 12:00:00'));

        $html = $this->page();

        // L'ancre de couverture est le lien en recouvrement (`absolute
        // inset-0`) pose juste apres le titre de la Boucle.
        $this->assertSame(
            1,
            preg_match('/<a href="([^"]*)" class="absolute inset-0"/', $html, $trouve),
            'Le lien de couverture n\'a pas ete trouve.'
        );

        $this->assertStringContainsString($recent->id, html_entity_decode($trouve[1]),
            'La couverture mene a un autre message que le plus recent.');
    }

    /* ─────────── LA DATE, LISIBLE EN SOMBRE ─────────── */

    /**
     * Mesure, pas impression : `gray-500` sur le fond `gray-800` de la carte
     * donne 3.04:1, sous le seuil AA de 4.5 pour du petit texte. `gray-400`
     * monte a 5.78:1.
     */
    public function test_la_date_porte_une_couleur_lisible_en_mode_sombre(): void
    {
        $boucle = $this->boucle('Boucle Sombre');
        $this->messageAt($boucle, 'un message', Carbon::parse('2026-11-20 10:00:00'));

        $html = $this->page();

        $this->assertSame(
            1,
            preg_match('/<span class="([^"]*)"[^>]*data-loops-latest-message-date/', $html, $trouve),
            'La date du message n\'a pas ete trouvee.'
        );

        $this->assertStringContainsString('dark:text-gray-400', $trouve[1]);
        $this->assertStringNotContainsString('dark:text-gray-500', $trouve[1],
            'La date retombe sous le seuil de contraste en mode sombre.');
    }
}
