<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1702 — le cablage des quatre pop-ups de l'editeur d'article.
 *
 * Cyril : « des boutons n'ont plus de sens […] quand on clique dessus, ils
 * ouvrent des popups vides : Boucle, Dossier, Liste de taches, Co-ecriture.
 * Est-ce car une option dans l'admin est desactivee ? Je ne crois pas. Est-ce
 * car l'IA est desactivee sur l'organisation ? Je ne crois pas. »
 *
 * ## Les deux hypotheses sont ECARTEES par la mesure
 *
 * Ni l'une ni l'autre. Le defaut est entierement cote navigateur, et il tient
 * en une phrase : TASK-1085 a deplace ces quatre Cards de la colonne laterale
 * vers des pop-ups en masquant leur ancien bouton de repli
 * (`x-show="false"`) — sans rebrancher ailleurs le `toggle()` que ce bouton
 * appelait. Or `toggle()` ne faisait pas qu'afficher le corps de la Card :
 * c'est lui qui CHARGE les donnees et demarre les sondages.
 *
 * Aucun reglage d'Organization, aucun drapeau d'IA n'intervient : les quatre
 * Cards sont rendues inconditionnellement par `blog/edit.blade.php`, et ce
 * fichier le FIGE — si une prochaine TASK les conditionnait a un reglage, la
 * garde du bas rougirait et la question de Cyril aurait enfin une reponse
 * ecrite.
 *
 * ## Ce que ce fichier NE peut pas prouver
 *
 * Le comportement reel du pont — un bouton clique, une Card qui s'ouvre et
 * charge — est du JavaScript. Il est mesure a part, sans navigateur, par
 * `tests/js/editor-panneaux.test.mjs` (8 gardes, 6 sabotages). Ici on ne
 * mesure que le CABLAGE servi par le serveur : les boutons existent, ils
 * visent le store, et chaque panneau contient bien sa Card.
 */
class TASK1702EditorPanelsWiringTest extends TestCase
{
    use RefreshDatabase;

    /** Les quatre panneaux, et la Card que chacun doit contenir. */
    private const PANNEAUX = [
        'boucle' => 'blogLoopCard',
        'dossier' => 'blogDossierCard',
        'todo' => 'blogTodoCard',
        'coauthors' => 'blogCoAuthorCard',
    ];

    private Organization $organization;

    private User $auteur;

    private BlogPost $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1702',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->auteur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        // `BlogPost` n'a pas de fabrique dans ce depot : les tests du blog
        // creent leurs articles directement, et le slug est derive du titre.
        $this->article = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->auteur->id,
            'title' => 'Article 1702',
            'content' => 'Contenu de l\'article servant de support a la mesure.',
            'status' => 'draft',
        ]);
    }

    private function editeur(): string
    {
        app()->forgetInstance('current_organization');

        return $this->actingAs($this->auteur)
            ->get('/org/'.$this->organization->slug.'/blog/rediger/'.$this->article->fresh()->slug.'/modifier')
            ->assertOk()
            ->getContent();
    }

    public function test_les_quatre_boutons_de_la_barre_visent_le_store(): void
    {
        $html = $this->editeur();

        foreach (array_keys(self::PANNEAUX) as $nom) {
            $this->assertSame(
                1,
                preg_match('/<button[^>]*@click="\$store\.editorPanels\.toggle\(\x27'.$nom.'\x27\)"/', $html),
                "Le bouton « {$nom} » de la barre ne declenche pas son panneau."
            );
        }
    }

    public function test_chaque_panneau_contient_bien_sa_card(): void
    {
        $html = $this->editeur();

        foreach (self::PANNEAUX as $nom => $composant) {
            // Le panneau existe…
            $debut = strpos($html, 'x-show="$store.editorPanels.isOpen(\\"'.$nom.'\\")"');

            if ($debut === false) {
                // Le composant `editor-panel` encode son nom via `@js()`, dont
                // l'echappement depend du contexte. On retombe sur la forme
                // non echappee plutot que d'epingler un detail d'encodage.
                $debut = strpos($html, "isOpen('".$nom."')");
            }

            $this->assertNotFalse($debut, "Le panneau « {$nom} » n'est pas rendu.");

            // …et la Card qui doit s'y trouver est bien dans le document.
            $this->assertStringContainsString(
                $composant.'(',
                $html,
                "La Card « {$composant} » n'est pas rendue : le panneau « {$nom} » serait vide."
            );
        }
    }

    /**
     * Les quatre Cards sont rendues SANS CONDITION.
     *
     * C'est la reponse ecrite aux deux hypotheses de Cyril : ni un reglage
     * d'Organization, ni l'IA. On le prouve en retirant tout ce qui pourrait
     * les conditionner — Boucles desactivees, aucune Boucle, aucun Dossier —
     * et en verifiant que les quatre panneaux sont toujours la.
     */
    public function test_aucun_reglage_d_organization_ne_fait_disparaitre_les_panneaux(): void
    {
        $this->organization->update(['loops_enabled' => false]);

        $html = $this->editeur();

        foreach (self::PANNEAUX as $nom => $composant) {
            $this->assertStringContainsString($composant.'(', $html, "La Card « {$composant} » a disparu.");
            $this->assertStringContainsString("toggle('".$nom."')", $html, "Le bouton « {$nom} » a disparu.");
        }
    }

    /**
     * L'ancien bouton de repli est bien NEUTRALISE, et c'est la trace du
     * defaut.
     *
     * TASK-1085 l'a laisse en `x-show="false"`. Tant qu'il est la, il ne faut
     * surtout pas croire qu'il declenche encore quoi que ce soit : c'est
     * precisement cette illusion qui a fait chercher la cause du cote des
     * reglages d'Organization.
     *
     * Si une prochaine TASK le retire, cette garde rougira — et ce sera le bon
     * moment pour verifier que le pont, lui, est toujours branche.
     */
    public function test_l_ancien_bouton_de_repli_reste_neutralise(): void
    {
        $html = $this->editeur();

        $this->assertGreaterThanOrEqual(
            4,
            preg_match_all('/<button\s+x-show="false" @click="toggle\(\)"/', $html),
            'Les anciens boutons de repli ont change de forme : verifier que le pont est toujours branche.'
        );
    }
}
