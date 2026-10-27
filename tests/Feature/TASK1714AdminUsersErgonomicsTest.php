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
        // L'organisation exige une validation : c'est ce qui rend la pastille
        // « en attente d'approbation » RENDABLE plus bas. Sans elle, la garde
        // de separation posee sur cette pastille serait vacante.
        $organisation = Organization::factory()->create([
            'slug' => 'ergo-1714', 'is_active' => true, 'requires_account_approval' => true,
        ]);
        app()->instance('current_organization', $organisation);

        $admin = User::factory()->create([
            'organization_id' => $organisation->id, 'is_admin' => true,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);

        // Adresse longue ET pastille : sans la pastille, la garde de separation
        // ci-dessous serait VACANTE — elle serait vraie avec ou sans le
        // correctif. Ce compte n'a pas verifie son email, il en porte donc une.
        User::factory()->create([
            'organization_id' => $organisation->id,
            'email' => 'une-adresse-vraiment-tres-longue-qui-deborde@sous-domaine.example.test',
            'email_verified_at' => null, 'approved_at' => now(),
        ]);

        // Et un second porteur, pour l'AUTRE pastille.
        User::factory()->create([
            'organization_id' => $organisation->id,
            'email' => 'une-autre-adresse-tout-aussi-longue-en-attente@sous-domaine.example.test',
            'email_verified_at' => now(), 'approved_at' => null,
        ]);

        return $this->actingAs($admin)->get('/admin/users')->assertOk()->getContent();
    }

    /**
     * La pastille `$attribut` vit-elle HORS de l'element tronque de SA ligne ?
     *
     * Le point delicat est « de sa ligne ». La premiere version de cette garde
     * mesurait la region du PREMIER porteur d'adresse du tableau — celui de
     * l'administrateur, qui est verifie et approuve, et ne porte donc aucune
     * pastille. L'absence etait vraie des deux cotes : le sabotage qui reunit
     * les deux elements restait VERT (lecon T1707, deux fois).
     *
     * On part donc de la PASTILLE, et on remonte au porteur le plus proche
     * AVANT elle. Entre les deux, la fermeture `</p>` du porteur doit
     * apparaitre : sinon la pastille est dedans, et `truncate` l'emporte.
     */
    private function assertPastilleHorsDuTronque(string $html, string $attribut): void
    {
        $pastille = strpos($html, $attribut);
        $this->assertNotFalse($pastille, "Le banc ne rend aucune pastille « {$attribut} » a separer.");

        $porteur = strrpos(substr($html, 0, $pastille), 'data-marqueur="email-complet"');
        $this->assertNotFalse($porteur, 'Aucun porteur d\'adresse complete ne precede la pastille.');

        $this->assertStringContainsString(
            '</p>',
            substr($html, $porteur, $pastille - $porteur),
            "La pastille « {$attribut} » vit DANS l'element tronque : une adresse longue l'emporte avec elle.",
        );
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

        $this->assertPastilleHorsDuTronque($html, 'data-user-email-unverified');
        $this->assertPastilleHorsDuTronque($html, 'data-user-pending-approval');
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
     * Toutes les actions sont DIRECTEMENT visibles (arbitrage Cyril, 27/10).
     *
     * Une premiere version repliait les actions secondaires dans un
     * `<details>`. Cyril : « supprimer les colonnes etait pour gagner de la
     * place pour la colonne Actions ». La place recuperee partait en fait a la
     * colonne Utilisateur, et le repli restait. Le repli disparait donc, et la
     * colonne Actions prend la largeur — mesuree a 368 px, pour onze actions
     * sur trois lignes et des lignes de 97 px.
     */
    public function test_toutes_les_actions_sont_directement_visibles(): void
    {
        $html = $this->ecranAdmin();

        $this->assertStringNotContainsString('data-marqueur="autres-actions"', $html, 'Un repli d\'actions subsiste.');

        // DEUX decisions, et il faut les deux : elargir Actions ne suffit pas.
        //
        // La colonne Actions porte une largeur plancher, ET la colonne
        // Utilisateur un plafond. Sans le plafond, toute largeur rendue par les
        // colonnes repliees repart chez Utilisateur — c'est ce que la mesure a
        // montre (414 px pour Actions a 368).
        //
        // La valeur exacte n'est pas figee : c'est un reglage visuel qui peut
        // bouger. Ce qui ne doit pas bouger, c'est qu'elles EXISTENT.
        $this->assertMatchesRegularExpression(
            '/<td class="sticky right-0[^"]*w-full[^"]*min-w-\[\d+rem\]/',
            $html,
            'La colonne Actions n\'absorbe pas la largeur disponible : sans `w-full`, la place liberee repart chez Utilisateur.',
        );
        $this->assertMatchesRegularExpression(
            '/<td class="px-4 py-3[^"]*md:max-w-\[\d+rem\]/',
            $html,
            'La colonne Utilisateur n\'a pas de plafond : elle absorbera la place rendue.',
        );

        // Et les actions de bout de liste sont rendues, pas seulement les
        // premieres : une garde qui ne verifie que « Modifier » passerait sur
        // une colonne amputee.
        foreach (['/ban"', '/toggle-admin', '/verify-email', '/send-password-reset'] as $action) {
            $this->assertStringContainsString($action, $html, "L'action « {$action} » a disparu de la colonne.");
        }
    }

    // ── Les quatre colonnes detaillees (demande Cyril, 27/10) ───────────────

    /** @return list<string> les intitules de colonne presents dans le `<thead>` */
    private function colonnes(string $html): array
    {
        $debut = strpos($html, '<thead');
        $fin = strpos($html, '</thead>', (int) $debut);
        $entete = substr($html, (int) $debut, (int) $fin - (int) $debut);

        $trouvees = [];
        foreach (['Points', 'Services', 'Échanges', 'Note', 'Statut', 'Organisation'] as $nom) {
            if (str_contains($entete, '>'.$nom) || str_contains($entete, $nom."\n")) {
                $trouvees[] = $nom;
            }
        }

        return $trouvees;
    }

    public function test_les_colonnes_detaillees_sont_absentes_par_defaut(): void
    {
        $colonnes = $this->colonnes($this->ecranAdmin());

        // « Note » a rejoint les quatre autres au second passage : l'objectif
        // declare par Cyril est « gagner un maximum de place pour la colonne
        // Actions », et une note moyenne ne sert aucun geste d'administration.
        foreach (['Points', 'Services', 'Échanges', 'Statut', 'Note'] as $masquee) {
            $this->assertNotContains($masquee, $colonnes, "La colonne « {$masquee} » parait alors qu'elle devrait etre repliee.");
        }

        // Et celle qui RESTE, sinon la garde passerait sur un tableau vide.
        $this->assertContains('Organisation', $colonnes);
    }

    public function test_la_vue_detaillee_ramene_les_quatre_colonnes(): void
    {
        $organisation = Organization::factory()->create(['slug' => 'ergo-detail-1714', 'is_active' => true]);
        app()->instance('current_organization', $organisation);
        $admin = User::factory()->create([
            'organization_id' => $organisation->id, 'is_admin' => true,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);

        $html = $this->actingAs($admin)->get('/admin/users?detail=1')->assertOk()->getContent();
        $colonnes = $this->colonnes($html);

        foreach (['Points', 'Services', 'Échanges', 'Statut'] as $rendue) {
            $this->assertContains($rendue, $colonnes, "La colonne « {$rendue} » manque en vue detaillee.");
        }
    }

    /**
     * La bascule CONSERVE les filtres et les tris en place.
     *
     * Une bascule qui repart d'une page vierge ferait perdre une recherche en
     * cours — et serait donc inutilisable precisement quand elle sert, c'est-a-dire
     * au milieu d'un depannage.
     */
    public function test_la_bascule_conserve_les_filtres_en_place(): void
    {
        $organisation = Organization::factory()->create(['slug' => 'ergo-filtre-1714', 'is_active' => true]);
        app()->instance('current_organization', $organisation);
        $admin = User::factory()->create([
            'organization_id' => $organisation->id, 'is_admin' => true,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);

        $html = $this->actingAs($admin)->get('/admin/users?detail=1&search=dupont')->assertOk()->getContent();

        $debut = strpos($html, 'data-marqueur="bascule-colonnes"');
        $this->assertNotFalse($debut, 'La bascule est absente.');

        // On lit le `href` de CE lien, pas de la page : plusieurs liens portent
        // des parametres de requete.
        $avant = substr($html, 0, $debut);
        $href = substr($avant, (int) strrpos($avant, 'href="') + 6);
        $href = substr($href, 0, (int) strpos($href, '"'));

        $this->assertStringContainsString('search=dupont', $href, 'La bascule perd la recherche en cours.');
        $this->assertStringNotContainsString('detail=1', $href, 'La bascule ne revient pas a la vue compacte.');
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
