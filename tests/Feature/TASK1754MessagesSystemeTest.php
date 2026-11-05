<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entraide\MessageSysteme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1754 (§3.4, obstacle n°2 — critere d'acceptation n°6).
 *
 * Les faits du fil etaient STOCKES en francais dans `messages.body` : renommer
 * le lexique ne changeait rien a ce qui etait deja ecrit, et un anglophone
 * lisait « L'acheteur a declare la prestation terminee » quelle que soit sa
 * langue.
 *
 * Le contrat tient en deux phrases, et ce fichier mesure les deux :
 * les NOUVEAUX messages sont rendus par clef+params, donc traduits ;
 * les ANCIENS — qui n'ont pas de clef — s'affichent tels qu'ils ont ete ecrits.
 */
class TASK1754MessagesSystemeTest extends TestCase
{
    use RefreshDatabase;

    private Transaction $echange;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create(['slug' => 'org-sys-1754', 'is_active' => true]);
        app()->instance('current_organization', $organization);

        $this->echange = Transaction::factory()->create([
            'organization_id' => $organization->id,
            'buyer_id' => User::factory()->create(['organization_id' => $organization->id])->id,
            'seller_id' => User::factory()->create(['organization_id' => $organization->id])->id,
        ]);
    }

    /** Un fait ecrit aujourd'hui se relit dans la langue du lecteur. */
    public function test_un_message_systeme_neuf_est_traduit(): void
    {
        $message = MessageSysteme::ecrire($this->echange, 'echange_accepte');

        $this->assertSame(__('messages.systeme.echange_accepte', [], 'fr'), MessageSysteme::rendre($message));

        $this->app->setLocale('en');

        $this->assertSame(__('messages.systeme.echange_accepte', [], 'en'), MessageSysteme::rendre($message));
    }

    /**
     * Le `body` reste ecrit — c'est le REPLI, pas un oubli.
     *
     * Si la clef disparaissait du catalogue (renommage, suppression), le fil
     * afficherait encore une phrase sensee plutot qu'une chaine vide ou une
     * clef brute.
     */
    public function test_la_clef_et_le_repli_sont_ecrits_tous_les_deux(): void
    {
        $message = MessageSysteme::ecrire($this->echange, 'proposition_envoyee', ['points' => 30]);

        $this->assertSame('proposition_envoyee', $message->metadata['system_key']);
        $this->assertSame(['points' => 30], $message->metadata['params']);
        $this->assertNotSame('', trim((string) $message->body));
        $this->assertStringContainsString('30 min', $message->body);
    }

    /**
     * L'HISTORIQUE — aucune clef — s'affiche tel qu'il a ete ecrit.
     *
     * C'est l'arbitrage Q5 : aucune reprise de donnees, divergence assumee et
     * bornee dans le temps. Sans ce repli, tous les fils anterieurs au
     * 05/11/2026 se videraient.
     */
    public function test_un_message_ancien_sans_clef_garde_son_texte(): void
    {
        $ancien = Message::create([
            'transaction_id' => $this->echange->id,
            'sender_id' => null,
            'organization_id' => $this->echange->organization_id,
            'body' => 'L\'acheteur a déclaré la prestation terminée.',
            'type' => 'system',
        ]);

        $this->assertSame('L\'acheteur a déclaré la prestation terminée.', MessageSysteme::rendre($ancien));
    }

    /**
     * Une clef INCONNUE retombe sur le `body`, elle ne sort jamais telle quelle.
     *
     * Sans cette garde, supprimer une entree du catalogue afficherait
     * « messages.systeme.xxx » dans le fil de tout le monde.
     */
    public function test_une_clef_hors_catalogue_retombe_sur_le_texte(): void
    {
        $message = Message::create([
            'transaction_id' => $this->echange->id,
            'sender_id' => null,
            'organization_id' => $this->echange->organization_id,
            'body' => 'Un fait que le catalogue ne connait plus.',
            'type' => 'system',
            'metadata' => ['system_key' => 'clef_disparue', 'params' => []],
        ]);

        $this->assertSame('Un fait que le catalogue ne connait plus.', MessageSysteme::rendre($message));
    }

    /**
     * `:temps` est RECONSTRUIT depuis les points, pas recopie.
     *
     * Une phrase ecrite avant un changement de format d'affichage doit se
     * relire dans le format du jour. Les params ne stockent donc que la donnee
     * (`points`), jamais sa mise en forme.
     */
    public function test_le_temps_est_reconstruit_au_rendu(): void
    {
        $message = MessageSysteme::ecrire($this->echange, 'echange_termine', ['points' => 90]);

        $this->assertArrayNotHasKey('temps', $message->metadata['params'],
            'La mise en forme a ete stockee : la phrase se figerait dans le format d\'hier.');
        $this->assertStringContainsString('1 h 30', MessageSysteme::rendre($message));
    }
}
