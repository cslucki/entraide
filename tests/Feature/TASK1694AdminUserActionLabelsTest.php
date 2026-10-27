<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug K — « Lien de réinitialisation » devient « Réinitialisation mdp ».
 *
 * ## Pourquoi un libellé, et pourquoi il mérite un test
 *
 * La colonne « Actions » porte désormais DEUX liens envoyés par email : la
 * confirmation d'adresse (bug J) et le mot de passe. « Lien de
 * réinitialisation » ne disait pas lequel — deux gestes aux conséquences
 * différentes derrière des libellés qu'on ne peut pas distinguer.
 *
 * Le test mesure le libellé **traduit**, pas une chaîne en dur : le texte
 * français était jusqu'ici écrit dans le Blade, hors de toute clef, donc
 * invisible à la version anglaise de l'écran.
 */
class TASK1694AdminUserActionLabelsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create([
            'slug' => 'org-1694k',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * La langue est imposee par la SESSION, pas par `setLocale()`.
     *
     * `SetLocale` recalcule la langue a chaque requete : un `setLocale()` pose
     * avant le `get()` est ecrase avant que le Blade ne soit rendu. La session
     * est la premiere autorite que ce middleware consulte.
     */
    private function html(string $locale): string
    {
        return $this->actingAs($this->superAdmin)
            ->withSession(['locale' => $locale])
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();
    }

    /** LE libellé demandé. */
    public function test_the_label_names_the_password(): void
    {
        $this->assertStringContainsString('Réinitialisation mdp', $this->html('fr'));
    }

    /**
     * Et l'ancien a disparu.
     *
     * Sans cette moitié, ajouter le nouveau texte ailleurs sur la page
     * suffirait à faire passer le test au-dessus.
     */
    public function test_the_old_ambiguous_label_is_gone(): void
    {
        $this->assertStringNotContainsString('Lien de réinitialisation<', $this->html('fr'));
    }

    /**
     * Le libellé passe par une clef de traduction.
     *
     * Mesuré en anglais : un texte resté en dur dans le Blade rendrait du
     * français sur l'écran anglais.
     */
    public function test_the_label_is_translated(): void
    {
        $html = $this->html('en');

        $this->assertStringContainsString('Password reset', $html);
        $this->assertStringNotContainsString('Réinitialisation mdp', $html);
    }
}
