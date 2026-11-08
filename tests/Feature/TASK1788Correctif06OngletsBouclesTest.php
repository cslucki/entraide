<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1788, correctif 06 — l'inventaire des Boucles.
 *
 * Trois demandes de Cyril :
 *
 * 1. un type sans aucune Boucle ne doit plus avoir d'onglet (« Coaching 0 ») ;
 * 2. « Vos espaces de collaboration » disparait — elle coute une ligne en haut
 *    d'ecran et n'apprend rien que le titre ne dise deja ;
 * 3. un onglet « Mes boucles », propose SEULEMENT a qui appartient a une
 *    Boucle, en est proprietaire ou animateur.
 */
class TASK1788Correctif06OngletsBouclesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788-06',
            'is_active' => true,
            'loops_enabled' => true,
            'members_can_create_loops' => true,
            'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    private function boucle(string $type, bool $jEnSuisMembre = false): Loop
    {
        $boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => $type,
        ]);

        if ($jEnSuisMembre) {
            LoopMember::factory()->create([
                'loop_id' => $boucle->id,
                'user_id' => $this->membre->id,
                'status' => 'active',
            ]);
        }

        return $boucle;
    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('loops.index'))
            ->assertOk()
            ->getContent();
    }

    /** L'onglet d'un type, tel qu'il est rendu : son libelle suivi du compte. */
    private function ongletDe(string $cle): string
    {
        return app(LoopTypeRegistry::class)->label($cle, $this->organization);
    }

    // ── 1. Les types a zero ──────────────────────────────────────────────────

    public function test_un_type_sans_aucune_boucle_n_a_plus_d_onglet(): void
    {
        $this->boucle('general');
        $this->boucle('project');

        $html = $this->page();

        // TEMOIN : la barre d'onglets est bien rendue, et les types qui ONT des
        // Boucles y figurent. Sans lui, une page en erreur ferait passer les
        // assertions d'absence.
        $this->assertStringContainsString(e(__('loops.types_tab_all')), $html,
            'Temoin absent : la barre d\'onglets n\'est pas rendue.');
        $this->assertStringContainsString(e($this->ongletDe('general')), $html, 'Le type « Communaute » a perdu son onglet.');
        $this->assertStringContainsString(e($this->ongletDe('project')), $html, 'Le type « Projets » a perdu son onglet.');

        foreach (['coaching', 'training', 'peer_support', 'networking', 'writing'] as $vide) {
            $this->assertStringNotContainsString(e($this->ongletDe($vide)), $html,
                "Le type « {$this->ongletDe($vide)} » garde un onglet alors qu'il n'a aucune Boucle.");
        }
    }

    /**
     * ⚠️ La moitie de l'ancienne regle qu'il ne fallait PAS perdre.
     *
     * Un type retire de l'offre mais encore porte par une Boucle doit garder
     * son onglet : sans lui, ces Boucles seraient visibles dans « Toutes » et
     * introuvables ailleurs. Compter suffit a le garantir — c'est pour cela que
     * le filtre a pu se reduire a une seule condition.
     */
    public function test_un_type_retire_de_l_offre_garde_son_onglet_s_il_porte_une_boucle(): void
    {
        // `peer_support` existe au catalogue mais n'est PAS offert : c'est le
        // cas reel d'un type retire de l'offre, pas une mise en scene.
        $registre = app(LoopTypeRegistry::class);
        $this->assertTrue($registre->exists('peer_support'), 'Precondition : le type doit exister au catalogue.');
        $this->assertFalse($registre->isAvailable('peer_support', $this->organization),
            'Precondition non prise : « peer_support » est propose, le test ne prouverait rien.');

        $this->boucle('general');
        $this->boucle('peer_support');

        $html = $this->page();

        $this->assertStringContainsString(e($this->ongletDe('peer_support')), $html,
            'Une Boucle porte ce type, mais il n\'a plus d\'onglet : elle devient introuvable.');
    }

    // ── 2. La phrase retiree ─────────────────────────────────────────────────

    public function test_la_phrase_des_espaces_de_collaboration_ne_figure_plus(): void
    {
        // DEUX Boucles : avec une seule, l'Organization bascule en mode
        // mono-boucle et `/loops` redirige (302) vers elle. L'inventaire
        // n'existe qu'a partir de deux.
        $this->boucle('general');
        $this->boucle('project');

        $html = $this->page();

        $this->assertStringContainsString(e(__('loops.types_tab_all')), $html,
            'Temoin absent : la page n\'est pas rendue.');

        $phrase = __('loops.collaboration_spaces');
        $this->assertStringNotContainsString($phrase, $html, 'La phrase est encore rendue.');
        $this->assertStringNotContainsString(e($phrase), $html, 'La phrase est encore rendue (forme echappee).');
    }

    // ── Le titre de la page ──────────────────────────────────────────────────

    /**
     * « Boucles », et non « Mes boucles » : la page montre AUSSI celles qu'on
     * peut decouvrir, et le nouvel onglet « Mes Boucles » dit desormais
     * lui-meme ou sont les siennes.
     *
     * L'assertion porte sur le `<h1>` : « Mes Boucles » reste present dans la
     * page — c'est le libelle de l'onglet et celui de la section. Le chercher
     * dans tout le document ferait rougir un correctif juste.
     */
    public function test_le_titre_de_la_page_est_boucles(): void
    {
        $this->boucle('general', jEnSuisMembre: true);
        $this->boucle('project');

        $html = $this->page();

        $this->assertMatchesRegularExpression(
            '/<h1[^>]*>\s*'.preg_quote(e(__('loops.index_title')), '/').'\s*<\/h1>/u',
            $html,
            'Le titre de la page n\'est pas « '.__('loops.index_title').' ».'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<h1[^>]*>\s*'.preg_quote(e(__('loops.my_loops')), '/').'\s*<\/h1>/u',
            $html,
            'Le titre de la page est encore « '.__('loops.my_loops').' ».'
        );
    }

    // ── 4. La carte epuree ───────────────────────────────────────────────────

    /**
     * Ni type, ni categories sur la carte — mais les FILTRES survivent.
     *
     * C'est toute la precaution de ce point : `data-type` et les listes du
     * `x-show` ne s'affichent pas, elles servent aux filtres par type et par
     * domaine. Retirer l'affichage ne doit pas retirer la donnee, sinon les
     * onglets qu'on vient de corriger ne filtreraient plus rien.
     */
    public function test_la_carte_n_affiche_plus_le_type_ni_les_categories(): void
    {
        $boucle = $this->boucle('general', jEnSuisMembre: true);
        $this->boucle('project');

        // `categories` n'a pas de colonne `name` : le libelle vit dans
        // `name_b2c` / `name_b2b`, et `displayName()` choisit selon le contexte.
        $categorie = \App\Models\Category::factory()->create([
            'organization_id' => $this->organization->id,
            'name_b2c' => 'Un domaine de mesure',
            'name_b2b' => 'Un domaine de mesure',
        ]);
        $boucle->categories()->attach($categorie->id);

        $html = $this->page();

        // TEMOIN : la carte de cette Boucle est bien rendue.
        $this->assertStringContainsString(e($boucle->name), $html,
            'Temoin absent : la carte de la Boucle n\'est pas rendue.');

        // La pastille de type portait le libelle dans un `<span>` arrondi ;
        // le libelle reste present ailleurs (onglets), donc on mesure la
        // PASTILLE, pas le mot.
        $this->assertStringNotContainsString(
            'rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600',
            $html,
            'La pastille de type est encore rendue sur la carte.'
        );

        // ⚠️ L'assertion porte sur la PASTILLE de domaine, pas sur le mot :
        // le nom du domaine reste legitimement dans la page — c'est une option
        // du filtre « Tous les domaines », qui, lui, ne bouge pas. Chercher le
        // mot dans tout le document ferait rougir un correctif juste.
        $this->assertStringNotContainsString(
            'inline-flex items-center whitespace-nowrap rounded-md bg-gray-100 px-1.5 py-0.5',
            $html,
            'Les pastilles de domaine sont encore rendues sur la carte.'
        );

        // ⚠️ Les filtres continuent de fonctionner : la donnee reste.
        $this->assertStringContainsString('data-type="general"', $html,
            'Le marqueur de type a disparu : le filtre par onglet ne trierait plus rien.');
        $this->assertStringContainsString($categorie->id, $html,
            'L\'identifiant de domaine a disparu : le filtre par domaine ne trierait plus rien.');
    }

    // ── 3. L'onglet « Mes boucles » ──────────────────────────────────────────

    public function test_l_onglet_mes_boucles_est_propose_a_qui_appartient_a_une_boucle(): void
    {
        $this->boucle('general', jEnSuisMembre: true);
        $this->boucle('project');

        $html = $this->page();

        $this->assertStringContainsString('data-loops-tab-mine', $html,
            'L\'onglet « Mes boucles » manque a quelqu\'un qui est membre d\'une Boucle.');
    }

    /**
     * Un proprietaire ou un animateur est un MEMBRE, avec un role.
     *
     * La demande en nommait trois ; `is_member` les couvre tous les trois —
     * une appartenance active, quel que soit le role. Recopier trois conditions
     * aurait garanti qu'elles divergent.
     */
    public function test_l_onglet_est_propose_au_proprietaire_et_a_l_animateur(): void
    {
        foreach (['owner', 'facilitator'] as $role) {
            $organisation = Organization::factory()->create([
                'slug' => 'org-1788-06-'.$role, 'is_active' => true,
                'loops_enabled' => true, 'locale' => 'fr',
            ]);
            app()->instance('current_organization', $organisation);

            $qui = User::factory()->create(['organization_id' => $organisation->id]);
            $boucle = Loop::factory()->create(['organization_id' => $organisation->id, 'type' => 'general']);
            // La seconde Boucle evite le mode mono-boucle, qui redirigerait.
            Loop::factory()->create(['organization_id' => $organisation->id, 'type' => 'project']);
            LoopMember::factory()->create([
                'loop_id' => $boucle->id, 'user_id' => $qui->id, 'status' => 'active', 'role' => $role,
            ]);

            $html = $this->actingAs($qui)->get(route('loops.index'))->assertOk()->getContent();

            $this->assertStringContainsString('data-loops-tab-mine', $html,
                "L'onglet « Mes boucles » manque a un {$role}.");
        }
    }

    public function test_l_onglet_n_est_pas_propose_a_qui_n_appartient_a_aucune_boucle(): void
    {
        $this->boucle('general');
        $this->boucle('project');

        $html = $this->page();

        // TEMOIN : la barre d'onglets EST rendue — sans lui, l'absence ne
        // prouverait rien.
        $this->assertStringContainsString(e(__('loops.types_tab_all')), $html,
            'Temoin absent : la barre d\'onglets n\'est pas rendue.');

        $this->assertStringNotContainsString('data-loops-tab-mine', $html,
            'L\'onglet « Mes boucles » est propose a qui n\'appartient a aucune Boucle.');
    }

    /**
     * ⚠️ Le piege que ce correctif devait eviter.
     *
     * Les cartes se filtrent elles-memes sur `q`, `domain` et `type` — jamais
     * sur `mine`, qu'elles ne connaissent pas. Masquer le seul TITRE de
     * « Decouvrir » aurait laisse la grille complete en dessous, et l'onglet
     * n'aurait rien filtre.
     */
    public function test_la_section_decouvrir_entiere_est_masquee_par_l_onglet(): void
    {
        $this->boucle('general', jEnSuisMembre: true);
        $this->boucle('project');

        $html = $this->page();

        $this->assertStringContainsString('data-loops-discover', $html,
            'La section « Decouvrir » n\'a pas de conteneur : seul son titre pourrait etre masque.');
        $this->assertMatchesRegularExpression(
            '/<div x-show="! mine" data-loops-discover>/',
            $html,
            'Le conteneur de « Decouvrir » n\'est pas conditionne a l\'onglet « Mes boucles ».'
        );
    }
}
