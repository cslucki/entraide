<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Tests\TestCase;

/**
 * TASK-1773 (MICRO_UX) — LES TEXTES DU FORMULAIRE « PROPOSER DE L'AIDE ».
 *
 * Le formulaire ne parle plus d'un service vendu contre des points, mais du
 * temps qu'on partage. Rien d'autre ne change : memes champs, memes bornes,
 * meme colonne `points_cost`, meme regle « 1 minute = 1 point ».
 *
 * Ces gardes defendent DEUX promesses, et la seconde est la plus fragile :
 *
 *   1. les nouveaux textes sont a l'ecran, a la creation ET a l'edition —
 *      une surface sur deux, c'est ce qu'une recette attrape ;
 *   2. **le formulaire de DEMANDE d'aide n'a pas bouge.** Les cles generiques
 *      (`marketplace.title`, `.description`, `.delivery_mode`,
 *      `.points_help_title`, `.points_one_minute`) lui servent encore. Les
 *      modifier en place aurait change les deux surfaces d'un coup, SANS la
 *      moindre erreur nulle part. C'est pour cela que cette TASK a introduit
 *      des cles dediees plutot que de reecrire les partagees.
 */
class TASK1773TextesProposerDeLAideTest extends TestCase
{
    private Organization $organization;

    private User $membre;

    private Category $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('fr');

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'is_default' => true,
            'locale' => 'fr',
            'service_points_min' => 20,
            'service_points_max' => 70,
        ]);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->categorie = Category::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);
    }

    /** @return list<string> */
    private function textesAttendus(): array
    {
        return [
            'Ce que vous souhaitez apporter au collectif',
            'Partagez vos compétences, votre expérience',
            'Que souhaitez-vous proposer ?',
            'Illustrations (facultatif)',
            'Mots-clés (facultatif)',
            'Comment souhaitez-vous aider ?',
            'Temps estimé pour cette aide',
            'Indiquez le temps que vous prévoyez de consacrer à cette aide.',
            'Proposer cette action sous forme d\'atelier',
            'Publier ma proposition',
            'Comment fonctionne l\'échange de temps',
            'le temps consacré à l\'entraide est comptabilisé en points',
            'Ces points permettent à leur tour',
            'N\'oubliez pas que vous devez présenter votre activité sur votre',
        ];
    }

    public function test_le_formulaire_de_creation_porte_les_nouveaux_textes(): void
    {
        $reponse = $this->actingAs($this->membre)->get(route('services.create'))->assertOk();

        // SANS `false` : la vue echappe, donc « l'echange » sort en
        // « l&#039;echange ». C'est `assertSee` qui doit echapper l'aiguille,
        // pas moi qui dois ecrire du HTML dans un test de TEXTE.
        foreach ($this->textesAttendus() as $texte) {
            $reponse->assertSee($texte);
        }

        // Le lien vers le profil public et son accompagnement sont CONSERVES :
        // la consigne le demande explicitement.
        $reponse->assertSee('profil public');
        $reponse->assertSee(route('profile.edit'), false);
    }

    /** Les bornes de l'Organization restent annoncees : elles n'ont pas bouge. */
    public function test_le_formulaire_annonce_toujours_les_bornes_de_l_organization(): void
    {
        $this->actingAs($this->membre)
            ->get(route('services.create'))
            ->assertOk()
            ->assertSee('Minimum autorisé dans '.$this->organization->name.' : 20 points')
            ->assertSee('Maximum autorisé dans '.$this->organization->name.' : 70 points');
    }

    /** Le champ de duree : meme `name`, memes bornes, et l'equivalence en points. */
    public function test_le_champ_de_duree_reste_le_champ_de_points(): void
    {
        $this->actingAs($this->membre)
            ->get(route('services.create'))
            ->assertOk()
            ->assertSee('name="points_cost"', false)
            ->assertSee('min="20"', false)
            ->assertSee('max="70"', false)
            ->assertSee('data-duration-equivalent', false)
            ->assertSee('Équivalent : :points points', false);
    }

    /** L'edition d'une proposition est la MEME surface : meme vocabulaire. */
    public function test_l_edition_d_une_proposition_parle_le_meme_langage(): void
    {
        $service = Service::factory()
            ->forUser($this->membre)
            ->forCategory($this->categorie)
            ->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->membre)
            ->get(route('services.edit', $service))
            ->assertOk()
            ->assertSee('Temps estimé pour cette aide')
            ->assertSee('Comment fonctionne l\'échange de temps')
            ->assertSee('Illustrations (facultatif)')
            ->assertSee('Proposer cette action sous forme d\'atelier')
            ->assertDontSee('barème de points');
    }

    /**
     * LA GARDE QUI COMPTE : le formulaire de DEMANDE d'aide n'a pas bouge.
     *
     * Il partage avec « Proposer » les cles generiques. Si une TASK future
     * reecrit l'une d'elles au lieu d'en creer une dediee, c'est ici que ca
     * rougira — et nulle part ailleurs, puisque rien ne leve d'erreur.
     */
    public function test_le_formulaire_de_demande_d_aide_est_intact(): void
    {
        $reponse = $this->actingAs($this->membre)->get(route('requests.create'))->assertOk();

        $reponse->assertSee('barème de points');
        $reponse->assertSee('1 minute = 1 point.');

        foreach (['Que souhaitez-vous proposer ?', 'Publier ma proposition', 'Temps estimé pour cette aide'] as $texteDeProposer) {
            $reponse->assertDontSee($texteDeProposer);
        }
    }
}
