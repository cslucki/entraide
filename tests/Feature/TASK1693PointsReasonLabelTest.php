<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * TASK-1693, bug G — le grand livre d'un membre n'affiche jamais une clef de
 * traduction.
 *
 * ## Le defaut
 *
 * La vue faisait :
 *
 *     __('points.reasons.'.$entry->reason, ['default' => $entry->reason])
 *
 * Le second argument d'`__()` est le tableau de REMPLACEMENT des
 * `:placeholders`, pas une valeur par defaut. `['default' => ...]` n'a donc
 * jamais rien fait, et Laravel rend la CLEF quand la traduction manque : le
 * membre lisait « points.reasons.cool ».
 *
 * ## Deux familles de motifs cohabitent
 *
 * `point_ledgers.reason` porte :
 *  - un vocabulaire FERME ecrit par le produit (`welcome_bonus`,
 *    `exchange_earned`, `exchange_spent`, `referral_reward`, `adjustment`,
 *    `admin_reset`), qui se traduit ;
 *  - du TEXTE LIBRE saisi par un administrateur depuis le panneau de
 *    correction (TASK-1667, elargi en TASK-1693 bug F), qui ne peut pas se
 *    traduire et doit s'afficher tel quel.
 *
 * `Lang::has()` distingue les deux — c'est la seule facon, `__()` ne sachant
 * pas dire « absent ».
 */
class TASK1693PointsReasonLabelTest extends TestCase
{
    use RefreshDatabase;

    /** Le vocabulaire FERME que le produit ecrit lui-meme. */
    private const MOTIFS_PRODUIT = [
        'welcome_bonus',
        'exchange_earned',
        'exchange_spent',
        'referral_reward',
        'adjustment',
        'admin_reset',
    ];

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693g',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    private function ecriture(string $motif, int $delta = 10): PointLedger
    {
        return PointLedger::query()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'delta' => $delta,
            'reason' => $motif,
        ]);
    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.points.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    /** LE defaut signale : une clef brute ne doit jamais atteindre l'ecran. */
    public function test_a_free_text_reason_is_shown_as_typed_never_as_a_translation_key(): void
    {
        $this->ecriture('cool');

        $html = $this->page();

        $this->assertStringNotContainsString('points.reasons.', $html,
            'aucune clef de traduction ne doit paraitre a l\'ecran');
        $this->assertStringContainsString('cool', $html,
            'le motif libre saisi par l\'administration s\'affiche tel quel');
    }

    /** Chaque motif du vocabulaire PRODUIT a sa traduction, dans les deux langues. */
    public function test_every_product_reason_is_translated_in_both_languages(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (self::MOTIFS_PRODUIT as $motif) {
                $cle = 'points.reasons.'.$motif;

                $this->assertTrue(Lang::has($cle), "[{$locale}] {$cle} n'est pas traduit");
                $this->assertNotSame($cle, __($cle), "[{$locale}] {$cle} rend sa propre clef");
            }
        }

        app()->setLocale('fr');
    }

    /**
     * `admin_reset` en particulier — c'est lui qui manquait.
     *
     * Il est ecrit par le bouton « remettre a zero » du panneau de correction,
     * en champ cache. Un motif que le PRODUIT ecrit doit se traduire : ce
     * n'est pas du texte libre, et le laisser au repli afficherait un
     * identifiant technique a un membre.
     */
    public function test_the_reset_reason_written_by_the_admin_panel_is_translated(): void
    {
        $this->ecriture('admin_reset', -50);

        $html = $this->page();

        $this->assertStringNotContainsString('admin_reset', $html,
            'un motif du vocabulaire produit ne doit pas tomber dans le repli');
        // `e()` : « Remise a zero par l'administration » porte une apostrophe,
        // que Blade rend en `&#039;`. Chercher la forme brute mesurerait
        // l'echappement, pas le libelle.
        $this->assertStringContainsString(e(__('points.reasons.admin_reset')), $html);
    }

    /**
     * La garde qui empeche la regression : le repli doit DISTINGUER.
     *
     * Un repli qui afficherait toujours `$entry->reason` passerait le premier
     * test tout en cessant de traduire. On mesure donc les deux familles dans
     * la MEME page : l'une traduite, l'autre brute.
     */
    public function test_the_fallback_distinguishes_the_two_families_on_the_same_page(): void
    {
        $this->ecriture('welcome_bonus', 100);
        $this->ecriture('correction recette', 5);

        $html = $this->page();

        $this->assertStringContainsString(e(__('points.reasons.welcome_bonus')), $html);
        $this->assertStringNotContainsString('welcome_bonus', $html,
            'le vocabulaire produit est TRADUIT, pas rendu brut');
        $this->assertStringContainsString('correction recette', $html,
            'le texte libre est rendu TEL QUEL, pas traduit');
    }
}
