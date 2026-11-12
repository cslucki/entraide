<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1796 — `message-thread` ne doit rendre qu'UNE racine.
 *
 * ## Le defaut
 *
 * La vue rendait TROIS elements de premier niveau : le `<div>` principal
 * (lignes 1 a 490), `<x-conversation.selection-bar />` (TASK-1771, qui rend un
 * `<template x-teleport="body">`), et un `<script>` (TASK-008). Livewire
 * n'accepte qu'une racine et leve `MultipleRootElementsDetectedException`.
 *
 * Le `<script>` n'est PAS en cause : `getRootElementCount()` retire les balises
 * `<script>` et `<style>` avant de parser. La seconde racine comptee est donc la
 * barre de selection, et elle seule.
 *
 * ## Pourquoi ce defaut n'a jamais ete vu
 *
 * La garde ne tourne QUE si `app.debug` est vrai
 * (`SupportMultipleRootElementDetection::provide()`, ligne 12 :
 * `if (! config('app.debug')) return;`).
 *
 *  - `phpunit.xml` force `APP_DEBUG=false` -> AUCUN test ne pouvait le voir ;
 *  - PROD mesuree en lecture seule le 09/11/2026 : `app.debug = false` -> le
 *    defaut y est LATENT, la messagerie privee fonctionne ;
 *  - `.env.prodwork` porte `APP_DEBUG=true` -> le banc QA rendait 500 sur
 *    TOUTE conversation.
 *
 * ## Ce que ce fichier mesure, et pourquoi deux angles
 *
 * 1. Le SYMPTOME : avec `app.debug = true`, le composant doit se monter sans
 *    lever. C'est ce que Cyril a vu, et ce qui doit cesser.
 * 2. La CAUSE : le HTML rendu ne doit porter qu'une racine, mesuree avec le
 *    MEME algorithme que Livewire — pas une regle reecrite ici. Ce second
 *    angle tient meme si une version future de Livewire deplacait sa garde,
 *    et il reste vrai avec `app.debug = false`, ou le premier ne dit rien.
 */
