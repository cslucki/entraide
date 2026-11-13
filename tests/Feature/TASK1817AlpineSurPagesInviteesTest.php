<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1817 — Alpine ne démarrait pas sur les pages invitées.
 *
 * ## Le défaut, mesuré en navigateur avant tout code
 *
 * `guest.blade.php` charge `app.js`, qui **utilise** `window.Alpine` (ses
 * stores, `registerAlpineStores()`) mais ne l'**importe** jamais. Alpine arrive
 * en réalité par `livewire.min.js`, que Livewire auto-injecte sur toute page
 * portant un de ses composants. Les pages invitées n'en portent aucun.
 *
 * | page | `window.Alpine` | `window.Livewire` | stores | `[x-data]` |
 * |---|---|---|---|---|
 * | `/login`, `/register` | **undefined** | **undefined** | false | **2** |
 * | `/dashboard`, `/membres` | 3.15.11 | chargé | true | — |
 *
 * La panne était **silencieuse** : zéro erreur console, puisque Alpine ne
 * tournait pas et ne pouvait donc pas échouer. Symptôme visible : le panneau
 * `x-cloak` restait `display:none` **à jamais**, et la bascule clair/sombre —
 * seule interaction offerte à un visiteur — ne répondait pas.
 *
 * ## Pourquoi les scripts Livewire plutôt qu'un Alpine autonome
 *
 * Les deux évitent un paquet neuf : `alpinejs` est déjà une devDependency.
 * Mesure du coût : Livewire **78,7 Ko gzip**, Alpine seul **16,7 Ko gzip**.
 *
 * Le choix ne s'est pas fait sur le poids, mais sur le **risque** : un Alpine
 * autonome sur ce layout se télescoperait avec celui de Livewire le jour où une
 * page invitée porterait un composant Livewire — deux instances, double
 * démarrage, panne silencieuse d'un autre genre. En passant par Livewire, il
 * n'y a **par construction** qu'une seule instance, celle que tous les autres
 * layouts utilisent déjà. C'est aussi le précédent du dépôt : `admin` et
 * `org-admin` posent cette même directive au même endroit.
 *
 * ## Mesure annexe : la directive est idempotente
 *
 * Posée en plus sur `app.blade.php`, dont les pages portent déjà des composants
 * Livewire, `livewire.min.js` reste servi **une seule fois** (le HTML grossit de
 * 263 o, le blob de configuration, et rien d'autre). Une directive en double est
 * donc du bruit à corriger, pas un double démarrage d'Alpine. C'est pourquoi
 * aucun test ici ne compte les occurrences du script dans le HTML rendu : cette
 * garde-là ne pourrait pas rougir, et ne prouverait rien.
 */
class TASK1817AlpineSurPagesInviteesTest extends TestCase
{
    use RefreshDatabase;

    private function layout(string $nom): string
    {
        return file_get_contents(resource_path("views/layouts/{$nom}.blade.php"));
    }

    // ────────────────────────────────────────────────────────────────────────
    // La correction
    // ────────────────────────────────────────────────────────────────────────

    public function test_le_layout_invite_charge_les_scripts_qui_portent_alpine(): void
    {
        $this->assertSame(
            1,
            preg_match_all('/@livewireScripts/', $this->layout('guest')),
            'Le layout invite doit poser la directive de scripts Livewire EXACTEMENT une fois : '
            .'zero laisse Alpine absent et toutes les directives `x-` mortes. (Deux ne casserait rien — '
            .'la directive est idempotente, mesure ci-dessus — mais serait du bruit a corriger.)'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pagesInvitees')]
    public function test_une_page_invitee_sert_le_script_qui_porte_alpine(string $url): void
    {
        $reponse = $this->get($url);

        $reponse->assertOk();
        $reponse->assertSee('livewire.min.js', escape: false);
    }

    /** @return array<string, array{0: string}> */
    public static function pagesInvitees(): array
    {
        return [
            'connexion' => ['/login'],
            'inscription' => ['/register'],
        ];
    }

    /**
     * Les directives Alpine du layout invité ne sont pas décoratives : si l'une
     * d'elles disparaissait, le correctif perdrait son objet.
     */
    public function test_les_pages_invitees_portent_bien_des_directives_alpine(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertGreaterThan(
            0,
            substr_count($html, 'x-data'),
            'La page de connexion ne porte plus aucune portee Alpine : ce correctif n a plus d objet.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Aucun autre layout touché, aucune instance en double
    // ────────────────────────────────────────────────────────────────────────

    public function test_les_layouts_admin_gardent_leur_propre_directive(): void
    {
        foreach (['admin', 'org-admin'] as $nom) {
            $this->assertSame(
                1,
                preg_match_all('/@livewireScripts/', $this->layout($nom)),
                "Le layout {$nom} doit garder SA directive, une seule fois : cette TASK ne le touche pas."
            );
        }
    }

    /**
     * `app.blade.php` n'en pose AUCUNE, et c'est volontaire : ses pages portent
     * des composants Livewire, qui injectent les scripts d'eux-memes. Lui en
     * ajouter une serait redondant — sans danger, mesure ci-dessus, mais du bruit.
     */
    public function test_le_layout_app_reste_sans_directive_explicite(): void
    {
        $this->assertSame(
            0,
            preg_match_all('/@livewireScripts/', $this->layout('app')),
            'Le layout `app` n a pas a poser la directive : ses pages portent des composants Livewire, '
            .'qui injectent les scripts eux-memes.'
        );
    }
}
