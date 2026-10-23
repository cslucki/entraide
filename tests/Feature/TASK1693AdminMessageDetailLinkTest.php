<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug R — la DATE mene au message dans son fil.
 *
 * ## Ce qui manquait
 *
 * « Il faut ajouter un lien, sur la date par exemple, pour visualiser le
 * message » (recette Cyril, 21/10). La liste ne montrait que 100 caracteres
 * tronques et aucun moyen d'atteindre le detail : la page `admin.messages.show`
 * existait — avec le voisinage de la conversation, 5 messages avant et 5
 * apres — mais AUCUNE poignee n'y menait. Une capacite non exposee est une
 * capacite absente.
 *
 * ## Deux manques, pas un
 *
 * 1. Le lien, c'est le geste demande.
 * 2. Mais `show()` ne connaissait que les conversations d'ECHANGE, et l'onglet
 *    ChatLoop est celui par DEFAUT de l'ecran : le lien n'aurait existe que
 *    pour une minorite de lignes. `loop-messages.show` est donc ajoutee, avec
 *    les MEMES bornes que son jumeau.
 *
 * ## Et un lien qui n'aboutirait pas n'est pas pose
 *
 * `show()` rend 404 hors de l'Organisation de l'administrateur, et c'est une
 * garantie ECRITE (`AdminMessagesTest::test_admin_cannot_view_message_detail_outside_organization`)
 * qu'on ne touche pas. Or le selecteur « Toutes les organisations » peut faire
 * figurer de telles lignes dans la liste. Le lien n'est donc pose que lorsque
 * le serveur l'honorera — sinon la date reste du texte. C'est la regle du bug
 * K : aucun geste offert ne doit etre refuse.
 */