class TASK1796MessageThreadSingleRootTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $acheteur;

    private User $vendeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1796',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->acheteur = User::factory()->create([
            'organization_id' => $this->organization->id,
            'points_balance' => 200,
        ]);

        $this->vendeur = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    /** Une DISCUSSION PRIVEE : ni service, ni demande, zero point. */
    private function conversationPrivee(): Transaction
    {
        return Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->acheteur->id,
            'seller_id' => $this->vendeur->id,
            'service_id' => null,
            'request_id' => null,
            'points_proposed' => 0,
            'status' => 'pending',
        ]);
    }

    /** Un ECHANGE d'entraide : un service et des points. */
    private function echangeDEntraide(): Transaction
    {
        $service = Service::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->vendeur->id,
            'title' => 'Relecture de devis',
        ]);

        return Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->acheteur->id,
            'seller_id' => $this->vendeur->id,
            'service_id' => $service->id,
            'request_id' => null,
            'points_proposed' => 20,
            'status' => 'pending',
        ]);
    }

    private function message(Transaction $transaction, string $body): Message
    {
        return Message::query()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $this->acheteur->id,
            'organization_id' => $this->organization->id,
            'body' => $body,
        ]);
    }

    /**
     * Le nombre de racines, compte comme Livewire le compte.
     *
     * L'algorithme est repris de `SupportMultipleRootElementDetection::
     * getRootElementCount()` — y compris le retrait prealable de `<script>` et
     * `<style>`, qui explique pourquoi le `<script>` final de la vue n'est pas
     * la racine fautive. Reecrire une regle approchante ferait mesurer autre
     * chose que ce que Livewire refuse.
     */
    private function compterLesRacines(string $html): int
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);

        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR);

        $body = $dom->getElementsByTagName('body')->item(0);
        $count = 0;

        foreach ($body?->childNodes ?? [] as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $count++;
            }
        }

        return $count;
    }

    // ---------------------------------------------------------------------
    // Le SYMPTOME — avec `app.debug = true`, comme le banc QA
    // ---------------------------------------------------------------------

    /**
     * `phpunit.xml` force `APP_DEBUG=false` : sans ce `config()`, le test
     * passerait sur le code FAUTIF et ne mesurerait rien. C'est la ligne qui
     * fait la difference entre un test et un faux vert.
     */
    public function test_une_discussion_privee_se_monte_avec_le_debug_actif(): void
    {
        config(['app.debug' => true]);
        $this->assertTrue(config('app.debug'), 'precondition : la garde Livewire ne tourne qu\'en debug');

        $transaction = $this->conversationPrivee();
        $this->message($transaction, 'Bonjour');

        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertOk();
    }

    public function test_un_echange_d_entraide_se_monte_avec_le_debug_actif(): void
    {
        config(['app.debug' => true]);

        $transaction = $this->echangeDEntraide();
        $this->message($transaction, 'Bonjour');

        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertOk();
    }

    /** La page ENTIERE, pas seulement le composant isole. */
    public function test_la_page_de_la_conversation_ne_rend_pas_500_avec_le_debug_actif(): void
    {
        config(['app.debug' => true]);

        $transaction = $this->conversationPrivee();
        $this->message($transaction, 'Bonjour');

        $this->actingAs($this->acheteur)
            ->get(route('organization.messages.show', [
                'organization' => $this->organization->slug,
                'transaction' => $transaction->id,
            ]))
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // La CAUSE — vraie avec ou sans debug
    // ---------------------------------------------------------------------

    public function test_le_composant_ne_rend_qu_une_seule_racine(): void
    {
        $transaction = $this->conversationPrivee();
        $this->message($transaction, 'Bonjour');

        $html = Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->html();

        $this->assertSame(
            1,
            $this->compterLesRacines($html),
            'Livewire n\'accepte qu\'une racine ; le compte est fait avec SON algorithme',
        );
    }

    public function test_un_echange_d_entraide_aussi_ne_rend_qu_une_racine(): void
    {
        $transaction = $this->echangeDEntraide();
        $this->message($transaction, 'Bonjour');

        $html = Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->html();

        $this->assertSame(1, $this->compterLesRacines($html));
    }

    // ---------------------------------------------------------------------
    // Ce que la correction ne doit PAS emporter
    // ---------------------------------------------------------------------

    /**
     * La barre de selection reste RENDUE.
     *
     * Elle est la seconde racine : la supprimer reglerait le 500 et casserait
     * le mode selection de TASK-1771. La correction la DEPLACE, elle ne la
     * retire pas — et ce test interdit la mauvaise solution.
     */
    public function test_la_barre_de_selection_est_toujours_rendue(): void
    {
        $transaction = $this->conversationPrivee();
        $this->message($transaction, 'Bonjour');

        $html = Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->html();

        // Le teleport Alpine est sa signature : la barre vit dans un
        // `<template x-teleport="body">`, et c'est lui qui la porte vers le haut
        // de l'ecran quel que soit son emplacement dans la source.
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('bp-selection-action', $html);
    }

    /** Le script du badge « non lus » reste rendu, lui aussi. */
    public function test_le_script_du_badge_est_toujours_rendu(): void
    {
        $transaction = $this->conversationPrivee();
        $this->message($transaction, 'Bonjour');

        $html = Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->html();

        $this->assertStringContainsString('syncUnreadBadge', $html);
        $this->assertStringContainsString('livewire:updated', $html);
    }

    /** Et le fil garde ce qu'il doit montrer : le message. */
    public function test_le_fil_affiche_toujours_ses_messages(): void
    {
        $transaction = $this->conversationPrivee();
        $this->message($transaction, 'Un message reconnaissable');

        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertSee('Un message reconnaissable');
    }
}
