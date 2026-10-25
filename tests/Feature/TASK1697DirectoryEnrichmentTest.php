<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1697 correctif 15 — l'annuaire gagne une recherche et quatre informations.
 *
 * Demande de Cyril : chercher par prenom/nom, et montrer sur la carte le site
 * web, LinkedIn, le nombre d'articles (avec un lien) et les Boucles.
 *
 * ## Les trois pieges que ces gardes tiennent
 *
 * 1. **`users.name` porte le nom de FAMILLE** depuis TASK-1399 et `first_name`
 *    est NULLABLE. Chercher sur un seul des deux champs laisserait la moitie
 *    des gens introuvables, et la saisie complete (« cyril s ») n'est contenue
 *    dans AUCUN des deux.
 * 2. **`loop_members.organization_id` est nullable**, et 3 lignes sur 24 y sont
 *    NULL en base de developpement. Filtrer le tenant sur cette colonne
 *    perdrait ces appartenances EN SILENCE. L'autorite est
 *    `loops.organization_id`.
 * 3. **Le compteur d'articles doit dire la meme chose que le profil.** Si les
 *    deux predicats divergent, l'annuaire annonce 3 articles et la fiche en
 *    liste 2.
 */
class TASK1697DirectoryEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1697d',
            'is_active' => true,
            'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);
    }

    // ── La recherche ────────────────────────────────────────────────────────

    public function test_la_recherche_trouve_par_prenom(): void
    {
        $cherche = $this->membre(['first_name' => 'Ariane', 'name' => 'Delcourt']);
        $autre = $this->membre(['first_name' => 'Bastien', 'name' => 'Marchal']);

        $html = $this->annuaire('ariane');

        $this->assertStringContainsString(e($cherche->fullName), $html);
        $this->assertStringNotContainsString(e($autre->fullName), $html);
    }

    public function test_la_recherche_trouve_par_nom_de_famille(): void
    {
        $cherche = $this->membre(['first_name' => 'Ariane', 'name' => 'Delcourt']);
        $autre = $this->membre(['first_name' => 'Bastien', 'name' => 'Marchal']);

        $html = $this->annuaire('delcourt');

        $this->assertStringContainsString(e($cherche->fullName), $html);
        $this->assertStringNotContainsString(e($autre->fullName), $html);
    }

    /**
     * Le cas que ni `first_name` ni `name` ne contient : la saisie complete.
     * C'est la clause sur la concatenation qui le couvre, et elle seule.
     */
    public function test_la_recherche_trouve_sur_la_saisie_complete(): void
    {
        $cherche = $this->membre(['first_name' => 'Ariane', 'name' => 'Delcourt']);
        $autre = $this->membre(['first_name' => 'Bastien', 'name' => 'Marchal']);

        $html = $this->annuaire('ariane del');

        $this->assertStringContainsString(e($cherche->fullName), $html);
        $this->assertStringNotContainsString(e($autre->fullName), $html);
    }

    public function test_la_recherche_ignore_la_casse(): void
    {
        $cherche = $this->membre(['first_name' => 'Ariane', 'name' => 'Delcourt']);

        $this->assertStringContainsString(e($cherche->fullName), $this->annuaire('ARIANE'));
        $this->assertStringContainsString(e($cherche->fullName), $this->annuaire('dElCoUrT'));
    }

    public function test_une_recherche_sans_resultat_le_dit(): void
    {
        $this->membre(['first_name' => 'Ariane', 'name' => 'Delcourt']);

        $html = $this->annuaire('zzzzz');

        $this->assertStringContainsString(e(__('directory.search_no_result')), $html);
    }

    /** La recherche ne doit pas percer le tenant. */
    public function test_la_recherche_ne_sort_pas_de_l_organization(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1697e', 'is_active' => true]);
        $etranger = User::factory()->create([
            'organization_id' => $autreOrg->id,
            'first_name' => 'Ariane',
            'name' => 'Delcourt',
        ]);

        $html = $this->annuaire('ariane');

        $this->assertStringNotContainsString(e($etranger->fullName), $html);
    }

    /** La recherche survit a la pagination : l'URL la porte. */
    public function test_la_recherche_est_portee_par_les_liens_de_pagination(): void
    {
        foreach (range(1, 20) as $i) {
            $this->membre(['first_name' => 'Ariane', 'name' => 'Nom'.$i]);
        }

        $html = $this->annuaire('ariane');

        $this->assertStringContainsString('q=ariane', $html);
    }

    // ── Site web et LinkedIn ────────────────────────────────────────────────

    public function test_le_site_web_et_linkedin_apparaissent_quand_ils_sont_renseignes(): void
    {
        $membre = $this->membre([
            'website' => 'https://exemple-ariane.test/accueil',
            'linkedin_url' => 'https://www.linkedin.com/in/ariane',
        ]);

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-website="'.$membre->id.'"', $html);
        $this->assertStringContainsString('data-member-linkedin="'.$membre->id.'"', $html);
        // seul l'hote est montre, comme sur la fiche profil
        $this->assertStringContainsString('exemple-ariane.test', $html);
    }

    public function test_aucun_lien_web_quand_rien_n_est_renseigne(): void
    {
        $membre = $this->membre(['website' => null, 'linkedin_url' => null]);

        $html = $this->annuaire();

        $this->assertStringContainsString(e($membre->fullName), $html);
        $this->assertStringNotContainsString('data-member-website="'.$membre->id.'"', $html);
        $this->assertStringNotContainsString('data-member-linkedin="'.$membre->id.'"', $html);
    }

    // ── Le compteur d'articles ──────────────────────────────────────────────

    public function test_le_compteur_d_articles_compte_les_articles_publies(): void
    {
        $membre = $this->membre();
        $this->article($membre, 'published');
        $this->article($membre, 'published');

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-articles="'.$membre->id.'"', $html);
        $this->assertStringContainsString('>2</span> '.trans_choice('directory.article_label', 2), $html);
    }

    public function test_un_brouillon_ne_compte_pas(): void
    {
        $membre = $this->membre();
        $this->article($membre, 'draft');

        $html = $this->annuaire();

        $this->assertStringNotContainsString('data-member-articles="'.$membre->id.'"', $html);
    }

    public function test_un_article_d_une_autre_organization_ne_compte_pas(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1697f', 'is_active' => true]);
        $membre = $this->membre();
        $this->article($membre, 'published', $autreOrg->id);

        $html = $this->annuaire();

        $this->assertStringNotContainsString('data-member-articles="'.$membre->id.'"', $html);
    }

    public function test_sans_article_aucun_lien_n_est_affiche(): void
    {
        $membre = $this->membre();

        $html = $this->annuaire();

        $this->assertStringContainsString(e($membre->fullName), $html);
        $this->assertStringNotContainsString('data-member-articles="'.$membre->id.'"', $html);
    }

    // ── Les Boucles ─────────────────────────────────────────────────────────

    public function test_les_boucles_apparaissent_avec_un_lien_scope(): void
    {
        $membre = $this->membre();
        $boucle = $this->boucle('Boucle Alpha');
        $this->appartenance($boucle, $membre, 'member');

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-loop="'.$boucle->id.'"', $html);
        $this->assertStringContainsString(e('Boucle Alpha'), $html);
        $this->assertStringContainsString(
            e(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $boucle->slug])),
            $html
        );
    }

    /** Les trois roles comptent : c'est l'appartenance ACTIVE qui est le critere. */
    public function test_les_trois_roles_sont_listes(): void
    {
        $membre = $this->membre();
        $roles = [];
        foreach (['owner', 'facilitator', 'member'] as $role) {
            $b = $this->boucle('Boucle '.$role);
            $this->appartenance($b, $membre, $role);
            $roles[] = $b;
        }

        $html = $this->annuaire();

        foreach ($roles as $b) {
            $this->assertStringContainsString('data-member-loop="'.$b->id.'"', $html);
        }
    }

    public function test_une_appartenance_inactive_n_est_pas_listee(): void
    {
        $membre = $this->membre();
        $boucle = $this->boucle('Boucle quittee');
        $this->appartenance($boucle, $membre, 'member', 'left');

        $html = $this->annuaire();

        $this->assertStringNotContainsString('data-member-loop="'.$boucle->id.'"', $html);
    }

    /** Meme perimetre que `VisibleLoops` : les archivees restent dehors. */
    public function test_une_boucle_archivee_n_est_pas_listee(): void
    {
        $membre = $this->membre();
        $boucle = $this->boucle('Boucle archivee', 'archived');
        $this->appartenance($boucle, $membre, 'owner');

        $html = $this->annuaire();

        $this->assertStringNotContainsString('data-member-loop="'.$boucle->id.'"', $html);
    }

    public function test_une_boucle_d_une_autre_organization_n_est_pas_listee(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1697g', 'is_active' => true]);
        $membre = $this->membre();
        $boucle = Loop::factory()->create([
            'organization_id' => $autreOrg->id,
            'name' => 'Boucle etrangere',
            'slug' => 'boucle-etrangere',
            'status' => 'active',
        ]);
        $this->appartenance($boucle, $membre, 'member');

        $html = $this->annuaire();

        $this->assertStringNotContainsString('data-member-loop="'.$boucle->id.'"', $html);
        $this->assertStringNotContainsString(e('Boucle etrangere'), $html);
    }

    /**
     * LE piege : `loop_members.organization_id` est nullable. Une appartenance
     * qui ne porte pas cette colonne doit quand meme etre listee — c'est
     * l'organisation de la BOUCLE qui tranche.
     */
    public function test_une_appartenance_sans_organization_id_est_quand_meme_listee(): void
    {
        $membre = $this->membre();
        $boucle = $this->boucle('Boucle sans org sur la ligne');

        LoopMember::withoutEvents(function () use ($boucle, $membre) {
            LoopMember::query()->create([
                'loop_id' => $boucle->id,
                'user_id' => $membre->id,
                'role' => 'member',
                'status' => 'active',
                'organization_id' => null,
            ]);
        });

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $boucle->id,
            'user_id' => $membre->id,
            'organization_id' => null,
        ]);

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-loop="'.$boucle->id.'"', $html);
    }

    // ── Le libelle « propositions » ─────────────────────────────────────────

    public function test_le_libelle_dit_proposition_et_plus_micro_service(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);

        // Le nombre et le libelle sont separes par un `</span>` : sur le HTML
        // brut, « 1 proposition » n'existe pas. Meme normalisation que
        // `TASK1409DirectoryServiceLabelAgreementTest`.
        $texte = html_entity_decode(strip_tags($this->annuaire()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/\s+/u', ' ', $texte);

        $this->assertStringContainsString('1 '.trans_choice('directory.service_label', 1), $html);

        // Assertion BORNEE au compteur, pas a la page : « micro-service »
        // subsiste legitimement dans la navigation, qui lit toujours le terme
        // global `$T['service']` de `config/terms.php`. C'est signale a Cyril,
        // pas corrige — il n'a demande que l'annuaire.
        $this->assertStringNotContainsString('1 '.config('terms.service'), $html);
        $this->assertStringNotContainsString('1 '.config('terms.services'), $html);
    }

    // ── La rangee de compteurs disparait a zero (correctif 16) ──────────

    public function test_zero_proposition_et_zero_demande_font_disparaitre_la_mention(): void
    {
        $membre = $this->membre();

        $html = $this->annuaire();

        $this->assertStringContainsString(e($membre->fullName), $html);
        $this->assertStringNotContainsString('data-member-counters="'.$membre->id.'"', $html);
    }

    public function test_une_seule_proposition_suffit_a_faire_apparaitre_la_mention(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-counters="'.$membre->id.'"', $html);
    }

    /**
     * Le piege du correctif 16 : le lien « articles » vit dans la MEME rangee.
     * Il doit survivre a la disparition des deux compteurs d'echange.
     */
    public function test_le_lien_articles_survit_a_la_disparition_des_compteurs(): void
    {
        $membre = $this->membre();
        $this->article($membre, 'published');

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-counters="'.$membre->id.'"', $html);
        $this->assertStringContainsString('data-member-articles="'.$membre->id.'"', $html);

        $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texte = preg_replace('/\s+/u', ' ', $texte);
        $this->assertStringNotContainsString('0 '.trans_choice('directory.service_label', 0), $texte);
        $this->assertStringNotContainsString('0 '.trans_choice('directory.request_label', 0), $texte);
    }

    // ── Les compteurs menent aux listes (correctif 17) ──────────────────

    public function test_le_compteur_de_propositions_mene_a_la_section_du_profil(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-services="'.$membre->id.'"', $html);
        $this->assertStringContainsString(
            e(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $membre->profileRouteKey(),
            ]).'#propositions'),
            $html
        );
    }

    public function test_le_compteur_de_demandes_mene_a_la_section_du_profil(): void
    {
        $membre = $this->membre();
        $this->demande($membre);

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-requests="'.$membre->id.'"', $html);
        $this->assertStringContainsString(
            e(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $membre->profileRouteKey(),
            ]).'#demandes'),
            $html
        );
    }

    /** Un compteur a zero ne promet rien : il n'est pas lie. */
    public function test_un_compteur_a_zero_n_est_pas_lie(): void
    {
        $membre = $this->membre();
        $this->demande($membre);          // 0 proposition, 1 demande

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-requests="'.$membre->id.'"', $html);
        $this->assertStringNotContainsString('data-member-services="'.$membre->id.'"', $html);
    }

    /** Les ancres existent reellement sur la fiche profil. */
    public function test_les_ancres_visees_existent_sur_le_profil(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);
        $this->demande($membre);

        $html = $this->actingAs($membre)
            ->get(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $membre->profileRouteKey(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="propositions"', $html);
        $this->assertStringContainsString('id="demandes"', $html);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function membre(array $attributs = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'first_name' => 'Ariane',
            'name' => 'Delcourt',
        ], $attributs));
    }

    private function boucle(string $nom, string $statut = 'active'): Loop
    {
        return Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => $nom,
            'slug' => Str::slug($nom).'-'.Str::random(5),
            'status' => $statut,
        ]);
    }

    private function appartenance(Loop $boucle, User $membre, string $role, string $statut = 'active'): LoopMember
    {
        return LoopMember::query()->create([
            'loop_id' => $boucle->id,
            'user_id' => $membre->id,
            'role' => $role,
            'status' => $statut,
            'organization_id' => $boucle->organization_id,
        ]);
    }

    private function proposition(User $membre): Service
    {
        return Service::factory()->create([
            'user_id' => $membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
        ]);
    }

    private function demande(User $membre): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'user_id' => $membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'open',
        ]);
    }

    private function article(User $auteur, string $statut, ?string $organizationId = null): BlogPost
    {
        return BlogPost::query()->create([
            'user_id' => $auteur->id,
            'organization_id' => $organizationId ?? $this->organization->id,
            'title' => 'Article '.Str::random(6),
            'slug' => 'article-'.Str::random(8),
            'content' => 'Contenu de mesure.',
            'status' => $statut,
            'published_at' => $statut === 'published' ? now()->subDay() : null,
        ]);
    }

    private function annuaire(?string $q = null): string
    {
        $url = route('organization.members.index', ['organization' => $this->organization->slug]);

        if ($q !== null) {
            $url .= '?q='.urlencode($q);
        }

        return $this->actingAs($this->membre(['first_name' => 'Vi', 'name' => 'Siteur']))
            ->get($url)
            ->assertOk()
            ->getContent();
    }
}
