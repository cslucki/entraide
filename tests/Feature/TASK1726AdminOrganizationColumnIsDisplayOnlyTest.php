<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1726 — la colonne « Organisation » de `/admin/users` redevient un
 * AFFICHAGE.
 *
 * ## D'ou vient ce fichier
 *
 * Il remplace `TASK1693AdminOrganizationAssignPlacementTest`, qui mesurait le
 * selecteur d'affectation en ligne : son placement dans la colonne qu'il
 * modifiait, l'absence de superposition `absolute` (le popover d'origine etait
 * rogne par le `overflow-x-auto` du tableau, defaut TASK-1693 bug D), sa
 * presence aux deux largeurs, et le caractere explicite de la soumission.
 *
 * Ce controle n'existe plus : l'Organization d'un compte est definitive. Les
 * tests de son placement n'avaient donc plus d'objet — mais deux des quatre
 * choses qu'ils mesuraient en PASSANT survivent au retrait, et devaient etre
 * recuperees avant de supprimer le fichier :
 *
 *   - la colonne affiche le SLUG, jamais le nom complet (retour de Cyril en
 *     TASK-1693 : « cela prend trop de place »). L'affichage est tout ce qui
 *     reste de la colonne, donc cette garde compte plus qu'avant ;
 *   - le cout de l'ecran ne suit pas le nombre de lignes. Le N+1 d'origine
 *     (`Organization::where('is_active')->get()` dans la boucle) etait celui
 *     du popover ; le retrait du selecteur ne doit pas en reintroduire un par
 *     une lecture de `$u->organization` non prechargee.
 *
 * ## Ce qu'il mesure en plus
 *
 * L'ABSENCE du controle, aux deux largeurs. Un bouton retire d'un seul des
 * deux rendus serait passe inapercu : la colonne desktop est `hidden` sous
 * `md`, et c'est exactement le piege que TASK-1693 bug D avait rencontre dans
 * l'autre sens.
 */
class TASK1726AdminOrganizationColumnIsDisplayOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Organization::factory()->create(['slug' => 'org-1726-a', 'name' => 'Alpha 1726', 'is_active' => true]);
        $this->b = Organization::factory()->create(['slug' => 'org-1726-b', 'name' => 'Beta 1726', 'is_active' => true]);

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

    /**
     * @return string la cellule « Organisation » de la ligne du membre
     *
     * L'ancien ancrage etait le marqueur du formulaire d'affectation, qui a
     * disparu avec lui. Celui-ci part du `title` portant le nom complet — mais
     * en restreignant d'ABORD au corps du tableau : la barre laterale rend
     * elle aussi `title="<nom de l'Organization>"` sur son lien « Org
     * dashboard », et chercher dans toute la page remontait sur ce lien, donc
     * sur une region qui n'a rien a voir avec la colonne mesuree.
     */
    private function celluleOrganisation(string $html): string
    {
        $tableau = $this->corpsDuTableau($html);

        $position = strpos($tableau, 'title="'.$this->a->name.'"');
        $this->assertNotFalse($position, 'la cellule Organisation doit etre rendue');

        $debut = strrpos(substr($tableau, 0, $position), '<td');
        $this->assertNotFalse($debut);

        return substr($tableau, $debut, strpos($tableau, '</td>', $debut) - $debut + 5);
    }

    /**
     * @return string le `<tbody>` de la liste, et rien d'autre
     *
     * Le filtre par Organization vit en TETE de page et liste toujours tous
     * les tenants — on cherche encore les gens par Organization, on ne les
     * deplace plus. Une assertion d'ABSENCE posee sur la page entiere
     * mesurerait donc ce filtre et rougirait a tort.
     */
    private function corpsDuTableau(string $html): string
    {
        $debut = strpos($html, '<tbody');
        $this->assertNotFalse($debut, 'la liste doit rendre un tableau');

        return substr($html, $debut, strpos($html, '</tbody>', $debut) - $debut);
    }

    public function test_la_colonne_affiche_encore_l_organization_du_compte(): void
    {
        $cellule = $this->celluleOrganisation($this->html());

        $this->assertStringContainsString($this->a->slug, $cellule,
            "retirer le controle ne doit pas retirer l'information");

        // La colonne est bien celle d'Organisation : masquee sous `md`.
        $this->assertStringContainsString('hidden md:table-cell', $cellule);

        // Le NOM COMPLET n'occupe pas la colonne : il n'apparait qu'en `title`.
        // Viser la forme ECHAPPEE, y compris pour une ABSENCE : un nom portant
        // une apostrophe est rendu `&#039;` par Blade, donc sa forme brute
        // n'apparaitrait JAMAIS et l'assertion passerait sans rien prouver.
        $sansTitles = preg_replace('/title="[^"]*"/', '', $cellule);
        $this->assertStringNotContainsString(e($this->a->name), $sansTitles,
            'le nom complet ne doit pas elargir la colonne');

        // …mais il reste accessible au survol : court ne doit pas vouloir dire muet.
        $this->assertStringContainsString('title="'.$this->a->name.'"', $cellule);
    }

    public function test_aucun_controle_d_affectation_ne_subsiste_a_aucune_largeur(): void
    {
        $html = $this->html();

        // Le marqueur du partiel supprime, dans ses deux contextes.
        $this->assertStringNotContainsString('data-user-assign-organization', $html);

        // Et l'autre Organization n'est plus PROPOSEE sur la ligne.
        $tableau = $this->corpsDuTableau($html);
        $this->assertStringNotContainsString('name="organization_id"', $tableau,
            "aucun champ d'affectation ne doit subsister dans les lignes");
        $this->assertStringNotContainsString($this->b->slug, $tableau,
            "les autres Organizations ne sont plus proposees ligne par ligne");
    }

    /**
     * Le cout de l'ecran ne grandit pas avec le nombre de lignes.
     *
     * Recupere de TASK-1693 bug D, et toujours utile dans l'autre sens : le
     * selecteur recevait une collection PRECHARGEE, et l'affichage qui le
     * remplace lit `$u->organization`. Sans `with()`, ce serait un N+1 neuf —
     * le meme defaut, reintroduit par le correctif qui enlevait sa cause.
     */
    public function test_l_ecran_ne_lit_pas_les_organizations_une_fois_par_ligne(): void
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

        // Une poignee de lectures est normale (le filtre, la portee, le tenant
        // courant). Ce qui ne doit JAMAIS arriver, c'est que ce nombre suive le
        // nombre de lignes : 10 comptes sont affiches ici.
        $this->assertLessThan(
            10,
            $surOrganizations,
            'le nombre de requetes sur `organizations` ne doit pas suivre le nombre de lignes',
        );
    }

    public function test_la_fiche_modifier_affiche_l_organization_sans_selecteur(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.edit', $this->membre))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-user-organization-readonly="'.$this->a->id.'"',
            $html,
            "la fiche doit AFFICHER l'Organization du compte"
        );
        $this->assertStringContainsString(e($this->a->name), $html);
        // `e()` : la phrase porte une apostrophe, que Blade rend `&#039;`.
        // Comparer la forme BRUTE aurait echoue — et, dans une assertion
        // d'absence, serait passee sans rien prouver (lecon TASK-1672).
        $this->assertStringContainsString(e(__('admin.users_organization_immutable_hint')), $html);

        // Plus de champ postable, et plus de liste d'Organizations a choisir.
        $this->assertStringNotContainsString('name="organization_id"', $html);
        $this->assertStringNotContainsString(e($this->b->name), $html);
    }
}
