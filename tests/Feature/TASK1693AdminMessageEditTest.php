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
 * TASK-1693, bug Q — modifier un message depuis `/admin/messages`.
 *
 * ## Ce qui manquait
 *
 * L'ecran de moderation ne savait que SUPPRIMER. Pour une faute de frappe, une
 * donnee personnelle lachee dans un fil ou une phrase a retirer, le seul geste
 * disponible detruisait le message entier — et avec lui la suite de la
 * conversation qui lui repondait.
 *
 * ## Les deux familles
 *
 * L'ecran melange deux modeles : `LoopMessage` (ChatLoop) et `Message`
 * (conversations d'echange). Les deux doivent etre modifiables, sinon la moitie
 * de l'ecran garderait l'ancien cul-de-sac. La REGLE vit a un seul endroit
 * (`appliquerEdition`), et ces tests la mesurent sur les deux chemins.
 *
 * ## Ce que ces tests verrouillent surtout
 *
 * La TRACE. Un administrateur qui reecrit les mots de quelqu'un d'autre ne doit
 * pas pouvoir le faire en silence : `edited_at` ET `edited_by` sont inscrits, et
 * la mention « modifie » apparait aupres des MEMBRES — pas seulement dans
 * l'administration. Sans cela, la capacite serait une falsification silencieuse.
 */
class TASK1693AdminMessageEditTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693q',
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

    private function messageDeBoucle(string $body = 'Le corps d\'origine'): LoopMessage
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'status' => 'active',
        ]);

        return LoopMessage::query()->create([
            'loop_id' => $loop->id,
            'sender_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
            'body' => $body,
            'type' => 'user',
        ]);
    }

    private function messageDEchange(string $body = 'Le corps d\'origine'): Message
    {
        $transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->auteur->id,
            'seller_id' => User::factory()->complete()->create(['organization_id' => $this->organization->id])->id,
        ]);

        return Message::query()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
            'body' => $body,
            'type' => 'user',
            'created_at' => now(),
        ]);
    }

    /** LE manque signale : la liste offre desormais le geste, sur les deux familles. */
    public function test_the_list_offers_an_edit_link_for_both_message_families(): void
    {
        $chatloop = $this->messageDeBoucle();
        $echange = $this->messageDEchange();

        $htmlChatloop = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'chatloop', 'organization_id' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-message-edit-link="'.$chatloop->id.'"', $htmlChatloop);
        $this->assertStringContainsString(
            e(route('admin.loop-messages.edit', $chatloop)),
            $htmlChatloop,
        );

        $htmlEchange = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'exchanges', 'organization_id' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-message-edit-link="'.$echange->id.'"', $htmlEchange);
        $this->assertStringContainsString(
            e(route('admin.messages.edit', $echange)),
            $htmlEchange,
        );
    }

    /**
     * La page porte le corps COMPLET.
     *
     * La liste n'en montre que 100 caracteres tronques : editer depuis la liste
     * aurait amputé tout message plus long que l'apercu.
     */
    public function test_the_edit_page_carries_the_whole_body_not_the_truncated_preview(): void
    {
        $long = str_repeat('Une phrase assez longue pour depasser l\'apercu. ', 6);
        $message = $this->messageDeBoucle($long);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-messages.edit', $message))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e($long), $html);
        $this->assertStringContainsString('data-message-edit-form', $html);
        // Le contexte est montre, pour qu'on voie de qui sont les mots.
        $this->assertStringContainsString($this->auteur->email, $html);
    }

    /** Le geste aboutit — ChatLoop. */
    public function test_editing_a_chatloop_message_rewrites_the_body(): void
    {
        $message = $this->messageDeBoucle();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-messages.update', $message), ['body' => 'Le corps corrige'])
            ->assertRedirect(route('admin.loop-messages.edit', $message))
            ->assertSessionHas('success');

        $this->assertSame('Le corps corrige', $message->fresh()->body);
    }

    /** Le geste aboutit — conversation d'echange. */
    public function test_editing_an_exchange_message_rewrites_the_body(): void
    {
        $message = $this->messageDEchange();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.messages.update', $message), ['body' => 'Le corps corrige'])
            ->assertRedirect(route('admin.messages.edit', $message))
            ->assertSessionHas('success');

        $this->assertSame('Le corps corrige', $message->fresh()->body);
    }

    /**
     * LA garantie de ce correctif : la trace, sur les deux familles.
     *
     * `edited_by` est ce qui distingue une correction par l'auteur d'une
     * correction par la moderation. Sans lui, les deux se confondraient dans
     * `edited_at` — et `messages` n'avait meme aucune des deux colonnes.
     */
    public function test_every_edit_records_both_when_and_who(): void
    {
        foreach ([
            ['chatloop', $this->messageDeBoucle(), 'admin.loop-messages.update'],
            ['echange', $this->messageDEchange(), 'admin.messages.update'],
        ] as [$famille, $message, $route]) {
            $this->assertNull($message->edited_at, "[{$famille}] un message neuf n'est pas modifie");

            $this->actingAs($this->superAdmin)
                ->put(route($route, $message), ['body' => 'Corrige par la moderation'])
                ->assertRedirect();

            $message->refresh();

            $this->assertNotNull($message->edited_at, "[{$famille}] la date de modification doit etre inscrite");
            $this->assertSame($this->superAdmin->id, $message->edited_by,
                "[{$famille}] le DECIDEUR doit etre inscrit : une correction par la moderation n'est pas une correction par l'auteur");
        }
    }

    /**
     * Et surtout : la mention « modifie » arrive jusqu'aux MEMBRES.
     *
     * Une trace que seule l'administration peut lire ne protege personne. Le
     * ChatLoop affichait deja `edited_at` ; la bulle partagee des echanges
     * savait le faire mais ne recevait pas l'information.
     */
    public function test_the_edited_marker_reaches_the_admin_list(): void
    {
        $message = $this->messageDeBoucle();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-messages.update', $message), ['body' => 'Corrige'])
            ->assertRedirect();

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'chatloop', 'organization_id' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-message-edited="'.$message->id.'"', $html);
        $this->assertStringContainsString(e(__('messages.edited')), $html);
    }

    /** Le fil d'echange transmet l'information a la bulle partagee. */
    public function test_the_exchange_thread_passes_the_edited_flag_to_the_shared_bubble(): void
    {
        $vue = file_get_contents(resource_path('views/livewire/message-thread.blade.php'));

        $this->assertSame(
            2,
            substr_count($vue, ':is-edited="$message->edited_at !== null"'),
            'les deux bulles du fil — envoyee et recue — doivent porter la mention',
        );
    }

    /**
     * Ce qui n'est PAS modifiable : l'auteur, la date, le contexte.
     *
     * Changer l'auteur d'un propos ne serait pas une moderation, ce serait un
     * faux. Le formulaire ne porte donc qu'un seul champ, et forcer les autres
     * dans la requete ne doit rien deplacer.
     */
    public function test_forcing_the_sender_or_the_date_moves_nothing(): void
    {
        $message = $this->messageDeBoucle();
        $autre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $dateOrigine = $message->created_at;

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-messages.update', $message), [
                'body' => 'Corrige',
                'sender_id' => $autre->id,
                'created_at' => now()->subYear()->toDateTimeString(),
                'loop_id' => Loop::factory()->create(['organization_id' => $this->organization->id])->id,
            ])
            ->assertRedirect();

        $message->refresh();

        $this->assertSame($this->auteur->id, $message->sender_id,
            'l\'auteur d\'un propos ne se reecrit pas');
        $this->assertSame(
            $dateOrigine->format('Y-m-d H:i:s'),
            $message->created_at->format('Y-m-d H:i:s'),
            'la date d\'envoi ne se reecrit pas',
        );
    }

    /** Un corps vide est refuse : pour retirer un message, on le supprime. */
    public function test_an_empty_body_is_refused_and_leaves_the_message_intact(): void
    {
        $message = $this->messageDeBoucle('Le corps d\'origine');

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-messages.update', $message), ['body' => '   '])
            ->assertSessionHasErrors('body');

        $message->refresh();

        $this->assertSame('Le corps d\'origine', $message->body);
        $this->assertNull($message->edited_at,
            'un refus ne doit pas laisser une trace de modification');
    }

    /**
     * Un texte IDENTIQUE n'inscrit aucune trace.
     *
     * « Modifie » doit vouloir dire quelque chose : ouvrir la page et
     * enregistrer sans rien changer marquerait a tort le message aux yeux des
     * membres.
     */
    public function test_saving_an_unchanged_body_records_no_edit(): void
    {
        $message = $this->messageDeBoucle('Le corps d\'origine');

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-messages.update', $message), ['body' => 'Le corps d\'origine'])
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertNull($message->fresh()->edited_at);
    }

    /** Un corps au-dela de la borne maison (5 000) est refuse. */
    public function test_a_body_beyond_the_house_limit_is_refused(): void
    {
        $message = $this->messageDeBoucle();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-messages.update', $message), ['body' => str_repeat('a', 5001)])
            ->assertSessionHasErrors('body');

        $this->assertNull($message->fresh()->edited_at);
    }

    /**
     * La capacite reste celle de l'administration PLATEFORME — les memes
     * bornes que la suppression voisine, sur le meme ecran.
     */
    public function test_the_capability_stays_with_the_platform_administration(): void
    {
        $message = $this->messageDeBoucle('Le corps d\'origine');
        $url = route('admin.loop-messages.update', $message);

        // Anonyme d'abord : `actingAs()` persiste pour le reste du test.
        $this->put($url, ['body' => 'Force'])->assertRedirect(route('login'));
        $this->get(route('admin.loop-messages.edit', $message))->assertRedirect(route('login'));

        $this->actingAs($this->auteur)->put($url, ['body' => 'Force'])->assertForbidden();
        $this->actingAs($this->auteur)->get(route('admin.loop-messages.edit', $message))->assertForbidden();

        $this->assertSame('Le corps d\'origine', $message->fresh()->body,
            'aucun acteur hors administration plateforme ne reecrit un message');
    }
}
