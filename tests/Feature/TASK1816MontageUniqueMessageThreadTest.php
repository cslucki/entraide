<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Locked;
use Tests\TestCase;

/**
 * TASK-1816 — un seul `MessageThread` par conversation.
 *
 * ## Le défaut, mesuré avant tout code
 *
 * `messages/index.blade.php` montait le composant DEUX fois : une fois dans le
 * conteneur `md:hidden` (téléphone), une fois dans `hidden md:flex` (grand
 * écran). **Le CSS n'en cachait qu'un ; le serveur rendait les deux.**
 *
 * | mesure, 13/11 | avant | après |
 * |---|---|---|
 * | composants montés | 2 | **1** |
 * | directives `wire:poll` | 2 | **1** |
 * | poids de la page | 348 636 o | **221 554 o** |
 * | snapshot Livewire | 1 962 o | **981 o** |
 * | requêtes SQL de la page | 53 | **37** |
 *
 * ## Ce que la mesure navigateur a CORRIGÉ de la théorie
 *
 * La cible annoncée était « 40 → 20 requêtes HTTP/minute ». **Faux.** Livewire
 * groupe les composants d'un même cycle dans **une seule** requête : avant
 * comme après, le navigateur émet **20 requêtes/minute**. Ce qui change, c'est
 * leur CHARGE — mesurée dans le corps des requêtes :
 *
 * | | avant | après |
 * |---|---|---|
 * | composants par requête | **2** | **1** |
 * | rendus de composant | **40/min** | **20/min** |
 *
 * ## Ce que ce fichier garde
 *
 * Des invariants de STRUCTURE. La preuve d'isolation inter-tenant, elle, vit
 * déjà dans `TASK1691LivewireTenantIdentityTest` : c'est elle qui forge un
 * `updates` Livewire et prouve qu'il ne lit ni n'écrit la conversation d'une
 * autre Organization. Ce fichier garde le socle sur lequel elle repose —
 * `#[Locked]` — pour qu'il ne disparaisse pas en silence.
 */
class TASK1816MontageUniqueMessageThreadTest extends TestCase
{
    use RefreshDatabase;

    private function vue(): string
    {
        return file_get_contents(resource_path('views/messages/index.blade.php'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le montage unique
    // ────────────────────────────────────────────────────────────────────────

    public function test_la_vue_ne_monte_le_fil_qu_une_seule_fois(): void
    {
        // On compte la DIRECTIVE, pas le mot : un commentaire qui nomme le
        // composant ne doit pas peser dans le compte (piège déjà payé en T1813,
        // où un chemin cité dans un commentaire avait fait rougir une suite).
        $montages = preg_match_all("/@livewire\(\s*'message-thread'/", $this->vue());

        $this->assertSame(
            1,
            $montages,
            "La vue monte le fil {$montages} fois. Deux montages signifient deux composants rendus par le "
            .'serveur, deux snapshots, et deux rendus par cycle de sondage — le CSS n en cache qu un.'
        );
    }

    public function test_le_panneau_parait_sur_telephone_quand_une_conversation_est_ouverte(): void
    {
        $vue = $this->vue();

        // Le panneau unique doit basculer : caché sur téléphone SANS conversation
        // (c'est la liste qui occupe l'écran), visible AVEC.
        $this->assertMatchesRegularExpression(
            '/@isset\(\$transaction\)\s*flex\s*@else\s*hidden\s*@endisset\s+md:flex/',
            $vue,
            'Le panneau ne porte plus la bascule responsive : sur telephone il resterait cache meme '
            .'quand une conversation est ouverte, ou masquerait la liste quand aucune ne l est.'
        );

        // La LISTE mobile porte legitimement `md:hidden flex-1 flex flex-col
        // min-h-0`, suivie de `@isset($transaction) hidden @endisset`. L ancien
        // PANNEAU, lui, fermait son attribut juste apres. C est cette forme-la,
        // et elle seule, qui ne doit plus exister.
        $this->assertStringNotContainsString(
            'md:hidden flex-1 flex flex-col min-h-0">',
            $vue,
            "L ancien panneau MOBILE est revenu : c est le double montage qui recommence."
        );

        // Et un seul conteneur de panneau, quoi qu il arrive.
        $this->assertSame(
            1,
            preg_match_all('/md:flex flex-1 flex-col min-h-0/', $vue),
            'Il doit exister exactement UN conteneur de panneau de conversation.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le fil garde son sondage — hors périmètre, mais il ne doit pas disparaître
    // ────────────────────────────────────────────────────────────────────────

    public function test_le_fil_sonde_toujours_toutes_les_trois_secondes(): void
    {
        $fil = file_get_contents(resource_path('views/livewire/message-thread.blade.php'));

        $this->assertSame(
            1,
            preg_match_all('/wire:poll[.\w]*/', $fil),
            'Le fil doit porter exactement UNE directive de sondage.'
        );

        $this->assertStringContainsString(
            'wire:poll.3000ms',
            $fil,
            'La frequence de sondage est hors perimetre de cette TASK : elle doit rester a 3 secondes.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // T1691 — le socle de l'isolation inter-tenant
    // ────────────────────────────────────────────────────────────────────────

    /**
     * `#[Locked]` est ce qui empêche le client d'écrire les propriétés qui
     * DÉCIDENT du tenant. Sans lui, un `updates` forgé déplaçait la lecture
     * **et l'écriture** (`markRead()`) vers une autre Organization — le P0
     * TASK-1691. La preuve dynamique vit dans sa suite ; ici on garde le socle.
     */
    public function test_les_proprietes_qui_decident_du_tenant_restent_verrouillees(): void
    {
        foreach (['transactionId', 'organizationId', 'organizationSlug'] as $nom) {
            $propriete = new \ReflectionProperty(MessageThread::class, $nom);

            $this->assertNotEmpty(
                $propriete->getAttributes(Locked::class),
                "`MessageThread::\${$nom}` a perdu son attribut #[Locked]. C est la garde du P0 TASK-1691 : "
                .'sans elle, un `updates` Livewire forge deplace le tenant, et `markRead()` ECRIT dans une '
                .'autre Organization.'
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le composant rend toujours, et dans son tenant
    // ────────────────────────────────────────────────────────────────────────

    public function test_le_fil_rend_la_conversation_de_son_propre_tenant(): void
    {
        $organization = Organization::factory()->create(['slug' => 't1816', 'is_active' => true]);
        $acheteur = User::factory()->create(['organization_id' => $organization->id]);
        $vendeur = User::factory()->create(['organization_id' => $organization->id]);

        $transaction = Transaction::factory()->create([
            'organization_id' => $organization->id,
            'buyer_id' => $acheteur->id,
            'seller_id' => $vendeur->id,
        ]);

        app()->instance('current_organization', $organization);

        \Livewire\Livewire::actingAs($acheteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertOk()
            ->assertSet('transactionId', (string) $transaction->id)
            ->assertSet('organizationId', (string) $organization->id);
    }
}