class TASK1693AdminMessageDetailLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693r',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $this->auteur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function messageDeBoucle(?Organization $dans = null, string $body = 'Le message cible'): LoopMessage
    {
        $org = $dans ?? $this->organization;

        $loop = Loop::factory()->create([
            'organization_id' => $org->id,
            'visibility' => 'public',
            'status' => 'active',
        ]);

        return LoopMessage::query()->create([
            'loop_id' => $loop->id,
            'sender_id' => $this->auteur->id,
            'organization_id' => $org->id,
            'body' => $body,
            'type' => 'user',
        ]);
    }

    /** Un voisin dans le MEME fil, a une minute de distance reelle. */
    private function voisin(LoopMessage $cible, string $body, int $minutes): LoopMessage
    {
        $voisin = LoopMessage::query()->create([
            'loop_id' => $cible->loop_id,
            'sender_id' => $this->auteur->id,
            'organization_id' => $cible->organization_id,
            'body' => $body,
            'type' => 'user',
        ]);

        $voisin->forceFill(['created_at' => $cible->created_at->copy()->addMinutes($minutes)])->save();

        return $voisin;
    }

    private function messageDEchange(?Organization $dans = null): Message
    {
        $org = $dans ?? $this->organization;

        $transaction = Transaction::factory()->create([
            'organization_id' => $org->id,
            'buyer_id' => $this->auteur->id,
            'seller_id' => User::factory()->complete()->create(['organization_id' => $org->id])->id,
        ]);

        return Message::query()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $this->auteur->id,
            'organization_id' => $org->id,
            'body' => 'Le message cible',
            'type' => 'user',
            'created_at' => now(),
        ]);
    }

    private function liste(string $filter): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => $filter, 'organization_id' => 'all']))
            ->assertOk()
            ->getContent();
    }

    /** LE geste demande, sur les deux familles. */
    public function test_the_date_links_to_the_detail_for_both_message_families(): void
    {
        $chatloop = $this->messageDeBoucle();
        $echange = $this->messageDEchange();

        $htmlChatloop = $this->liste('chatloop');
        $this->assertStringContainsString('data-message-show-link="'.$chatloop->id.'"', $htmlChatloop);
        $this->assertStringContainsString(e(route('admin.loop-messages.show', $chatloop)), $htmlChatloop);

        $htmlEchange = $this->liste('exchanges');
        $this->assertStringContainsString('data-message-show-link="'.$echange->id.'"', $htmlEchange);
        $this->assertStringContainsString(e(route('admin.messages.show', $echange)), $htmlEchange);
    }

    /**
     * Le detail d'un message de ChatLoop, qui n'existait pas — et son
     * voisinage, qui est la raison d'aller voir.
     */
    public function test_the_chatloop_detail_shows_the_message_and_its_neighbourhood(): void
    {
        $cible = $this->messageDeBoucle(body: 'La phrase a relire');

        // `created_at` n'est pas `fillable` sur ce modele : le passer a
        // `create()` est IGNORE en silence, et les trois messages atterrissent
        // a la meme seconde (`timestamp(0)`). Les bornes strictes `<` et `>` du
        // voisinage les excluaient alors tous les deux — un premier jet de ce
        // test mesurait donc une page vide sans le dire.
        $avant = $this->voisin($cible, 'Ce qui precede', -1);
        $apres = $this->voisin($cible, 'Ce qui suit', 1);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-messages.show', $cible))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('La phrase a relire', $html);
        $this->assertStringContainsString($avant->body, $html, 'une phrase se juge sur ce a quoi elle repond');
        $this->assertStringContainsString($apres->body, $html);
        $this->assertStringContainsString(e($cible->loop->name), $html, 'le contexte nomme est la BOUCLE');
    }

    /** Le voisinage ne franchit pas la Boucle : un autre fil n'est pas melange. */
    public function test_the_chatloop_neighbourhood_stays_inside_its_own_loop(): void
    {
        $cible = $this->messageDeBoucle();
        $ailleurs = $this->messageDeBoucle(body: 'Un message d\'une AUTRE Boucle');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-messages.show', $cible))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($ailleurs->body, $html);
    }

    /**
     * LA garde que ce correctif ne doit pas percer.
     *
     * `show()` rend 404 hors de l'Organisation de l'administrateur. Le nouveau
     * chemin ChatLoop porte la MEME borne — sinon le bug R aurait ouvert par la
     * porte de derriere ce que la porte de devant refuse.
     */
    public function test_the_chatloop_detail_is_refused_outside_the_admin_organization(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1693r-autre', 'is_active' => true]);
        $ailleurs = $this->messageDeBoucle($autreOrg);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-messages.show', $ailleurs))
            ->assertNotFound();
    }

    /** Et un administrateur sans Organisation ne voit aucun detail. */
    public function test_an_admin_without_an_organization_gets_no_chatloop_detail(): void
    {
        $sansOrg = User::factory()->create(['organization_id' => null, 'is_admin' => true]);

        $this->actingAs($sansOrg)
            ->get(route('admin.loop-messages.show', $this->messageDeBoucle()))
            ->assertNotFound();
    }

    /**
     * LE point de ce correctif qui demandait une decision : une ligne dont le
     * detail serait refuse ne recoit PAS de lien.
     *
     * La garantie 404 est ecrite et reste entiere ; offrir malgre tout le lien
     * aurait fabrique un cul-de-sac — exactement le defaut du bug K, ou un
     * bouton « Rejoindre » menait a un 403.
     */
    public function test_a_row_from_another_organization_gets_no_link_at_all(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1693r-tiers', 'is_active' => true]);
        $ailleurs = $this->messageDeBoucle($autreOrg);
        $chezNous = $this->messageDeBoucle();

        $html = $this->liste('chatloop');

        // La ligne est bien LISTEE — on ne cache rien a l'administration.
        $this->assertStringContainsString('data-message-show-unavailable="'.$ailleurs->id.'"', $html);
        $this->assertStringNotContainsString('data-message-show-link="'.$ailleurs->id.'"', $html,
            'un lien que le serveur refuse ne doit pas etre pose');

        // Et la ligne de notre Organisation garde le sien.
        $this->assertStringContainsString('data-message-show-link="'.$chezNous->id.'"', $html);
    }

    /**
     * La garde de fond : TOUT lien pose par la liste aboutit reellement.
     *
     * C'est la formulation generale du defaut. Un test par cas pourrait rester
     * vert en se trompant de paire ; celui-ci suit chaque lien offert.
     */
    public function test_every_link_the_list_offers_actually_opens(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1693r-fond', 'is_active' => true]);
        $this->messageDeBoucle($autreOrg);
        $this->messageDeBoucle();
        $this->messageDEchange();
        $this->messageDEchange($autreOrg);

        foreach (['chatloop', 'exchanges'] as $filter) {
            $html = $this->liste($filter);

            preg_match_all('/href="([^"]*admin\/(?:loop-)?messages\/[0-9a-f-]{36})"/', $html, $m);

            $this->assertNotEmpty($m[1], "[{$filter}] aucun lien de detail trouve : le test ne mesurerait rien");

            foreach (array_unique($m[1]) as $url) {
                $this->actingAs($this->superAdmin)
                    ->get(html_entity_decode($url))
                    ->assertOk();
            }
        }
    }

    /** Depuis le detail, on peut corriger — et depuis la correction, relire. */
    public function test_the_detail_and_the_edit_page_point_at_each_other(): void
    {
        $message = $this->messageDeBoucle();

        $detail = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-messages.show', $message))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-message-detail-edit-link="'.$message->id.'"', $detail);
        $this->assertStringContainsString(e(route('admin.loop-messages.edit', $message)), $detail);

        $edition = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-messages.edit', $message))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-message-edit-show-link', $edition);
        $this->assertStringContainsString(e(route('admin.loop-messages.show', $message)), $edition);
    }

    /** Le detail reste reserve a l'administration plateforme. */
    public function test_the_chatloop_detail_stays_with_the_platform_administration(): void
    {
        $url = route('admin.loop-messages.show', $this->messageDeBoucle());

        // Anonyme d'abord : `actingAs()` persiste pour le reste du test.
        $this->get($url)->assertRedirect(route('login'));

        $this->actingAs($this->auteur)->get($url)->assertForbidden();
    }

    /** La mention « modifie » du bug Q se lit aussi sur le detail. */
    public function test_the_detail_names_the_last_edit_and_its_author(): void
    {
        $message = $this->messageDeBoucle();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-messages.update', $message), ['body' => 'Corrige par la moderation'])
            ->assertRedirect();

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-messages.show', $message->fresh()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-message-detail-edited', $html);
        // Viser la forme ECHAPPEE : Blade rend l'apostrophe en `&#039;`, et la
        // fabrique tire des noms qui en portent. Ce test est passe vert ici et
        // ROUGE en CI sur « O'Hara » — troisieme visage de la lecon TASK-1672.
        $this->assertStringContainsString(e($this->superAdmin->full_name), $html);
    }
}
