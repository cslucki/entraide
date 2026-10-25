<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1703 — la Messagerie prend la forme d'un espace de Boucle.
 *
 * Cyril : « sur le module Messagerie, il faudrait que l'ecran ressemble plus a
 * celui des chatloops, ceci sur la version desktop. La, la fenetre qui contient
 * les messages est trop etroite en comparaison de celle d'une boucle. […] que
 * la fenetre soit plus large, une top navigation bar comme sur la version
 * mobile, et sur le rail gauche l'icone Messagerie n'est pas highlightee comme
 * les autres. »
 *
 * ## Trois demandes, trois causes distinctes
 *
 * 1. **La largeur et la hauteur.** `x-page-container` centre le contenu dans
 *    `max-w-7xl` avec ses marges ; `loops.show` s'en extrait explicitement
 *    (`.loops-show-wrapper { max-width: none }`). Et la hauteur soustrayait
 *    `5rem` pour une barre d'en-tete que cette page ne pose PAS — exactement ce
 *    que le commentaire de `loops.show` decrit : « otherwise ~5rem stays
 *    empty ».
 *
 * 2. **La barre haute.** Sur telephone `x-mobile-topbar` en pose une ; sur
 *    desktop il n'y avait rien au-dessus des deux colonnes.
 *
 * 3. **Le rail.** `'active' => ['messages']` — la SEULE entree du rail a ne pas
 *    nommer sa variante `organization.`. Sous `/org/{slug}/messages` la route
 *    courante est `organization.messages.index`, qui n'apparie pas « messages ».
 *    La barre basse mobile, elle, prefixe automatiquement : d'ou une messagerie
 *    surlignee sur telephone et jamais sur desktop.
 *
 * ## Ce que ce fichier NE mesure pas
 *
 * L'apparence. Les largeurs et les hauteurs sont du CSS, et une assertion sur
 * une valeur en `rem` ne prouverait que sa propre recopie — elle rougirait au
 * premier ajustement visuel, qui est precisement ce qu'on veut pouvoir faire.
 * Ce qui est fige ici, c'est ce qui a une consequence VERIFIABLE : la regle
 * d'extraction existe, la barre est servie au bon endroit, et le rail s'allume.
 */
