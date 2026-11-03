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

    public function test_le_script_du_premier_lancement_est_EMIS_sur_la_page(): void
    {
        // LE défaut de la recette du 03/11, et la garde qui manquait.
        //
        // Le composant poussait son `@vite` dans `@push('scripts')` — mais il
        // est inclus APRÈS la ligne `@stack('scripts')` du layout. La pile
        // était déjà rendue : le script n'était émis sur AUCUNE page, le bloc
        // restait `display:none` à vie, et aucune des quatre conditions du
        // premier lancement n'était même évaluée. Tous les tests passaient :
        // ils mesuraient le BALISAGE du bloc, jamais l'émission du SCRIPT qui
        // le révèle.
        //
        // On mesure donc l'implication, dans les deux sens : bloc rendu ⇒
        // script émis ; bloc absent ⇒ script absent. Le motif cherché est le
        // nom d'entrée que Vite conserve dans le nom du fichier compilé.
        $reponse = $this->actingAs($this->membre)->get('/boucles')->assertOk();

        if (PwaInstallContext::annonceLesNotifications()) {
            $reponse->assertSee('data-push-first-run', false);
            $reponse->assertSee('push-first-run-', false);

            return;
        }

        $reponse->assertDontSee('push-first-run-', false);
    }

    public function test_chaque_etat_de_permission_a_son_corps_de_texte(): void
    {
        // Demande Cyril 03/11, points 3 à 5 : `granted` propose une
        // activation simple, `denied` explique la réactivation. Les deux
        // corps voyagent en attributs de données — le script ne fabrique
        // aucun texte — donc leur présence se mesure sur la page.
        if (! PwaInstallContext::annonceLesNotifications()) {
            $this->markTestSkipped('canal fermé sur cette branche : le bloc n’est pas rendu');
        }

        $this->actingAs($this->membre)->get('/boucles')->assertOk()
            ->assertSee('data-push-text-granted-body', false)
            ->assertSee('data-push-text-denied-body', false)
            ->assertSee('data-push-text-understood', false);
    }

    public function test_tout_libelle_APPELE_par_le_gabarit_existe_dans_les_DEUX_locales(): void
    {
        // La v1 a payé pour la liste recopiée : la vue lisait `push.*`, les
        // clés vivaient dans `pwa.*`, et ce test — qui vérifiait sa propre
        // liste `pwa.*`, correcte — restait vert pendant que l'écran aurait
        // montré des clés brutes. On dérive donc les clés DU GABARIT : ce
        // que la vue appelle est exactement ce qu'on exige des catalogues.
        $html = (string) file_get_contents(resource_path('views/components/push-first-run.blade.php'));

        preg_match_all("/__\('([a-z]+\.[a-z0-9_]+)'\)/", $html, $appels);

        $this->assertNotSame([], $appels[1], 'aucun appel __() trouvé : le motif de recherche est à revoir');

        foreach (['fr', 'en'] as $locale) {
            foreach (array_unique($appels[1]) as $cle) {
                $this->assertTrue(
                    \Illuminate\Support\Facades\Lang::has($cle, $locale),
                    "[{$locale}] le gabarit appelle {$cle}, absente du catalogue : l’écran montrerait la clé brute",
                );
            }
        }
    }

    public function test_la_reinitialisation_du_report_n_existe_qu_en_LOCAL(): void
    {
        // Point 6 de la demande du 03/11 : un moyen sûr de revoir la
        // proposition, pour la recette UNIQUEMENT. Même garde serveur que le
        // panneau de diagnostic qui l'héberge : hors local, le bouton
        // n'existe pas dans le HTML — rien à désactiver côté navigateur.
        $this->actingAs($this->membre)->get('/notifications/preferences')->assertOk()
            ->assertDontSee('data-push-first-run-reset', false);

        app()->detectEnvironment(fn () => 'local');

        $this->actingAs($this->membre)->get('/notifications/preferences')->assertOk()
            ->assertSee('data-push-first-run-reset', false);
    }

    public function test_toute_entree_vite_appelee_par_une_vue_est_DECLAREE(): void
    {
        // Défaut trouvé le 03/11 par l'intégration de recette T1744+T1745,
        // et invisible autrement.
        //
        // `push-first-run.js` existait, était importé par `@vite()`, et
        // n'était PAS dans les entrées de `vite.config.js`. Une entrée non
        // déclarée est absente du manifeste, et `@vite()` lève alors une
        // `ViteException` **en pleine page** : 500 sur toute surface
        // authentifiée. `npm run build` ne dit rien — il construit ce qu'on
        // lui déclare, pas ce qu'on utilise.
        //
        // Sur la branche T1745 seule, le bloc n'est jamais rendu faute de
        // canal au catalogue : la page ne touchait donc jamais la ligne
        // fautive. Il a fallu les deux branches ensemble pour la révéler.
        //
        // Cette garde ferme le trou pour TOUTES les entrées, pas seulement
        // celle-ci : c'est la classe de défaut qui compte.
        $config = file_get_contents(base_path('vite.config.js'));

        $vues = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        $appelees = [];

        foreach ($vues as $fichier) {
            if (! str_ends_with((string) $fichier, '.blade.php')) {
                continue;
            }

            preg_match_all(
                "/@vite\(\s*'([^']+\.(?:js|css))'\s*\)/",
                (string) file_get_contents((string) $fichier),
                $trouvees
            );

            foreach ($trouvees[1] as $entree) {
                $appelees[$entree] = (string) $fichier;
            }
        }

        $this->assertNotSame([], $appelees, 'aucun @vite() trouvé : le motif de recherche est à revoir');

        foreach ($appelees as $entree => $fichier) {
            $this->assertStringContainsString(
                "'".$entree."'",
                $config,
                "[{$entree}] est appelée par ".basename($fichier)." mais n'est pas déclarée dans vite.config.js : "
                .'la page lèvera une ViteException à l\'exécution.',
            );
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
