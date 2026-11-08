<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1788 (demande Cyril) — l'icone Flux dans la barre du bas, sur telephone.
 *
 * ## Ce que la demande recouvrait vraiment
 *
 * L'entree EXISTAIT deja dans `mobile-bottom-nav`, mais derriere
 * `can('create', FeedPost::class)` : elle ne paraissait donc qu'a qui a le
 * droit de PUBLIER. Or le Flux se LIT bien plus largement — `viewAny` ne
 * demande que d'appartenir a l'Organization. Un membre qui ne publie pas
 * n'avait **aucun chemin** vers le Flux sur son telephone.
 *
 * Le rail de bureau porte deja l'arbitrage, pris par Cyril le 10/09 et ecrit
 * dans `app-side-nav` : « l'entree se montre TOUJOURS (…) un rail qui cache ce
 * qu'on n'a pas le droit de faire cache aussi ce qui existe. » La barre du
 * telephone ne l'avait jamais suivi.
 *
 * Ces tests opposent donc les DEUX droits — publier et lire — pour que la
 * distinction ne se reperde pas.
 */
class TASK1788Correctif10bisFluxBarreMobileTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // `feed_post_publish_mode` par DEFAUT : seuls l'administrateur de
        // l'Organization et le SuperAdmin publient. C'est la precondition du
        // test — un membre ordinaire ne peut pas publier ici.
        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788-barre', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);
    }

    private function barre(User $qui): string
    {
        return $this->actingAs($qui)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    /** ⚠️ LE cas de la demande : un membre qui ne peut PAS publier. */
    public function test_un_membre_qui_ne_publie_pas_voit_le_flux_dans_la_barre(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->organization->id]);

        // PRECONDITION : il ne peut effectivement pas publier. Sans elle, le
        // test ne mesurerait pas ce qu'il annonce.
        $this->assertFalse($membre->can('create', FeedPost::class),
            'Precondition non prise : ce membre PEUT publier, le test ne prouverait rien.');

        $html = $this->barre($membre);

        $this->assertStringContainsString('data-bp-mobile-nav', $html,
            'Temoin absent : la barre du bas n\'est pas rendue.');
        $this->assertStringContainsString('data-bp-nav-item="flux"', $html,
            'Le Flux manque dans la barre du bas pour qui ne peut pas publier.');
    }

    /** Et il n'a pas disparu pour qui peut publier. */
    public function test_celui_qui_publie_voit_toujours_le_flux(): void
    {
        $this->organization->update(['feed_post_publish_mode' => 'members']);
        $publieur = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->assertTrue($publieur->can('create', FeedPost::class),
            'Precondition non prise : ce membre ne peut pas publier.');

        $this->assertStringContainsString('data-bp-nav-item="flux"', $this->barre($publieur),
            'Le Flux a disparu de la barre pour qui peut publier.');
    }

    /**
     * Le Flux est a GAUCHE de « Dossiers » — demande de Cyril.
     *
     * La barre DEFILE depuis T1625 : la position n'est donc pas contrainte par
     * la place disponible, c'est un choix d'ordre de lecture. Un choix qui ne
     * se mesure pas se reperd au premier ajout d'onglet.
     *
     * ⚠️ `data-bp-dossiers` n'existe que si l'Organization a la route des
     * dossiers : la precondition est verifiee avant de comparer les positions,
     * sinon le test passerait sur une absence.
     */
    public function test_le_flux_precede_les_dossiers_dans_la_barre(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->organization->id]);

        $html = $this->barre($membre);

        $positionFlux = strpos($html, 'data-bp-nav-item="flux"');
        $positionDossiers = strpos($html, 'data-bp-nav-item="dossiers"');

        $this->assertNotFalse($positionFlux, 'Temoin absent : le Flux n\'est pas dans la barre.');
        $this->assertNotFalse($positionDossiers, 'Precondition non prise : « Dossiers » n\'est pas dans la barre, l\'ordre ne se mesure pas.');

        $this->assertLessThan($positionDossiers, $positionFlux,
            'Le Flux ne precede plus « Dossiers » dans la barre du bas.');

        // Et il vient APRES l'annuaire : c'est la place demandee, pas « quelque
        // part avant Dossiers ».
        $positionAnnuaire = strpos($html, 'data-bp-nav-item="members"');
        $this->assertNotFalse($positionAnnuaire, 'Temoin absent : l\'annuaire n\'est pas dans la barre.');
        $this->assertGreaterThan($positionAnnuaire, $positionFlux,
            'Le Flux a remonte au-dela de la place demandee.');
    }

    /**
     * La barre du telephone dit la MEME chose que le rail de bureau.
     *
     * C'est tout l'objet de la correction : deux barres qui divergent sur ce
     * qui existe font croire a deux produits.
     */
    public function test_la_barre_du_telephone_et_le_rail_de_bureau_s_accordent(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->organization->id]);

        $html = $this->barre($membre);

        // Le rail de bureau rend l'entree Flux pour tout le monde depuis
        // l'arbitrage du 10/09 : les deux doivent etre dans la page.
        $this->assertStringContainsString('data-bp-nav-item="flux"', $html, 'La barre du telephone n\'a pas le Flux.');
        $this->assertStringContainsString(
            e(route('organization.flux', ['organization' => $this->organization->slug])),
            $html,
            'L\'adresse du Flux n\'est nulle part dans la page.'
        );
    }
}
