<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Support\Flowchart\FlowchartGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1608, addendum MASTER — les portes parlent le vocabulaire de leur
 * Organization.
 *
 * ## Une seule configuration, celle qui existe deja
 *
 * L'ecran `/admin/organizations/{organization}/homepage` ecrit quatre libelles
 * dans `Organization.homepage_settings`. Le flowchart les relit, exactement
 * comme `organization/hero-v2.blade.php:5` :
 * `filled($settings[$cle]) ? … : org_trans($defaut)`.
 *
 * Aucune seconde configuration n'est creee.
 *
 * ## LE PIEGE, et pourquoi ce fichier le mesure en premier
 *
 * Les noms des champs admin sont TROMPEURS :
 *
 * | ecran admin | champ reel | intention |
 * |---|---|---|
 * | Card 1 — « J'explore une piste » | `card_create_label` | `explore_idea` |
 * | Card 2 — « Je cree du lien »     | `card_meet_label`   | `connect` |
 * | Card 3 — « J'ai besoin d'aide »  | `card_help_label`   | `need_help` |
 * | Card 4 — « Je peux aider »       | `card_offer_label`  | `offer_help` |
 *
 * `card_create_label` porte « J'explore », et `card_meet_label` porte « Je
 * cree du lien » : un mapping deduit des NOMS aurait interverti deux
 * intentions sur quatre, et personne ne l'aurait vu sans mesure.
 *
 * ## Editorial, jamais structurel
 *
 * Le libelle change ; l'identifiant du noeud, lui, reste `intent:<cle>`. Aucun
 * comportement ne se branche sur du texte.
 */
