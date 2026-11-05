<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 14 — le pli des Boucles sur les cartes de l'annuaire.
 *
 * Arbitrage de Cyril : au-dela de trois Boucles, les trois premieres restent
 * visibles et une pastille « +N » porte le reste — **jamais plus de quatre
 * pastilles**. Le clic deplie sur place, sans quitter l'annuaire.
 */
class TASK1762PliDesBouclesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-boucles', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->org);

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function membre(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Camille', 'name' => 'MOREAU',
            'approved_at' => now(),
        ]);
    }

    private function rejoint(User $membre, string $nom, string $role = 'member'): Loop
    {
        $boucle = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'name' => $nom,
            'status' => 'active',
            'created_by' => $membre->id,
        ]);

        LoopMember::create([
            'loop_id' => $boucle->id, 'user_id' => $membre->id,
            'role' => $role, 'status' => 'active',
            'joined_at' => now(), 'organization_id' => $this->org->id,
        ]);

        return $boucle;
    }

    private function page(array $parametres = []): string
    {
        $url = route('organization.members.index', $this->org);

        if ($parametres !== []) {
            $url .= '?'.http_build_query($parametres);
        }

        return $this->actingAs($this->visiteur)->get($url)->assertOk()->getContent();
    }

    /** @return string le bloc de Boucles de ce membre, isole */
    private function blocBoucles(string $html, User $membre): string
    {
        $debut = strpos($html, 'data-member-loops="'.$membre->id.'"');

        $this->assertNotFalse($debut, 'Le bloc de Boucles est introuvable.');

        $fin = strpos($html, '</div>', $debut);

        return substr($html, $debut, $fin - $debut);
    }

    // ── En dessous du seuil ──────────────────────────────────────────────────

    /** Trois Boucles ou moins : rien ne change, aucune pastille. */
    public function test_trois_boucles_ne_se_plient_pas(): void
    {
        $membre = $this->membre();
        foreach (['Alpha', 'Beta', 'Gamma'] as $nom) {
            $this->rejoint($membre, $nom);
        }

        $bloc = $this->blocBoucles($this->page(), $membre);

        foreach (['Alpha', 'Beta', 'Gamma'] as $nom) {
            $this->assertStringContainsString($nom, $bloc);
        }

        $this->assertStringNotContainsString('data-member-loops-plus', $bloc);
        $this->assertStringNotContainsString('data-member-loop-replie', $bloc);
    }

    // ── Au-dela du seuil ─────────────────────────────────────────────────────

    /**
     * 🔴 JAMAIS plus de quatre pastilles — la limite posee par Cyril.
     *
     * Six Boucles : trois badges visibles, trois replies, une pastille « +3 ».
     */
    public function test_jamais_plus_de_quatre_pastilles_visibles(): void
    {
        $membre = $this->membre();
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta'] as $nom) {
            $this->rejoint($membre, $nom);
        }

        $bloc = $this->blocBoucles($this->page(), $membre);

        // Trois badges SANS `x-show`, et trois AVEC.
        $this->assertSame(3, substr_count($bloc, 'data-member-loop-replie'));
        $this->assertSame(6, substr_count($bloc, 'data-member-loop='));

        // Une seule pastille, qui annonce le bon nombre.
        $this->assertSame(1, substr_count($bloc, 'data-member-loops-plus'));
        // `assertMatchesRegularExpression` et non une chaine collee : le
        // gabarit Blade met le libelle sur sa propre ligne, et une assertion
        // sur « >+3< » mesurerait l'indentation, pas le contenu.
        $this->assertMatchesRegularExpression('/data-member-loops-plus.*?>\s*\+3\s*</s', $bloc);
    }

    /** 🔴 L'infobulle de la pastille NOMME les Boucles cachees. */
    public function test_la_pastille_nomme_les_boucles_cachees(): void
    {
        $membre = $this->membre();
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $nom) {
            $this->rejoint($membre, $nom);
        }

        $bloc = $this->blocBoucles($this->page(), $membre);

        preg_match('/<button[^>]*data-member-loops-plus[^>]*>/', $bloc, $m);

        $this->assertNotEmpty($m, 'La pastille est introuvable.');
        // ALPHABETIQUE, et non l'ordre grec : Delta precede Epsilon qui
        // precede Gamma. Les trois visibles sont donc Alpha, Beta, Delta.
        $this->assertStringContainsString('Epsilon, Gamma', html_entity_decode($m[0]));
    }

    /**
     * 🔴 Les badges replies sont dans le DOM, pas charges a la demande.
     *
     * Si Alpine ne demarre pas, `x-show` ne retire rien : l'etat degrade montre
     * PLUS, jamais moins. Et chaque badge reste un lien vers sa Boucle.
     */
    public function test_les_badges_replies_restent_des_liens_dans_le_dom(): void
    {
        $membre = $this->membre();
        $boucles = [];
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $nom) {
            $boucles[$nom] = $this->rejoint($membre, $nom);
        }

        $bloc = $this->blocBoucles($this->page(), $membre);

        $this->assertStringContainsString('Delta', $bloc);
        $this->assertStringContainsString('data-member-loop="'.$boucles['Delta']->id.'"', $bloc);
        $this->assertStringContainsString($boucles['Delta']->slug, $bloc);
    }

    /** Le pli a sa racine Alpine : sans elle, la directive serait inerte. */
    public function test_le_pli_a_sa_racine_alpine(): void
    {
        $membre = $this->membre();
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $nom) {
            $this->rejoint($membre, $nom);
        }

        $bloc = $this->blocBoucles($this->page(), $membre);

        $this->assertStringContainsString('x-data', $bloc);
        $this->assertStringContainsString('deplie', $bloc);
    }

    // ── L'ordre ──────────────────────────────────────────────────────────────

    /**
     * 🔴 Les Boucles qu'on ANIME passent devant.
     *
     * L'ordre cesse d'etre cosmetique des lors que seules les trois premieres
     * sont visibles : il decide de ce qu'on voit.
     */
    public function test_les_boucles_animees_passent_devant(): void
    {
        $membre = $this->membre();

        // Noms choisis pour que l'ordre alphabetique soit l'INVERSE de l'ordre
        // attendu : sans le tri par role, ce test rougirait.
        $this->rejoint($membre, 'Zeta simple', 'member');
        $this->rejoint($membre, 'Alpha simple', 'member');
        $this->rejoint($membre, 'Yankee animee', 'facilitator');
        $this->rejoint($membre, 'Xray possedee', 'owner');

        $bloc = $this->blocBoucles($this->page(), $membre);

        $this->assertLessThan(strpos($bloc, 'Yankee animee'), strpos($bloc, 'Xray possedee'));
        $this->assertLessThan(strpos($bloc, 'Alpha simple'), strpos($bloc, 'Yankee animee'));

        // La seule repliee est donc la derniere du classement.
        preg_match('/data-member-loop-replie[^>]*>\s*([^<]+)/', $bloc, $m);
        $this->assertSame('Zeta simple', trim($m[1]));
    }

    // ── Les deux presentations ───────────────────────────────────────────────

    /** 🔴 Le MEME pli dans la vue « Nouveaux arrivés ». */
    public function test_le_pli_vaut_aussi_pour_la_vue_nouveaux(): void
    {
        $membre = $this->membre();
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $nom) {
            $this->rejoint($membre, $nom);
        }

        $html = $this->page(['view' => 'latest']);
        $bloc = $this->blocBoucles($html, $membre);

        $this->assertStringContainsString('data-member-row', $html);
        $this->assertStringContainsString('data-member-loops-plus', $bloc);
        $this->assertMatchesRegularExpression('/data-member-loops-plus.*?>\s*\+2\s*</s', $bloc);
    }
}
