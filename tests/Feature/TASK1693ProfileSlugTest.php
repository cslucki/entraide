<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserSlug;
use App\Services\UserSlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug Z — l'adresse publique d'un membre.
 *
 * `/org/ofsh/profile/01a15a3e-b8f3-718e-8a8a-c4a761a24f3d` ne se lit pas, ne se
 * retient pas et ne se dit pas a l'oral. `/org/ofsh/profile/marie-dupont` fait
 * les trois.
 *
 * ## Ce que le slug ne fait PAS
 *
 * La page porte `Authenticate` ET `EnsureOrganizationMember`. Le slug la rend
 * donc LISIBLE, pas PUBLIQUE. Ce test le fixe explicitement, pour que personne
 * ne croie plus tard que ce correctif a ouvert les profils.
 *
 * ## La regle qui compte le plus : aucun recyclage
 *
 * Sans elle : Marie a `marie-dupont`, le partage, change pour `marie` ; le
 * slug se libere, Paul le prend, et TOUS les liens deja partages vers Marie
 * menent chez Paul. Une usurpation d'identite creee par la fonctionnalite.
 */
class TASK1693ProfileSlugTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693z',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = $this->personne('Marie', 'Dupont');

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function personne(?string $prenom, string $nom, ?Organization $dans = null): User
    {
        $user = User::factory()->complete()->create([
            'organization_id' => ($dans ?? $this->organization)->id,
            'first_name' => $prenom,
            'name' => $nom,
            'email_verified_at' => now(),
            'slug' => null,
        ]);

        app(UserSlugService::class)->attribuerDepuisLeNom($user);

        return $user->refresh();
    }

    private function urlProfil(string $segment): string
    {
        return route('organization.profile.show', [
            'organization' => $this->organization->slug,
            'user' => $segment,
        ]);
    }

    // ── La derivation ────────────────────────────────────────────────────────

    /** « prenom-nom », l'ordre dont on parle. */
    public function test_the_slug_is_derived_as_firstname_lastname(): void
    {
        $this->assertSame('marie-dupont', $this->membre->slug);
    }

    /** Les accents et la ponctuation sont aplanis. */
    public function test_accents_and_punctuation_are_flattened(): void
    {
        $this->assertSame('melky-cedric-lamango-tollo',
            $this->personne('Melky Cédric', 'LAMANGO-TOLLO')->slug);
    }

    /** Sans prenom, le nom seul suffit. */
    public function test_a_missing_first_name_falls_back_to_the_name_alone(): void
    {
        $this->assertSame('bernard', $this->personne(null, 'Bernard')->slug);
    }

    /**
     * Sans nom exploitable, l'UUID RESTE.
     *
     * Fabriquer un « membre-1234 » serait plus joli et ne voudrait rien dire.
     */
    public function test_an_unusable_name_keeps_the_uuid(): void
    {
        $user = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => null,
            'name' => '**',
            'slug' => null,
        ]);

        $this->assertNull(app(UserSlugService::class)->attribuerDepuisLeNom($user));
        $this->assertNull($user->refresh()->slug);
    }

    /** Une collision est suffixee — a la REPRISE seulement. */
    public function test_a_collision_is_suffixed_during_backfill(): void
    {
        $this->assertSame('marie-dupont-2', $this->personne('Marie', 'Dupont')->slug);
    }

    /** Mais deux Organizations peuvent porter le MEME slug. */
    public function test_two_organizations_can_hold_the_same_slug(): void
    {
        $ailleurs = Organization::factory()->create(['slug' => 'org-1693z-bis', 'is_active' => true]);

        $this->assertSame('marie-dupont', $this->personne('Marie', 'Dupont', $ailleurs)->slug,
            'Organization = Tenant : l\'unicite est composite, jamais globale');
    }

    // ── La resolution ────────────────────────────────────────────────────────

    /** LE geste demande : l'adresse lisible ouvre le profil. */
    public function test_the_readable_address_opens_the_profile(): void
    {
        $this->actingAs($this->membre)
            ->get($this->urlProfil('marie-dupont'))
            ->assertOk()
            ->assertSee($this->membre->publicDisplayName());
    }

    /**
     * L'identifiant continue d'aboutir — et REDIRIGE vers l'adresse lisible.
     *
     * C'est ce qui permet au correctif de ne rien casser : les 44 points du
     * code qui construisent une URL de profil, les liens echanges en message
     * et les favoris aboutissent tous.
     */
    public function test_the_uuid_still_works_and_redirects_to_the_readable_address(): void
    {
        $this->actingAs($this->membre)
            ->get($this->urlProfil($this->membre->id))
            ->assertRedirect($this->urlProfil('marie-dupont'));
    }

    /** Un segment inconnu rend 404, jamais une erreur de base. */
    public function test_an_unknown_segment_is_a_plain_404(): void
    {
        $this->actingAs($this->membre)
            ->get($this->urlProfil('personne-de-ce-nom'))
            ->assertNotFound();
    }

    // ── Le choix, depuis /profile/edit ───────────────────────────────────────

    /** Le champ est offert a chacun sur son propre profil. */
    public function test_the_field_is_offered_on_the_profile_form(): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('profile.edit'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-profile-slug-field', $html);
        $this->assertStringContainsString('name="slug"', $html);
    }

    /** Et choisir une adresse fonctionne. */
    public function test_a_member_can_choose_their_own_address(): void
    {
        $this->actingAs($this->membre)
            ->put(route('profile.update'), [
                'name' => $this->membre->name,
                'email' => $this->membre->email,
                'phone' => '+33600000000',
                'slug' => 'donald',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('donald', $this->membre->refresh()->slug);

        $this->actingAs($this->membre)->get($this->urlProfil('donald'))->assertOk();
    }

    /**
     * LA regle centrale : l'ancienne adresse n'est pas recyclee.
     *
     * Elle redirige vers la nouvelle, et reste indisponible pour autrui.
     */
    public function test_a_released_address_is_never_recycled(): void
    {
        $this->actingAs($this->membre)
            ->put(route('profile.update'), [
                'name' => $this->membre->name,
                'email' => $this->membre->email,
                'phone' => '+33600000000',
                'slug' => 'marie',
            ])
            ->assertSessionHasNoErrors();

        // 1. L'ancien lien aboutit toujours, et chez la BONNE personne.
        $this->actingAs($this->membre)
            ->get($this->urlProfil('marie-dupont'))
            ->assertRedirect($this->urlProfil('marie'));

        // 2. Et personne d'autre ne peut le reprendre.
        $paul = $this->personne('Paul', 'Martin');

        $this->actingAs($paul)
            ->put(route('profile.update'), [
                'name' => $paul->name,
                'email' => $paul->email,
                'phone' => '+33600000000',
                'slug' => 'marie-dupont',
            ])
            ->assertSessionHasErrors('slug');

        $this->assertSame('paul-martin', $paul->refresh()->slug,
            'heriter de l\'adresse de quelqu\'un, c\'est heriter de ses liens');
    }

    /** Une adresse deja prise est refusee, et le DIT. */
    public function test_a_taken_address_is_refused_with_its_own_message(): void
    {
        $paul = $this->personne('Paul', 'Martin');

        $this->actingAs($paul)
            ->put(route('profile.update'), [
                'name' => $paul->name,
                'email' => $paul->email,
                'phone' => '+33600000000',
                'slug' => 'marie-dupont',
            ])
            ->assertSessionHasErrors(['slug' => __('profile.slug_taken')]);
    }

    /**
     * Les segments RESERVES sont refuses.
     *
     * `/profile/edit`, `/profile/ai-usage` et `/profile/availability` sont
     * declares AVANT `/profile/{user}` et gagnent la resolution : quelqu'un
     * dont le slug serait `edit` aurait un profil INATTEIGNABLE.
     */
    public function test_reserved_segments_are_refused(): void
    {
        foreach (['edit', 'ai-usage', 'availability'] as $reserve) {
            $this->actingAs($this->membre)
                ->put(route('profile.update'), [
                    'name' => $this->membre->name,
                    'email' => $this->membre->email,
                    'phone' => '+33600000000',
                    'slug' => $reserve,
                ])
                ->assertSessionHasErrors(['slug' => __('profile.slug_invalid')]);

            $this->assertSame('marie-dupont', $this->membre->refresh()->slug,
                "[{$reserve}] fabriquerait une adresse morte");
        }
    }

    /** Une forme invalide est refusee, avec son propre message. */
    public function test_an_invalid_shape_is_refused(): void
    {
        foreach (['Marie Dupont', 'ab', 'marie--dupont', 'marie_dupont', '-marie'] as $mauvais) {
            $this->actingAs($this->membre)
                ->put(route('profile.update'), [
                    'name' => $this->membre->name,
                    'email' => $this->membre->email,
                    'phone' => '+33600000000',
                    'slug' => $mauvais,
                ])
                ->assertSessionHasErrors(['slug' => __('profile.slug_invalid')]);
        }

        $this->assertSame('marie-dupont', $this->membre->refresh()->slug);
    }

    // ── L'arbitrage, depuis l'administration ─────────────────────────────────

    /** Le champ est offert au SuperAdmin. */
    public function test_the_field_is_offered_on_the_admin_screen(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.users.edit', $this->membre))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-admin-slug-field', $html);
        $this->assertStringContainsString('name="slug"', $html);
    }

    /** Et le SuperAdmin arbitre reellement — avec les MEMES gardes. */
    public function test_the_superadmin_can_change_an_address_under_the_same_guards(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $payload = [
            'first_name' => $this->membre->first_name,
            'name' => $this->membre->name,
            'email' => $this->membre->email,
        ];

        $this->actingAs($admin)
            ->put(route('admin.users.update', $this->membre), $payload + ['slug' => 'edit'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $this->membre), $payload + ['slug' => 'marie-d'])
            ->assertSessionHasNoErrors();

        $this->assertSame('marie-d', $this->membre->refresh()->slug);
    }

    // ── Ce que le slug ne change PAS ─────────────────────────────────────────

    /**
     * Le profil reste reserve aux MEMBRES.
     *
     * Le slug le rend lisible, pas public. Sans ce test, on pourrait croire
     * plus tard que ce correctif a ouvert les profils — il ne l'a pas fait, et
     * les rouvrir serait une autre decision.
     */
    public function test_the_readable_address_does_not_make_the_profile_public(): void
    {
        // Une route prefixee renvoie vers la connexion DE L'ORGANIZATION, pas
        // vers la connexion generique.
        $this->get($this->urlProfil('marie-dupont'))
            ->assertRedirect(route('organization.login', ['organization' => $this->organization->slug]));

        $etranger = User::factory()->complete()->create([
            'organization_id' => Organization::factory()->create(['slug' => 'org-1693z-tiers'])->id,
        ]);

        $this->actingAs($etranger)->get($this->urlProfil('marie-dupont'))->assertNotFound();
    }

    /** Le registre inscrit bien chaque adresse, courante et passee. */
    public function test_the_ledger_records_every_address(): void
    {
        app(UserSlugService::class)->attribuer($this->membre, 'marie');

        $inscriptions = UserSlug::where('user_id', $this->membre->id)->get();

        $this->assertCount(2, $inscriptions);
        $this->assertSame(['marie-dupont', 'marie'], $inscriptions->sortBy('created_at')->pluck('slug')->all());
        $this->assertSame(1, $inscriptions->where('is_current', true)->count(),
            'une seule adresse courante a la fois');
    }
}