class TASK1703MessagerieDesignTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1703',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    private function messagerie(): string
    {
        app()->forgetInstance('current_organization');

        return $this->actingAs($this->membre)
            ->get('/org/'.$this->organization->slug.'/messages')
            ->assertOk()
            ->getContent();
    }

    // ── 1. La largeur et la hauteur ─────────────────────────────────────────

    /**
     * La page s'extrait du conteneur centre, comme l'espace d'une Boucle.
     *
     * La mesure porte sur la REGLE, pas sur une valeur : on verifie que la
     * classe du conteneur est bien posee et que la feuille la neutralise sur
     * desktop. Sans la classe, la regle ne designerait rien ; sans la regle, la
     * classe ne ferait rien. Les deux sont necessaires, aucune ne suffit.
     */
    public function test_la_fenetre_s_extrait_du_conteneur_centre_sur_desktop(): void
    {
        $html = $this->messagerie();

        $this->assertStringContainsString('messages-index-wrapper', $html, 'Le conteneur de page ne porte plus sa classe.');

        $this->assertSame(
            1,
            preg_match('/\.messages-index-wrapper\s*\{[^}]*max-width:\s*none/s', $html),
            'La fenetre reste enfermee dans `max-w-7xl` : elle restera plus etroite que celle d\'une Boucle.'
        );
    }

    /**
     * La hauteur ne soustrait plus une barre qui n'existe pas.
     *
     * Cette page ne pose aucun `$header`. Le `calc(100vh - 5rem)` d'avant
     * laissait donc cinq rem de vide sous le composeur — le defaut que
     * `loops.show` avait deja nomme pour lui-meme.
     */
    public function test_la_hauteur_ne_soustrait_plus_une_barre_absente(): void
    {
        $html = $this->messagerie();

        // La hauteur est portee par le WRAPPER et la fenetre la remplit.
        // Donner `100dvh` a la fenetre alors qu'une barre haute la precede
        // ferait depasser la page de la hauteur de cette barre — le defaut que
        // ce correctif a failli introduire.
        $this->assertSame(
            1,
            preg_match('/\.messages-index-wrapper\s*\{[^}]*height:\s*100dvh/s', $html),
            'La page ne prend pas toute la hauteur de l\'ecran.'
        );

        $this->assertSame(
            1,
            preg_match('/\.messages-index-container\s*\{[^}]*flex:\s*1 1 auto/s', $html),
            'La fenetre ne remplit pas la hauteur restante sous la barre haute.'
        );

        $this->assertSame(
            0,
            preg_match('/\.messages-index-container\s*\{[^}]*height:\s*100dvh/s', $html),
            'La fenetre impose sa propre hauteur d\'ecran : avec la barre au-dessus, la page depasserait.'
        );

        $this->assertSame(
            0,
            preg_match('/\.messages-index-container\s*\{[^}]*calc\(100vh\s*-\s*5rem\)/s', $html),
            'La soustraction de la barre d\'en-tete absente est revenue.'
        );
    }

    // ── 2. La barre haute ───────────────────────────────────────────────────

    public function test_la_barre_haute_est_servie_et_porte_le_titre_et_le_compte(): void
    {
        $html = $this->messagerie();

        // Le marqueur est borne par `[\s>]`. Sans cela, `data-messages-topbar`
        // s'apparie aussi a `data-messages-topbar-absent` — un sabotage qui
        // renommait l'attribut est reste VERT, et c'est lui qui l'a montre.
        $this->assertSame(1, preg_match('/<div[^>]*\sdata-messages-topbar[\s>]/', $html), 'La barre haute n\'est pas servie.');

        $this->assertSame(1, preg_match('/\sdata-messages-topbar[\s>](.*?)<\/div>\s*<div class="messages-index-container/s', $html, $barre));

        $this->assertStringContainsString(e(__('messages.title')), $barre[1], 'La barre haute ne porte pas le titre.');
        $this->assertStringContainsString(e(trans_choice('messages.conversation_count', 0, ['count' => 0])), $barre[1]);
    }

    /**
     * Elle est reservee au DESKTOP.
     *
     * Sur telephone, `x-mobile-topbar` en pose deja une : deux barres
     * l'une sous l'autre seraient le defaut que TASK-1693 bug AQ a corrige
     * ailleurs — « deux fleches a trois centimetres l'une de l'autre ».
     */
    public function test_la_barre_haute_ne_fait_pas_doublon_sur_telephone(): void
    {
        $html = $this->messagerie();

        $this->assertSame(
            1,
            preg_match('/<div class="messages-index-topbar hidden md:flex/', $html),
            'La barre haute s\'affiche aussi sur telephone, ou l\'application en pose deja une.'
        );
    }

    // ── 4. Le composeur ne bouge plus sur telephone ─────────────────────────

    /**
     * Le document ne defile plus, et la hauteur ne varie plus.
     *
     * Cyril, capture a l'appui : « il faut que le composer qui est en bas soit
     * sticky, car si on fait un geste vers le haut, le composer se deplace. »
     *
     * C'est mot pour mot le defaut que TASK-1684 MICRO-01 avait nomme sur
     * l'espace d'une Boucle : `100dvh` SUIT la barre d'URL de Chrome Android,
     * donc un geste vers le haut la retracte, le viewport grandit, et le
     * composeur ancre en bas se deplace. La Messagerie etait restee en `dvh`.
     *
     * Les deux moities sont mesurees, et aucune ne suffit : sans le verrou du
     * document, Chrome continue de retracter sa barre ; sans `svh`, la hauteur
     * continue de varier.
     */
    public function test_sur_telephone_le_document_ne_defile_plus_et_la_hauteur_ne_varie_plus(): void
    {
        $html = $this->messagerie();

        $this->assertSame(
            1,
            preg_match('/body:has\(\.messages-index-container\)\s*\{[^}]*overflow:\s*hidden/s', $html),
            'Le document defile encore : Chrome retractera sa barre d\'URL et le composeur bougera.'
        );

        $this->assertSame(
            1,
            preg_match('/\.messages-index-container\s*\{[^}]*100svh/s', $html),
            'La hauteur reste en `dvh`, l\'unite qui suit la barre d\'URL.'
        );

        // Et le repli `dvh` PRECEDE la regle `svh` : l'inverse rendrait `svh`
        // inoperant partout, la derniere declaration gagnant.
        $this->assertTrue(
            strpos($html, '100dvh - 3.5rem') < strpos($html, '100svh - 3.5rem'),
            'Le repli `dvh` est declare APRES `svh` : il l\'annule.'
        );
    }

    /**
     * La hauteur du viewport VISUEL est branchee, et par le MEME composant que
     * l'espace d'une Boucle.
     *
     * C'est elle qui tient quand le clavier virtuel s'ouvre. Deux copies d'un
     * calcul de viewport divergent a la premiere retouche : il n'y en a qu'une.
     */
    public function test_la_hauteur_du_viewport_visuel_est_branchee(): void
    {
        $html = $this->messagerie();

        // La garde porte sur l'ECRITURE de la variable, pas sur sa seule
        // apparition : `var(--bp-vvh, 100svh)` figure de toute facon dans la
        // feuille de style. Un sabotage qui vidait le script est reste VERT
        // tant que la mesure ne distinguait pas la DEFINITION de l'USAGE —
        // meme piege qu'en TASK-1609 sur `data-bp-mobile-nav`.
        $this->assertSame(
            1,
            preg_match("/setProperty\(\s*'--bp-vvh'/", $html),
            'Le script n\'ecrit plus la hauteur du viewport visuel : le clavier virtuel la fera bouger.'
        );

        $this->assertStringContainsString("querySelector('.messages-index-container')", $html);
        $this->assertStringContainsString('visualViewport', $html);
    }

    // ── 3. Le rail ──────────────────────────────────────────────────────────

    /**
     * L'icone Messagerie s'allume dans le rail, comme les autres.
     *
     * Les DEUX etats sont mesures : allumee sur la messagerie, eteinte
     * ailleurs. Une garde qui ne verifierait que la presence resterait verte si
     * toutes les icones s'allumaient en permanence.
     */
    public function test_l_icone_messagerie_s_allume_dans_le_rail(): void
    {
        $surLaMessagerie = $this->messagerie();

        $this->assertSame(
            1,
            preg_match('/data-side-nav-item="messages"[^>]*data-side-nav-active="1"/', $surLaMessagerie),
            'L\'icone Messagerie du rail ne s\'allume pas sur la messagerie.'
        );

        app()->forgetInstance('current_organization');
        $ailleurs = $this->actingAs($this->membre)
            ->get('/org/'.$this->organization->slug.'/membres')
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/data-side-nav-item="messages"[^>]*data-side-nav-active="0"/', $ailleurs),
            'L\'icone Messagerie reste allumee hors de la messagerie.'
        );
    }
}
