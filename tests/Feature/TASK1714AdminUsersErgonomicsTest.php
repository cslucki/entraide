<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1714 volet 3 — ergonomie de `/admin/users`.
 *
 * ## Ce que ces gardes prouvent, et ce qu'elles ne prouvent PAS
 *
 * Elles mesurent du BALISAGE. Une hauteur de ligne, un debordement, une
 * colonne hors cadre ne se mesurent qu'au navigateur — et ils l'ont ete, avant
 * et apres (consigne dans le TASK file) :
 *
 * | mesure | avant | apres |
 * |---|---|---|
 * | hauteur de ligne mediane | 367 px | 85 px |
 * | colonne Actions a l'ecran | NON | oui, aux deux largeurs |
 * | debordement mobile (390 px) | 396 px | 207 px, Actions collante |
 *
 * Ces gardes figent donc les DECISIONS qui produisent ces chiffres, pour
 * qu'une reecriture future les defasse bruyamment. Elles ne remplacent pas la
 * recette : asserter le balisage ne prouve pas le rendu (lecon T1667).
 */
class TASK1714AdminUsersErgonomicsTest extends TestCase
{
    use RefreshDatabase;

    private function ecranAdmin(): string
    {
        $organisation = Organization::factory()->create(['slug' => 'ergo-1714', 'is_active' => true]);
        app()->instance('current_organization', $organisation);

        $admin = User::factory()->create([
            'organization_id' => $organisation->id, 'is_admin' => true,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);

        // Adresse longue ET pastille : sans la pastille, la garde d'absence
        // ci-dessous serait VACANTE — elle serait vraie avec ou sans le
        // correctif. Ce compte n'a pas verifie son email, il en porte donc une.
        User::factory()->create([
            'organization_id' => $organisation->id,
            'email' => 'une-adresse-vraiment-tres-longue-qui-deborde@sous-domaine.example.test',
            'email_verified_at' => null, 'approved_at' => now(),
        ]);

        return $this->actingAs($admin)->get('/admin/users')->assertOk()->getContent();
    }

    /**
     * L'adresse est tronquee AVEC un recours.
     *
     * Une troncature sans acces au texte complet n'est pas une troncature,
     * c'est une perte : l'administrateur voit « une-adresse-vraiment… » et n'a
     * aucun moyen de lire la suite.
     */
    public function test_une_adresse_longue_porte_son_texte_complet_en_title(): void
    {
        $html = $this->ecranAdmin();

        $this->assertStringContainsString(
            'title="une-adresse-vraiment-tres-longue-qui-deborde@sous-domaine.example.test"',
            $html,
        );
    }

    /**
     * L'adresse et ses pastilles vivent dans DEUX elements.
     *
     * Elles partageaient un `<p class="truncate">` — donc `white-space: nowrap`
     * et `overflow: hidden`. Une adresse longue emportait la pastille « email
     * non verifie » avec elle : l'etat disparaissait sans que rien ne le dise.
     */
    public function test_l_adresse_et_ses_pastilles_ne_partagent_pas_le_meme_element_tronque(): void
    {
        $html = $this->ecranAdmin();

        // D'ABORD : la pastille EXISTE. C'est ce qui rend l'absence mesurable
        // plus bas — une garde posee sur l'absence d'un marqueur qui n'est
        // jamais rendu est vacante, et reste verte sous sabotage.
        $this->assertStringContainsString('data-user-email-unverified', $html, 'Le banc ne produit aucune pastille a separer.');

        $debut = strpos($html, 'data-marqueur="email-complet"');
        $this->assertNotFalse($debut, 'Le porteur de l\'adresse complete est absent.');

        // ENSUITE : le `<p>` tronque se FERME avant que la pastille ne commence.
        $fin = strpos($html, '</p>', $debut);
        $this->assertNotFalse($fin);
        $region = substr($html, $debut, $fin - $debut);

        $this->assertStringNotContainsString('data-user-email-unverified', $region);
        $this->assertStringNotContainsString('data-user-pending-approval', $region);
    }

    /**
     * La colonne Actions est COLLANTE, et porte un fond opaque.
     *
     * Le fond n'est pas decoratif : sans lui, le contenu des autres colonnes
     * defilerait visiblement sous celle-ci.
     */
    public function test_la_colonne_actions_est_collante_avec_un_fond_opaque(): void
    {
        $html = $this->ecranAdmin();

        $debut = strpos($html, 'sticky right-0');
        $this->assertNotFalse($debut, 'Aucune colonne collante.');

        $this->assertStringContainsString('>Actions</th>', $html);
        $this->assertMatchesRegularExpression(
            '/<th class="sticky right-0[^"]*bg-gray-50[^"]*dark:bg-gray-700/',
            $html,
            'L\'en-tete collant n\'a pas de fond opaque.',
        );
        $this->assertMatchesRegularExpression(
            '/<td class="sticky right-0[^"]*bg-white[^"]*dark:bg-gray-800/',
            $html,
            'La cellule collante n\'a pas de fond opaque.',
        );
    }

    /**
     * Les actions secondaires sont repliees ; les urgentes restent dehors.
     *
     * Onze actions a plat produisaient des lignes de 367 px. Enfouir « Valider
     * compte » ou « Modifier » ferait au contraire payer un clic de plus a ce
     * qui est urgent ou frequent.
     */
    public function test_les_actions_secondaires_sont_repliees_et_les_urgentes_restent_visibles(): void
    {
        $html = $this->ecranAdmin();

        $repli = strpos($html, 'data-marqueur="autres-actions"');
        $this->assertNotFalse($repli, 'Aucun repli d\'actions secondaires.');

        // Les POSITIONS, et non une fenetre de N caracteres : une assertion
        // par fenetre se casse au premier retrait de ligne modifie, et ne dit
        // rien de l'ORDRE, qui est pourtant tout le sujet.
        $modifier = strpos($html, '/admin/users/');
        $bannir = strpos($html, '/ban"');

        $this->assertNotFalse($modifier, 'Le lien « Modifier » est absent.');
        $this->assertNotFalse($bannir, 'L\'action « Bannir » est absente.');

        $this->assertLessThan($repli, $modifier, '« Modifier » est enfoui dans le repli.');
        $this->assertGreaterThan($repli, $bannir, '« Bannir » n\'est pas replie.');
    }

    /**
     * La largeur minimale du tableau ne s'applique qu'a partir de `md`.
     *
     * Elle protege la colonne Organisation, elle-meme `hidden md:table-cell` :
     * en dessous, elle ne protegeait plus rien et forcait 736 px sur un
     * telephone de 390.
     */
    public function test_la_largeur_minimale_du_tableau_est_bornee_au_desktop(): void
    {
        $html = $this->ecranAdmin();

        $this->assertStringContainsString('md:min-w-[46rem]', $html);
        $this->assertStringNotContainsString('class="w-full min-w-[46rem]', $html);
    }
}
