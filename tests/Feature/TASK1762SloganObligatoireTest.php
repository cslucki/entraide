<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\UserSlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1762 point 17 — le slogan, « ce qui vous définit en une phrase ».
 *
 * Obligatoire sur le formulaire du membre (contrairement a tous ses voisins
 * de cette TASK). Affiche en dessous de la structure sur la fiche publique,
 * AVANT le texte de presentation sur les cartes de l'annuaire, et bien en
 * avant sur la vue « Nouveaux arrivés ». Editable par le SuperAdmin.
 */
class TASK1762SloganObligatoireTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-slogan', 'is_active' => true, 'locale' => 'fr',
            'structure_enabled' => true,
        ]);
        app()->instance('current_organization', $this->org);
    }

    private function membre(array $surcharge = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'first_name' => 'Camille', 'name' => 'MOREAU', 'phone' => '0600000000',
            'approved_at' => now(),
        ], $surcharge));
    }

    private function formulaire(User $u, array $surcharge = []): array
    {
        return array_merge([
            'first_name' => $u->first_name,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
        ], $surcharge);
    }

    // ── La colonne ───────────────────────────────────────────────────────────

    /** La colonne existe, et reste NULLABLE : aucun compte existant n'est touche. */
    public function test_la_colonne_existe_et_est_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'slogan'));
        $this->assertNull($this->membre()->fresh()->slogan);
    }

    // ── Le formulaire du membre ──────────────────────────────────────────────

    /** 🔴 Le champ est dans la section « Présentation », AVANT la presentation. */
    public function test_le_champ_est_dans_presentation_avant_la_bio(): void
    {
        $membre = $this->membre();

        $html = $this->actingAs($membre)
            ->get(route('organization.profile.edit', $this->org))
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="slogan"', $html);

        $positionBlocPresentation = strpos($html, 'data-profile-block="presentation"');
        $positionSlogan = strpos($html, 'name="slogan"');
        $positionBio = strpos($html, 'name="bio"');

        $this->assertNotFalse($positionBlocPresentation);
        $this->assertGreaterThan($positionBlocPresentation, $positionSlogan,
            'Le slogan doit vivre DANS le bloc « Présentation ».');
        $this->assertLessThan($positionBio, $positionSlogan,
            'Le slogan doit preceder le champ de presentation.');
    }

    /** 🔴 Obligatoire : un `PUT` sans slogan est refuse, et rien d'autre n'est enregistre. */
    public function test_un_put_sans_slogan_est_refuse(): void
    {
        $membre = $this->membre(['slogan' => 'Ancien slogan']);

        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, ['first_name' => 'Modifie']))
            ->assertSessionHasErrors('slogan');

        $this->assertSame('Ancien slogan', $membre->fresh()->slogan);
        $this->assertSame('Camille', $membre->fresh()->first_name);
    }

    /** On l'enregistre. */
    public function test_le_slogan_est_enregistre(): void
    {
        $membre = $this->membre();

        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, ['slogan' => 'Je resous des problemes, pas des tickets.']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Je resous des problemes, pas des tickets.', $membre->fresh()->slogan);
    }

    /** 🔴 Trop long (120) : refuse, et le reste du profil n'est pas enregistre pour autant. */
    public function test_un_slogan_trop_long_est_refuse(): void
    {
        $membre = $this->membre(['slogan' => 'Slogan initial']);

        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, [
                'slogan' => str_repeat('a', 121),
                'first_name' => 'Modifie',
            ]))
            ->assertSessionHasErrors('slogan');

        $this->assertSame('Slogan initial', $membre->fresh()->slogan);
        $this->assertSame('Camille', $membre->fresh()->first_name);
    }

    /** Et 120 caracteres pile passent. */
    public function test_120_caracteres_pile_sont_acceptes(): void
    {
        $membre = $this->membre();

        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, ['slogan' => str_repeat('a', 120)]))
            ->assertSessionHasNoErrors();

        $this->assertSame(120, mb_strlen($membre->fresh()->slogan));
    }

    // ── La fiche publique ────────────────────────────────────────────────────

    /** 🔴 En dessous de la structure. */
    public function test_sur_la_fiche_il_suit_la_structure(): void
    {
        $membre = $this->membre([
            'structure' => 'SCOP Oxalis',
            'slogan' => 'Je resous des problemes, pas des tickets.',
        ]);
        app(UserSlugService::class)->attribuer($membre, 'camille');

        $html = $this->actingAs($this->membre())
            ->get(route('organization.profile.show', ['organization' => $this->org, 'user' => 'camille']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-profile-slogan', $html);

        $positionStructure = strpos($html, 'data-profile-structure');
        $positionSlogan = strpos($html, 'data-profile-slogan');

        $this->assertNotFalse($positionStructure);
        $this->assertLessThan($positionSlogan, $positionStructure);
    }

    /** Et il s'affiche meme quand l'Organization n'autorise pas la structure. */
    public function test_sur_la_fiche_il_s_affiche_sans_structure_activee(): void
    {
        $this->org->update(['structure_enabled' => false]);

        $membre = $this->membre(['slogan' => 'Je resous des problemes, pas des tickets.']);
        app(UserSlugService::class)->attribuer($membre, 'camille');

        $html = $this->actingAs($this->membre())
            ->get(route('organization.profile.show', ['organization' => $this->org, 'user' => 'camille']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-profile-slogan', $html);
        $this->assertStringContainsString('Je resous des problemes, pas des tickets.', $html);
    }

    // ── Les cartes de l'annuaire ─────────────────────────────────────────────

    private function carteDe(string $html, User $membre): string
    {
        $debut = strpos($html, 'data-member-card="'.$membre->id.'"');

        $this->assertNotFalse($debut, 'La carte de ce membre est introuvable.');

        $suivante = strpos($html, 'data-member-card=', $debut + 20);

        return $suivante === false
            ? substr($html, $debut)
            : substr($html, $debut, $suivante - $debut);
    }

    /** 🔴 Sur la carte, AVANT le texte de presentation. */
    public function test_sur_la_carte_il_precede_la_presentation(): void
    {
        $membre = $this->membre([
            'slogan' => 'Je resous des problemes, pas des tickets.',
            'bio' => 'Une biographie complete.',
        ]);

        $html = $this->actingAs($this->membre())
            ->get(route('organization.members.index', $this->org))
            ->assertOk()->getContent();

        $carte = $this->carteDe($html, $membre);

        $this->assertStringContainsString('data-member-slogan', $carte);

        $positionSlogan = strpos($carte, 'data-member-slogan');
        $positionBio = strpos($carte, 'Une biographie complete.');

        $this->assertNotFalse($positionBio);
        $this->assertLessThan($positionBio, $positionSlogan);
    }

    // ── La vue « Nouveaux arrivés » ───────────────────────────────────────────

    /** 🔴 Mis en avant — present, et pas tronque. */
    public function test_sur_nouveaux_arrives_il_est_mis_en_avant(): void
    {
        $slogan = 'Je resous des problemes, pas des simples tickets de support.';
        $membre = $this->membre(['slogan' => $slogan]);

        $url = route('organization.members.index', $this->org).'?'.http_build_query(['view' => 'latest']);
        $html = $this->actingAs($this->membre())->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('data-member-slogan', $html);
        $this->assertStringContainsString($slogan, $html);
    }

    // ── La recherche ─────────────────────────────────────────────────────────

    /**
     * 🔴 Le moteur de recherche trouve aussi dans le SLOGAN.
     *
     * Meme raisonnement que le point 13 (la bio) : le slogan est deja
     * affiche sur la carte et la fiche publique, le chercher ne rend
     * trouvable que ce qui l'etait deja a la lecture.
     */
    public function test_la_recherche_trouve_dans_le_slogan(): void
    {
        $trouve = $this->membre(['slogan' => 'Je resous des problemes, pas des tickets.']);
        $absent = $this->membre(['first_name' => 'Jean', 'name' => 'DUPONT', 'slogan' => 'Autre chose entierement.']);

        $url = route('organization.members.index', $this->org).'?'.http_build_query(['q' => 'problemes']);
        $html = $this->actingAs($this->membre())->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('data-member-card="'.$trouve->id.'"', $html);
        $this->assertStringNotContainsString('data-member-card="'.$absent->id.'"', $html);
    }

    // ── Le SuperAdmin ────────────────────────────────────────────────────────

    /**
     * 🔴 Le SuperAdmin peut le modifier — mais le champ n'y est PAS requis.
     *
     * « On pourra le modifier via le superadmin » rend le champ EDITABLE, pas
     * obligatoire sur cet ecran : au moment ou cette TASK ajoute la colonne,
     * AUCUN compte existant n'a de slogan, et `required` y bloquerait tout
     * autre geste de l'ecran (bannir, approuver…) tant qu'il n'est pas saisi.
     */
    public function test_le_superadmin_peut_le_modifier_sans_qu_il_soit_requis(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);
        $membre = $this->membre(['slogan' => null]);

        $html = $this->actingAs($admin)
            ->get(route('admin.users.edit', $membre))
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="slogan"', $html);

        // Sans slogan dans le payload : les AUTRES champs s'enregistrent quand meme.
        $this->actingAs($admin)
            ->put(route('admin.users.update', $membre), [
                'name' => $membre->name,
                'email' => $membre->email,
                'first_name' => 'Modifie',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Modifie', $membre->fresh()->first_name);

        // Et avec un slogan dans le payload : il s'enregistre.
        $this->actingAs($admin)
            ->put(route('admin.users.update', $membre), [
                'name' => $membre->name,
                'email' => $membre->email,
                'first_name' => 'Modifie',
                'slogan' => 'Pose par le SuperAdmin.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Pose par le SuperAdmin.', $membre->fresh()->slogan);
    }
}
