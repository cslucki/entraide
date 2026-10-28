<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\VerifyEmailWithTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * TASK-1694, bug J — renvoyer le lien de confirmation depuis `/admin/users`.
 *
 * ## Pourquoi ce geste, à côté de « Valider l'email »
 *
 * Le geste voisin **contourne** la preuve de possession d'adresse : c'est un
 * dépannage. Celui-ci la **redemande**. C'est donc celui à tenter d'abord, et
 * il est posé **avant** l'autre dans la colonne : l'ordre de lecture suggère le
 * geste le moins lourd en premier.
 *
 * ## La durée repart de zéro
 *
 * Demande de Cyril. Ce n'est pas un effet de bord : un lien signé porte une
 * expiration calculée **au moment de l'envoi**. On ne prolonge pas un lien déjà
 * émis — on en émet un neuf.
 */
class TASK1694AdminResendVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $nonVerifie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694j',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->nonVerifie = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => null,
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function url(?User $cible = null): string
    {
        return route('admin.users.resend-verification', $cible ?? $this->nonVerifie);
    }

    /** LE geste demandé : le lien repart. */
    public function test_the_superadmin_can_resend_the_link(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin)
            ->post($this->url())
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($this->nonVerifie, VerifyEmailWithTemplate::class);
    }

    /**
     * La durée repart de zéro.
     *
     * Mesurée sur l'URL réellement construite : son `expires` doit viser
     * ~24 h **à partir de maintenant**, pas la fin d'un lien antérieur.
     */
    public function test_the_validity_restarts_from_now(): void
    {
        $lien = (new VerifyEmailWithTemplate)->toMail($this->nonVerifie);

        // TASK-1720 — on extrait l'URL, pas le document qui la contient.
        //
        // L'ancienne forme retenait la CHAINE de `viewData` contenant
        // `expires=`. Or sur le chemin GABARIT — celui que prend reellement
        // cet envoi, le gabarit `confirmation-email-suite-inscription` etant
        // pose par migration — cette chaine est le corps HTML ENTIER. Le
        // `parse_url()` qui suit s'appliquait donc a tout le document, et son
        // `PHP_URL_QUERY` demarrait au PREMIER `?` rencontre, d'ou qu'il
        // vienne : un `?` dans un nom, une raison sociale ou une ville de
        // fabrique suffisait a faire disparaitre `expires`.
        //
        // Mesure : avec un nom contenant « ? », la cle devient ABSENTE —
        // l'assertion tombait sans que rien du produit ne soit en cause. Le
        // gate SQLite l'a vu une fois, et la gouvernance de
        // `sqlite-known-failures.txt` est explicite : « les flakies se
        // corrigent, ils ne s'inscrivent pas ».
        //
        // L'intention de la garde est INCHANGEE : le lien signe doit expirer
        // 24 h apres CET envoi.
        $url = $lien->actionUrl;

        if ($url === null) {
            foreach ((array) $lien->viewData as $valeur) {
                if (is_string($valeur) && preg_match('#href="([^"]*expires=[^"]*)"#', $valeur, $trouve)) {
                    $url = html_entity_decode($trouve[1]);

                    break;
                }
            }
        }

        $this->assertNotNull($url, 'le message doit porter une url signee');

        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('expires', $query);
        $this->assertEqualsWithDelta(
            now()->addMinutes((int) config('auth.verification.expire'))->timestamp,
            (int) $query['expires'],
            60,
            'le lien doit expirer 24 h apres CET envoi',
        );
    }

    /**
     * Rien sur un compte déjà vérifié — et la garde est au SERVEUR.
     *
     * Le bouton n'apparaît pas, mais une garde de vue n'est pas une
     * protection : l'adresse resterait atteignable.
     */
    public function test_nothing_is_sent_to_an_already_verified_account(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin)
            ->post($this->url($this->superAdmin))
            ->assertRedirect()
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    /** Le geste reste réservé au SuperAdmin plateforme. */
    public function test_a_simple_member_cannot_resend(): void
    {
        Notification::fake();

        $simple = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($simple)->post($this->url())->assertForbidden();

        Notification::assertNothingSent();
    }

    /** Le bouton est là, et seulement sur les comptes non vérifiés. */
    public function test_the_button_shows_only_where_it_has_an_effect(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-user-resend-verification="'.$this->nonVerifie->id.'"',
            $html,
        );
        $this->assertStringNotContainsString(
            'data-user-resend-verification="'.$this->superAdmin->id.'"',
            $html,
            'un geste sans effet est un piege',
        );
    }

    /**
     * Et il est posé AVANT « Valider l'email ».
     *
     * L'ordre de lecture suggère le geste le moins lourd en premier :
     * redemander la preuve avant de la contourner.
     */
    public function test_resending_comes_before_bypassing(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $renvoyer = strpos($html, 'data-user-resend-verification="'.$this->nonVerifie->id.'"');
        $valider = strpos($html, 'data-user-verify-email="'.$this->nonVerifie->id.'"');

        $this->assertNotFalse($renvoyer);
        $this->assertNotFalse($valider);
        $this->assertLessThan($valider, $renvoyer);
    }
}
