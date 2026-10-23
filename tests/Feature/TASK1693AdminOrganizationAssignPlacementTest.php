<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1693, bug D — l'affectation d'Organization vit dans la colonne qu'elle
 * modifie, et redevient atteignable sur desktop.
 *
 * ## Le defaut
 *
 * Le controle etait un popover Alpine (`absolute right-0 … z-10`) pose dans la
 * colonne « Actions ». Or le conteneur du tableau porte `overflow-x-auto`
 * depuis TASK-1640, qui l'avait mis a la place d'un `overflow-hidden` pour
 * cesser de couper les colonnes. Un ancetre en `overflow-x: auto` etablit un
 * conteneur de defilement qui ROGNE ses descendants en `absolute` — et
 * `z-index` n'y change rien, une pile de contexte ne sortant pas du cadre qui
 * la rogne. La colonne « Actions » etant au bord droit, le panneau s'ouvrait
 * hors cadre.
 *
 * Effet de bord d'un correctif anterieur, invisible jusqu'ici : un enfant
 * `absolute` mal place ne leve aucune erreur, il s'affiche simplement ailleurs
 * qu'on croit.
 *
 * ## Ce que ces tests mesurent, et ce qu'ils ne peuvent pas mesurer
 *
 * Ils mesurent la STRUCTURE rendue (placement, absence de superposition,
 * presence aux deux largeurs) et le COUT en requetes. Ils ne peuvent pas
 * mesurer qu'un panneau est visible a l'oeil : le clipping est un fait de mise
 * en page, qui demanderait un navigateur reel. La garde porte donc sur la CAUSE
 * — plus aucun positionnement absolu pour ce controle — plutot que sur le
 * symptome.
 */
class TASK1693AdminOrganizationAssignPlacementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Organization::factory()->create(['slug' => 'org-1693d-a', 'name' => 'Alpha 1693', 'is_active' => true]);
        $this->b = Organization::factory()->create(['slug' => 'org-1693d-b', 'name' => 'Beta 1693', 'is_active' => true]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->a->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->a->id,
            'email_verified_at' => now(),
        ]);
    }

    private function html(): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();
    }

    /** @return string la cellule « Organisation » de la ligne du membre */
    private function celluleOrganisation(string $html): string
    {
        $ancre = 'data-user-assign-organization="'.$this->membre->id.':desktop"';
        $position = strpos($html, $ancre);

        $this->assertNotFalse($position, 'le formulaire desktop est present');

        // La cellule qui le contient : on remonte au `<td` precedent.
        $debut = strrpos(substr($html, 0, $position), '<td');
        $this->assertNotFalse($debut);

        return substr($html, $debut, strpos($html, '</td>', $debut) - $debut + 5);
    }

    public function test_the_control_sits_in_the_organization_column_not_in_the_actions_column(): void
    {
        $cellule = $this->celluleOrganisation($this->html());

        // La cellule porte a la fois l'INFORMATION et le controle : l'affectation
        // ne doit pas remplacer l'affichage de l'Organization courante.
        $this->assertStringContainsString($this->a->slug, $cellule, 'la cellule continue d\'afficher l\'affectation');
        $this->assertStringContainsString('name="organization_id"', $cellule);
        $this->assertStringContainsString($this->b->slug, $cellule, 'les autres Organizations sont proposees');

        // Le NOM COMPLET n'occupe plus la colonne : il n'apparait qu'en
        // attribut `title`, jamais comme texte rendu. C'est la mesure du
        // retour de Cyril (« cela prend trop de place ») — sans elle, le
        // correctif pourrait etre defait sans que rien ne rougisse.
        $sansTitles = preg_replace('/title="[^"]*"/', '', $cellule);
        // Viser la forme ECHAPPEE, y compris pour une ABSENCE : un nom portant
        // une apostrophe est rendu `&#039;` par Blade, donc sa forme brute
        // n'apparait JAMAIS — l'assertion passerait sans rien prouver.
        // Faux vert, pas faux rouge (lecon TASK-1672).
        $this->assertStringNotContainsString(e($this->a->name), $sansTitles,
            'le nom complet ne doit pas elargir la colonne');
        $this->assertStringNotContainsString(e($this->b->name), $sansTitles);

        // …mais il reste accessible au survol : court ne doit pas vouloir dire muet.
        $this->assertStringContainsString('title="'.$this->a->name.'"', $cellule);

        // Et la colonne est bien celle d'Organisation : elle est masquee sous `md`.
        $this->assertStringContainsString('hidden md:table-cell', $cellule);
    }

    /**
     * LA CAUSE du defaut est fermee : plus aucune superposition.
     *
     * C'est la garde utile. Mesurer « le panneau est visible » demanderait un
     * navigateur ; mesurer « il n'y a plus de panneau a rogner » se fait ici.
     */
    public function test_the_control_no_longer_relies_on_an_absolutely_positioned_overlay(): void
    {
        $cellule = $this->celluleOrganisation($this->html());

        foreach (['absolute', 'x-show', 'x-cloak', 'commOpen'] as $interdit) {
            $this->assertStringNotContainsString(
                $interdit,
                $cellule,
                "[{$interdit}] : le controle doit vivre dans le flux, un enfant positionne serait rogne par le `overflow-x-auto` du tableau",
            );
        }
    }

    /**
     * Le controle existe aux DEUX largeurs.
     *
     * La colonne Organisation etant `hidden md:table-cell`, deplacer le
     * controle sans second rendu aurait RETIRE du telephone une capacite qui y
     * fonctionnait : le popover n'etait casse que sur desktop.
     */
    public function test_the_control_exists_for_both_widths_from_a_single_partial(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-user-assign-organization="'.$this->membre->id.':desktop"', $html);
        $this->assertStringContainsString('data-user-assign-organization="'.$this->membre->id.':mobile"', $html);

        // Le rendu mobile est bien borne a `md:hidden` : sans cela, les deux
        // formulaires s'afficheraient en meme temps sur desktop.
        $position = strpos($html, 'data-user-assign-organization="'.$this->membre->id.':mobile"');
        $avant = substr($html, max(0, $position - 400), 400);
        $this->assertStringContainsString('md:hidden', $avant);
    }

    /** Le geste fonctionne, et il reste explicite (aucune soumission au `change`). */
    public function test_the_gesture_reassigns_and_requires_an_explicit_submit(): void
    {
        $cellule = $this->celluleOrganisation($this->html());

        $this->assertStringContainsString('type="submit"', $cellule);
        $this->assertStringNotContainsString('@change', $cellule,
            'reaffecter le tenant de quelqu\'un ne doit pas tenir a un clic de travers');

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.users.assign-organization', $this->membre), [
                'organization_id' => $this->b->id,
            ])
            ->assertRedirect();

        $this->assertSame($this->b->id, $this->membre->fresh()->organization_id);
    }

    /**
     * Le cout de l'ecran ne grandit plus avec le nombre de lignes.
     *
     * L'ancien popover appelait `Organization::where('is_active', true)->get()`
     * DANS la boucle : une requete par ligne. Le partiel recoit desormais la
     * collection deja chargee par le controleur.
     */
    public function test_the_screen_does_not_query_organizations_once_per_row(): void
    {
        User::factory()->count(8)->create([
            'organization_id' => $this->a->id,
            'email_verified_at' => now(),
        ]);

        DB::enableQueryLog();
        $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk();
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $surOrganizations = collect($requetes)
            ->filter(fn (array $r): bool => str_contains($r['query'], 'from "organizations"'))
            ->count();

        // Une poignee de lectures d'Organizations est normale (le filtre, la
        // portee, le tenant courant). Ce qui ne doit JAMAIS arriver, c'est que
        // ce nombre suive le nombre de lignes : 10 comptes sont affiches ici.
        $this->assertLessThan(
            10,
            $surOrganizations,
            'le nombre de requetes sur `organizations` ne doit pas suivre le nombre de lignes',
        );
    }
}