class TASK1608FlowchartIntentLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    private function organisation(array $homepage = [], array $extra = []): Organization
    {
        return Organization::factory()->create(array_merge([
            'slug' => 'org-'.bin2hex(random_bytes(4)),
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'homepage_settings' => $homepage,
        ], $extra));
    }

    /** @return array<string, array<string, mixed>> les noeuds d'intention, par id */
    private function portes(Organization $organization): array
    {
        $graph = app(FlowchartGraph::class)->build($organization, null);

        $portes = [];

        foreach ($graph['nodes'] as $node) {
            if ($node['data']['kind'] === 'intent') {
                $portes[$node['data']['id']] = $node['data'];
            }
        }

        return $portes;
    }

    // =====================================================================
    // A. Le mapping reel
    // =====================================================================

    /**
     * Chaque champ admin atterrit sur LA bonne intention.
     *
     * Les quatre valeurs sont volontairement distinctes et sans rapport avec
     * leur intention : un mapping inverse produirait quatre libelles au bon
     * format mais sur les mauvaises portes, et un test qui ne verifierait que
     * « le texte personnalise apparait » le laisserait passer.
     */
    public function test_each_admin_field_lands_on_the_right_intent(): void
    {
        $organisation = $this->organisation([
            'card_help_label' => 'ALPHA besoin',
            'card_offer_label' => 'BETA offre',
            'card_create_label' => 'GAMMA exploration',
            'card_meet_label' => 'DELTA lien',
        ]);

        $portes = $this->portes($organisation);

        $this->assertSame('ALPHA besoin', $portes['intent:need_help']['label']);
        $this->assertSame('BETA offre', $portes['intent:offer_help']['label']);
        $this->assertSame(
            'GAMMA exploration',
            $portes['intent:explore_idea']['label'],
            "`card_create_label` porte « J'explore », pas « Creer » : son nom ment.",
        );
        $this->assertSame(
            'DELTA lien',
            $portes['intent:connect']['label'],
            '`card_meet_label` porte « Je cree du lien », pas « Rencontrer » : son nom ment aussi.',
        );
    }

    // =====================================================================
    // B. L'exemple de MASTER, de bout en bout
    // =====================================================================

    /**
     * « J'ai besoin d'une ressource » remplace « J'ai besoin d'aide », et rien
     * d'autre ne bouge.
     */
    public function test_a_custom_label_replaces_the_canonical_one_on_that_node_only(): void
    {
        $organisation = $this->organisation(['card_help_label' => "J'ai besoin d'une ressource"]);

        $graph = app(FlowchartGraph::class)->build($organisation, null);
        $portes = $this->portes($organisation);

        $this->assertSame("J'ai besoin d'une ressource", $portes['intent:need_help']['label']);

        // Le libelle canonique ne subsiste nulle part dans le payload.
        $this->assertStringNotContainsString(
            "J'ai besoin d'aide",
            json_encode($graph, JSON_UNESCAPED_UNICODE),
            'Le libelle canonique ne doit pas cohabiter avec celui de l\'Organization.',
        );

        // Les trois autres portes gardent le leur.
        $this->assertSame(__('flowchart.intent_offer_help'), $portes['intent:offer_help']['label']);
    }

    /**
     * Le texte change, la STRUCTURE non.
     *
     * L'identifiant reste `intent:need_help`, l'entree propre a cette
     * intention reste atteignable, et son arete part toujours de la meme
     * porte. C'est le §2 de l'addendum : la personnalisation est editoriale.
     */
    public function test_a_custom_label_never_changes_the_structure(): void
    {
        $organisation = $this->organisation(['card_help_label' => "J'ai besoin d'une ressource"]);

        $graph = app(FlowchartGraph::class)->build($organisation, null);

        $ids = array_column(array_column($graph['nodes'], 'data'), 'id');

        $this->assertContains('intent:need_help', $ids, "L'identifiant fonctionnel ne suit pas le texte.");
        $this->assertContains('entry:need_help', $ids, "L'entree propre a l'intention reste presente.");

        $aretes = array_map(
            static fn (array $e): string => $e['data']['source'].'>'.$e['data']['target'],
            $graph['edges'],
        );

        $this->assertContains('intent:need_help>entry:need_help', $aretes);
        $this->assertContains('entry:need_help>step:clarify', $aretes);

        // Et l'entree garde SON libelle canonique : le §6 interdit de
        // reecrire les textes internes a partir du CTA de l'admin.
        $entree = collect($graph['nodes'])->firstWhere('data.id', 'entry:need_help');
        $this->assertSame(__('flowchart.entry_need_help'), $entree['data']['label']);
    }

    // =====================================================================
    // C. Repli, vacuite, isolation
    // =====================================================================

    /** Sans personnalisation, les libelles canoniques du flowchart. */
    public function test_without_any_override_the_canonical_labels_are_used(): void
    {
        $portes = $this->portes($this->organisation());

        foreach (FlowchartGraph::INTENTS as $intent) {
            $this->assertSame(
                __('flowchart.intent_'.$intent),
                $portes['intent:'.$intent]['label'],
                "Repli manquant pour {$intent}.",
            );
        }
    }

    /**
     * Une valeur VIDE ou faite d'espaces ne cree pas une porte sans nom.
     *
     * `filled()` laisse passer `'   '` sur une chaine non triviale ? Non — mais
     * il laisse passer `'<br>'`, qui donnerait une card muette apres retrait
     * du balisage. La mesure porte donc sur le resultat, pas sur `filled()`.
     */
    public function test_an_empty_or_markup_only_override_falls_back(): void
    {
        foreach (['', '   ', '<br>', '<span></span>'] as $vide) {
            $portes = $this->portes($this->organisation(['card_help_label' => $vide]));

            $this->assertSame(
                __('flowchart.intent_need_help'),
                $portes['intent:need_help']['label'],
                'Une valeur sans texte doit retomber sur le libelle canonique, jamais rendre une card muette.',
            );
        }
    }

    /** Le balisage saisi par un admin n'atteint pas le canvas. */
    public function test_markup_in_an_override_is_stripped_not_escaped(): void
    {
        $portes = $this->portes($this->organisation([
            'card_help_label' => "J'ai besoin<br>d'une ressource",
        ]));

        // Ni `<br>`, ni `&lt;br&gt;` : un libelle de noeud n'est pas du HTML,
        // et l'echapper afficherait l'echappement.
        $this->assertSame("J'ai besoin d'une ressource", $portes['intent:need_help']['label']);
    }

    /** Deux Organizations, deux vocabulaires — et aucun emprunt entre elles. */
    public function test_two_organizations_keep_their_own_wording(): void
    {
        $a = $this->organisation(['card_help_label' => 'Besoin chez A']);
        $b = $this->organisation(['card_help_label' => 'Besoin chez B']);

        $this->assertSame('Besoin chez A', $this->portes($a)['intent:need_help']['label']);
        $this->assertSame('Besoin chez B', $this->portes($b)['intent:need_help']['label']);

        $graphA = json_encode(app(FlowchartGraph::class)->build($a, null), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Besoin chez B', $graphA);
    }

    /**
     * La page rend bien le libelle de l'Organization — pas seulement le
     * constructeur du graphe.
     */
    public function test_the_page_renders_the_organization_wording(): void
    {
        $organisation = $this->organisation(['card_help_label' => "J'ai besoin d'une ressource"]);

        app()->forgetInstance('current_organization');

        $response = $this->get('/org/'.$organisation->slug.'/flowchart');
        $response->assertOk();

        // `assertSee` AVEC echappement : Blade rend `'` en `&#039;`, et
        // chercher la chaine brute mesurerait l'echappement, pas le libelle.
        $response->assertSee("J'ai besoin d'une ressource");
        $response->assertDontSee("J'ai besoin d'aide");
    }
}
