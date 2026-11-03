<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Pwa\PwaInstallContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1745 — « Activer les notifications » au premier lancement installé.
 *
 * ## Le partage des rôles entre PHP et le navigateur
 *
 * Quatre conditions gouvernent l'apparition de ce bloc. Deux sont posées
 * côté SERVEUR et se mesurent ici : la capacité doit exister, et la personne
 * doit être connectée. Les deux autres — application lancée installée,
 * appareil pas déjà abonné — ne se lisent que dans un navigateur, et sont
 * mesurées dans `tests/e2e/task-1745-first-run.spec.js`.
 *
 * Ce fichier le dit plutôt que de laisser croire qu'il prouve le parcours
 * entier.
 */
class TASK1745PushFirstRunTest extends TestCase
{
    use RefreshDatabase;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.public_key', str_repeat('B', 87));

        $organization = Organization::factory()->create(['is_active' => true]);
        $this->membre = User::factory()->create(['organization_id' => $organization->id]);
    }

    /** Le catalogue autorise-t-il le Push sur cette branche ? */
    private function canalOuvert(): bool
    {
        foreach (NotificationCatalogue::keys() as $cle) {
            if (NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH)) {
                return true;
            }
        }

        return false;
    }

    public function test_un_visiteur_ANONYME_ne_voit_jamais_la_proposition(): void
    {
        // La première des deux gardes serveur. Proposer d'activer des
        // notifications à quelqu'un qui n'a pas de compte n'a pas de
        // destinataire : l'abonnement se rattache à une personne.
        $this->get('/')->assertOk()->assertDontSee('data-push-first-run', false);
    }

    public function test_la_proposition_suit_la_REALITE_du_catalogue(): void
    {
        // CDC 3.2 : ne rien promettre qui ne soit pas livré. Sur une branche
        // où aucune clé n'autorise `web_push`, proposer d'activer des
        // notifications serait proposer le vide — le bloc ne doit donc même
        // pas être rendu.
        //
        // Ce test vaut dans les deux sens, et c'est voulu : T1744 et T1745
        // sont deux branches ouvertes en ce moment, et la réponse diffère de
        // l'une à l'autre.
        $this->assertSame(
            $this->canalOuvert(),
            PwaInstallContext::annonceLesNotifications(),
            "la capacité annoncée doit suivre le catalogue, pas un drapeau",
        );

        $reponse = $this->actingAs($this->membre)->get('/boucles')->assertOk();

        if ($this->canalOuvert()) {
            $reponse->assertSee('data-push-first-run', false);

            return;
        }

        $reponse->assertDontSee('data-push-first-run', false);
    }

    public function test_le_push_eteint_ferme_la_proposition(): void
    {
        // L'interrupteur d'exploitation de T1739 doit valoir ici aussi : on
        // retire le Push de l'interface sans toucher au catalogue ni aux
        // clefs.
        config()->set('webpush.enabled', false);

        $this->assertFalse(PwaInstallContext::annonceLesNotifications());

        $this->actingAs($this->membre)
            ->get('/boucles')
            ->assertOk()
            ->assertDontSee('data-push-first-run', false);
    }

    public function test_les_deux_libelles_existent_dans_les_DEUX_locales(): void
    {
        // « Activer les notifications » ET « Plus tard ». Le second n'est pas
        // décoratif : un refus doit être une sortie VISIBLE, sinon la
        // proposition devient un mur.
        foreach (['fr', 'en'] as $locale) {
            foreach (['first_run_title', 'first_run_body', 'first_run_accept', 'first_run_later', 'first_run_failed'] as $cle) {
                $this->assertTrue(
                    \Illuminate\Support\Facades\Lang::has("pwa.{$cle}", $locale),
                    "[{$locale}] libellé manquant : pwa.{$cle}",
                );
            }
        }
    }

    public function test_la_proposition_ne_bloque_PAS_la_navigation(): void
    {
        // Point explicite de la demande. Le bloc est une barre `fixed` en
        // bas, jamais une superposition qui couvrirait la page : rien ne
        // doit devenir inatteignable pendant qu'il est affiché.
        //
        // Mesuré sur le balisage, parce que c'est là que la décision vit :
        // aucune classe d'overlay plein écran, aucun piège de focus.
        $html = file_get_contents(resource_path('views/components/push-first-run.blade.php'));

        $this->assertStringContainsString('fixed inset-x-0 bottom-0', $html);
        $this->assertStringNotContainsString('inset-0', $html, 'une superposition plein écran bloquerait la navigation');
        $this->assertStringNotContainsString('role="dialog"', $html);
    }
}
