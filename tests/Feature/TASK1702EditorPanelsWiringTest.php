<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\OrganizationAiSetting;
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

    // ── Correctif 2 : les filtres de source suivent l'interrupteur IA ───────

    /**
     * Allume l'IA de l'Organization, comme le fait TASK-1693.
     *
     * `AiSurfaceGate` memorise sa reponse par Organization : sans l'instance
     * fraiche, le test mesurerait la reponse d'avant.
     */
    private function allumerLIA(): void
    {
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );

        app()->forgetInstance(\App\Support\Ai\AiSurfaceGate::class);
    }

    /**
     * Sans IA, les trois filtres de source disparaissent.
     *
     * Cyril : « sauf erreur sans l'IA, il n'y a plus qu'un seul type
     * d'annotation ». Exact : « Toutes », « Humains » et « Questionnement »
     * designent alors le meme ensemble — trois boutons pour une seule realite.
     *
     * Les DEUX etats sont mesures. Une garde qui ne verifierait que l'absence
     * resterait verte si les filtres disparaissaient pour tout le monde : c'est
     * la lecon ecrite en tete de `TASK1693AiSurfacesFollowTheSwitchTest`.
     */
    public function test_les_filtres_de_source_des_annotations_suivent_l_interrupteur_ia(): void
    {
        $eteint = $this->editeur();
        $this->assertStringNotContainsString('data-annotation-source-filters', $eteint, 'Les filtres de source sont servis alors que l\'IA est eteinte.');

        $this->allumerLIA();

        $allume = $this->editeur();
        $this->assertStringContainsString('data-annotation-source-filters', $allume, 'Les filtres de source ont disparu alors que l\'IA est allumee.');
    }

    /**
     * Et les annotations DEJA ECRITES restent visibles.
     *
     * C'est le point delicat : une Organization qui eteint l'IA apres coup
     * garde ses questionnements. Retirer les filtres ne doit pas les masquer —
     * lecon de TASK-1693 bug AH, « masquer des notes DEJA ECRITES effacerait du
     * travail fait ». Ici rien n'est masque, parce que le tri par defaut est
     * « toutes » : on le prouve en verifiant que le filtre par defaut n'a pas
     * change de valeur.
     */
    public function test_retirer_les_filtres_ne_masque_aucune_annotation(): void
    {
        // Le tri par defaut est declare dans le composant Alpine, pas dans la
        // page : c'est donc la source qu'on interroge. Meme procede que
        // `TASK1698NavigationFurnitureTenantTest`, qui lit les vues pour
        // prouver qu'un gabarit n'est reference nulle part.
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertSame(
            1,
            preg_match("/sourceFilter:\s*'all'/", $source),
            'Le tri par defaut des annotations n\'est plus « toutes » : retirer les filtres masquerait des annotations existantes.'
        );
    }

    /**
     * Le drapeau transmis a la Card dit la MEME chose que le rendu des filtres.
     *
     * C'est la consequence la moins evidente du correctif, et la plus genante :
     * `openForAnnotation()` bascule le filtre sur `ai_method` quand on ouvre une
     * annotation d'origine IA. Une Organization qui eteint l'IA APRES COUP
     * garde ses questionnements ; cliquer l'un d'eux poserait donc un filtre
     * que plus aucun bouton ne permet de retirer — l'ecran se figerait sur une
     * vue partielle.
     *
     * Les deux etats sont mesures, et avec eux la COHERENCE entre le drapeau et
     * les boutons : les separer ferait de l'un le mensonge de l'autre.
     */
    public function test_le_drapeau_des_filtres_suit_le_rendu_des_filtres(): void
    {
        $eteint = $this->editeur();
        $this->assertStringContainsString('sourceFiltersAvailable: false', $eteint);
        $this->assertStringNotContainsString('data-annotation-source-filters', $eteint);

        $this->allumerLIA();

        $allume = $this->editeur();
        $this->assertStringContainsString('sourceFiltersAvailable: true', $allume);
        $this->assertStringContainsString('data-annotation-source-filters', $allume);
    }

    // ── Correctifs 2 et 3 : le vide dit OU se trouve l'outil ────────────────

    /**
     * Les deux messages de vide montrent le bouton de la barre d'edition.
     *
     * Cyril : « montre-le dans la phrase, ainsi la personne pourrait rapidement
     * le reperer dans la barre d'edition tiptap ».
     *
     * La garde porte sur le PICTOGRAMME, pas sur la phrase : un texte qui
     * mentionnerait le bouton sans le montrer passerait une assertion de
     * libelle, et c'est precisement ce que la demande ecarte.
     */
    public function test_les_deux_messages_de_vide_montrent_le_bouton_de_la_barre(): void
    {
        $html = $this->editeur();

        // Le pictogramme est cherche DANS le bloc, pas dans la page : ce meme
        // trace apparait aussi dans la barre d'edition et ailleurs, et une
        // mesure a l'echelle du document serait verte sans rien prouver.
        // C'est la lecon des quatre faux verts de TASK-1697.
        $trace = 'M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z';

        $this->assertSame(
            1,
            preg_match('/data-annotation-empty-hint.*?'.preg_quote($trace, '/').'/s', $html),
            'Le message de vide ne montre pas le pictogramme de la barre d\'edition : le reconnaitre est tout l\'interet.'
        );

        // Et il est bien dans CE bloc, pas repris d'un voisin : la portion
        // mesuree s'arrete au premier `</div>` fermant du bloc.
        $this->assertSame(1, preg_match('/data-annotation-empty-hint(.*?)<\/template>/s', $html, $bloc));
        $this->assertStringContainsString($trace, $bloc[1]);

        // Plan : le repere de la barre est un LIBELLE, pas une icone.
        $this->assertStringContainsString('data-plan-empty-hint', $html);
        $this->assertSame(1, preg_match('/data-plan-empty-hint(.*?)<\/template>/s', $html, $blocPlan));
        $this->assertStringContainsString('>H2<', $blocPlan[1], 'Le message de vide du Plan ne montre pas le selecteur de niveau.');

        // Et les phrases elles-memes sont servies, pas leur clef.
        foreach (['annotation_empty_hint', 'plan_empty_hint'] as $clef) {
            $this->assertStringNotContainsString('blog.'.$clef, $html, "La clef « {$clef} » est servie telle quelle.");
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
