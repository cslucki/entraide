<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dans un Drive de Boucle, la colonne dit QUI A DEPOSE — pas « Proprietaire ».
 *
 * ## Le defaut
 *
 * L'en-tete annoncait « Proprietaire ». Sous cet en-tete on lisait :
 *
 * - pour un fichier, `dossier_files.uploaded_by` — l'uploader ;
 * - pour un Article, `blog_posts.user_id` — l'auteur du contenu ;
 * - pour un sous-dossier, le NOM DE LA BOUCLE, avec un avatar vide, puisque
 *   `governingDossier()->owner` est `null` sur un Dossier tenu par une Boucle.
 *
 * Deux depots et un nom d'entite presentes comme trois proprietes.
 *
 * ## La decision
 *
 * En-tete **« Ajoute par »**, exact pour ce qu'il montre :
 *
 * - fichier => `uploaded_by` ;
 * - Article => `dossier_blog_posts.added_by`, **pas** l'auteur : dans un Drive
 *   partage, celui qui ecrit et celui qui range different souvent ;
 * - Dossier => rien. La table `dossiers` ne porte que `owner_id` : ni
 *   `added_by`, ni `created_by`. Plutot que d'afficher une propriete a la place
 *   d'un depot, la cellule se tait.
 *
 * Aucune migration, aucune notion de propriete inventee, aucun droit touche.
 */
class TASK1146LoopDriveAddedByTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    /** m3 — membre de la Boucle, auteur des Articles. */
    private User $m3;

    /** m4 — membre de la Boucle, celui qui depose. */
    private User $m4;

    private Loop $loop;

    private Dossier $racineBoucle;

    private Dossier $sousDossierBoucle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->m3 = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Marceline']);
        $this->m4 = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Bastien']);

        app()->instance('current_organization', $this->org);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
            'type' => 'general',
            'created_by' => $this->m3->id,
            'name' => 'Boucle Atelier',
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->m3->id, 'joined_at' => now(),
        ]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->m4->id, 'role' => 'member', 'joined_at' => now(),
        ]);

        $this->racineBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'loop_id' => $this->loop->id,
            'name' => 'Documents',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
        $this->sousDossierBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $this->racineBoucle->id,
            'name' => 'Communication',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
    }

    private function fichier(Dossier $dossier, string $nom, User $deposant): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $deposant->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.Str::random(12).'.txt',
            'original_name' => $nom,
            'display_name' => $nom,
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $dossier->id.$nom),
            'source' => 'upload',
        ]);
    }

    /** Un Article ECRIT par l'un et RANGE ici par l'autre — le cas qui tranche. */
    private function article(Dossier $dossier, string $titre, User $auteur, User $deposant): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $dossier->organization_id,
            'user_id' => $auteur->id,
            'title' => $titre,
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        DossierBlogPost::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $deposant->id,
        ]);

        return $post;
    }

    private function ouvrir(User $acteur, ?Dossier $cible = null): TestResponse
    {
        return $this->actingAs($acteur)->get(route('organization.dossiers.show', [
            'organization' => $this->org->slug,
            'dossier' => ($cible ?? $this->racineBoucle)->getKey(),
        ]));
    }

    /**
     * Les en-tetes de colonnes, lus en EN.
     *
     * En FR, « Ajoute par » est aussi la valeur de la cle preexistante
     * `dossiers.file_uploaded_by` et « Partage » est une sous-chaine de
     * « Partage/Partager » : les trois libelles EN — Owner, Added by, Sharing —
     * sont sans collision.
     *
     * @return list<string>
     */
    private function enTetes(string $html): array
    {
        preg_match_all('/<div>([^<]+)<\/div>/', $html, $m);

        return array_map('trim', $m[1]);
    }

    // =====================================================================
    // 1 et 2 — le fichier montre bien qui l'a depose
    // =====================================================================

    public function test_a_file_shows_the_member_who_uploaded_it(): void
    {
        $fichier = $this->fichier($this->racineBoucle, 'Rapport.pdf', $this->m3);

        // La donnee affichee vient de `uploaded_by`, pas d'une propriete.
        $this->assertSame($this->m3->id, $fichier->uploaded_by);
        $this->assertSame($this->m3->id, $fichier->uploader->id);

        $this->ouvrir($this->m3)->assertOk()->assertSee($this->m3->publicDisplayName());
    }

    public function test_a_file_uploaded_by_another_member_shows_that_member(): void
    {
        $fichier = $this->fichier($this->racineBoucle, 'Depose par Bastien.pdf', $this->m4);

        $this->assertSame($this->m4->id, $fichier->fresh()->uploaded_by);

        // m3 ouvre le Drive et lit le nom de m4 : la colonne suit le depot,
        // pas la personne qui regarde ni celle qui gouverne.
        $this->ouvrir($this->m3)->assertOk()->assertSee($this->m4->publicDisplayName());
    }

    // =====================================================================
    // 3 — auteur != ajoute par, la decision qui tranche
    // =====================================================================

    public function test_an_article_shows_who_filed_it_not_its_author(): void
    {
        $post = $this->article($this->racineBoucle, 'Compte rendu', auteur: $this->m3, deposant: $this->m4);

        $lien = DossierBlogPost::where('blog_post_id', $post->id)->firstOrFail();

        // Les deux donnees existent et different : c'est tout l'enjeu.
        $this->assertSame($this->m3->id, $post->user_id, "l'auteur est m3");
        $this->assertSame($this->m4->id, $lien->added_by, 'le deposant est m4');
        $this->assertSame($this->m4->id, $lien->addedBy->id);

        $cellules = $this->cellulesDAttribution($this->ouvrir($this->m3)->assertOk()->getContent());

        // C'est LA cellule d'attribution qu'on lit, pas la page entiere : un
        // nom present dans un fil d'Ariane ou une barre laterale ne prouverait
        // rien. Le deposant y est, l'auteur n'y est pas.
        // `e()` : les cellules sont extraites du HTML, donc echappees. Comparer
        // la forme brute rendrait ces deux assertions dependantes du nom que
        // Faker a tire — la positive echouerait au hasard, la negative
        // passerait pour la mauvaise raison (TASK-1147).
        $this->assertContains(e($this->m4->publicDisplayName()), $cellules,
            'La colonne doit montrer le deposant.');
        $this->assertNotContains(e($this->m3->publicDisplayName()), $cellules,
            "La colonne ne doit pas montrer l'auteur du contenu.");
    }

    /**
     * Le contenu des seules cellules de la colonne d'attribution.
     *
     * @return list<string>
     */
    private function cellulesDAttribution(string $html): array
    {
        preg_match_all(
            '/<span class="min-w-0 truncate text-xs text-gray-500 dark:text-gray-400">([^<]*)<\/span>/',
            $html,
            $m,
        );

        return array_values(array_filter(array_map('trim', $m[1])));
    }

    // =====================================================================
    // 4 — « Proprietaire » ne doit plus etre utilise ici
    // =====================================================================

    public function test_the_loop_drive_no_longer_calls_a_deposit_an_ownership(): void
    {
        $this->withSession(['locale' => 'en']);
        $this->fichier($this->racineBoucle, 'Rapport.pdf', $this->m4);

        $enTetes = $this->enTetes($this->ouvrir($this->m3)->assertOk()->getContent());

        $this->assertContains('Added by', $enTetes);
        $this->assertNotContains('Owner', $enTetes);
        // Dans une Boucle, « Partage » repeterait « Boucle » a chaque ligne.
        $this->assertNotContains('Sharing', $enTetes);
    }

    // =====================================================================
    // 5 — le Dossier : comportement exact et documente
    // =====================================================================

    public function test_a_folder_row_says_nothing_because_no_such_data_exists(): void
    {
        // La table ne porte que `owner_id`. Rien ne permet de dire qui a
        // « ajoute » un Dossier ici, et sur un Dossier de Boucle `owner_id`
        // est meme NULL.
        $this->assertNotContains('added_by', \Schema::getColumnListing('dossiers'));
        $this->assertNotContains('created_by', \Schema::getColumnListing('dossiers'));
        $this->assertNull($this->sousDossierBoucle->governingDossier()->owner_id);

        $html = $this->ouvrir($this->m3)->assertOk()->getContent();

        // La ligne du sous-dossier existe bien...
        $this->assertStringContainsString('Communication', $html);
        // ...mais le nom de la Boucle n'est plus presente comme une
        // attribution : il ne sert que d'identite dans le fil d'Ariane.
        $this->assertSame(
            0,
            preg_match('/<span class="min-w-0 truncate text-xs[^"]*">\s*'.preg_quote($this->loop->name, '/').'\s*<\/span>/', $html),
            'Le nom de la Boucle ne doit plus remplir la cellule d\'attribution.',
        );
    }

    // =====================================================================
    // 6 — liste et grille coherentes
    // =====================================================================

    public function test_the_grid_view_never_displayed_this_column(): void
    {
        $this->fichier($this->racineBoucle, 'Rapport.pdf', $this->m4);

        $html = $this->ouvrir($this->m3)->assertOk()->getContent();

        // La grille est un jeu de cartes : elle n'a jamais porte de colonne
        // d'attribution, donc il n'y a rien a y corriger. L'en-tete de
        // colonnes n'existe que dans la vue liste, et une seule fois.
        $this->assertSame(1, substr_count($html, '>'.__('dossiers.col_added_by').'<'));
    }

    // =====================================================================
    // 7 — FR / EN
    // =====================================================================

    public function test_the_label_exists_in_both_locales(): void
    {
        foreach (['fr' => 'Ajouté par', 'en' => 'Added by'] as $locale => $attendu) {
            app()->setLocale($locale);

            $this->assertSame($attendu, __('dossiers.col_added_by'));
        }
    }

    // =====================================================================
    // Coherence visuelle du module (parentheses de la meme session)
    // =====================================================================

    /**
     * La colonne du module suit le fond de la page.
     *
     * En clair, `--bp-page` et `--bp-surface` valent la meme couleur ; en
     * sombre, `surface` est plus clair et la colonne se detachait en bloc.
     */
    public function test_the_module_sidebar_follows_the_page_background(): void
    {
        $html = $this->ouvrir($this->m3)->assertOk()->getContent();

        $this->assertStringContainsString('bg-[var(--bp-page)]', $html);
        $this->assertStringNotContainsString(
            'self-stretch border-r border-[var(--bp-border)] bg-[var(--bp-surface)]',
            $html,
        );
    }

    /** Une seule destination, un seul dessin : le module reprend l'icone du rail. */
    public function test_the_loops_icon_matches_the_global_rail(): void
    {
        $iconeDuRail = 'M8 10h8M8 14h5m8-2a9 9 0 11-18 0 9 9 0 0118 0z';

        $html = $this->ouvrir($this->m3)->assertOk()->getContent();

        $this->assertStringContainsString($iconeDuRail, $html);
        // L'ancienne silhouette « personnes » ne sert plus a designer Boucles.
        $this->assertStringNotContainsString('M12.2 8a3.2 3.2 0 1 1-6.4 0', $html);
    }

    /** Un etat vide doit dire ou aller, pas seulement ce qui manque. */
    public function test_the_empty_loops_space_offers_a_way_to_find_one(): void
    {
        $sansBoucle = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($sansBoucle)
            ->get(route('organization.dossiers.index', [
                'organization' => $this->org->slug,
                'espace' => 'boucles',
            ]))
            ->assertOk()
            ->assertSee(__('dossiers.loops_empty'))
            ->assertSee(__('dossiers.loops_empty_cta'))
            ->assertSee(route('organization.loops.index', ['organization' => $this->org->slug]), false);
    }

    // =====================================================================
    // 9 — aucune fuite inter-Organization
    // =====================================================================

    public function test_another_organization_never_leaks(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);

        // La donnee est reellement candidate : un Drive de Boucle avec du
        // contenu, ouvert par quelqu'un d'une autre Organization.
        $this->fichier($this->racineBoucle, 'Rapport.pdf', $this->m4);

        $reponse = $this->actingAs($etranger)->get(route('organization.dossiers.show', [
            'organization' => $this->org->slug,
            'dossier' => $this->racineBoucle->getKey(),
        ]));

        $this->assertContains($reponse->status(), [403, 404]);
        // Forme echappee : chercher le nom brut ferait passer l'assertion des
        // que le nom porte une apostrophe, sans rien prouver.
        $this->assertStringNotContainsString(e($this->m4->publicDisplayName()), $reponse->getContent());
    }

    // =====================================================================
    // 10 — droits et partage inchanges
    // =====================================================================

    public function test_access_rules_are_untouched(): void
    {
        $horsBoucle = User::factory()->create(['organization_id' => $this->org->id]);

        // Les membres de la Boucle entrent, racine comme enfant ; celui qui
        // n'en est pas reste dehors. Rien de tout cela n'a bouge.
        foreach ([$this->m3, $this->m4] as $membre) {
            $this->ouvrir($membre)->assertOk();
            $this->ouvrir($membre, $this->sousDossierBoucle)->assertOk();
        }

        $this->ouvrir($horsBoucle)->assertForbidden();
        $this->ouvrir($horsBoucle, $this->sousDossierBoucle)->assertForbidden();
    }
}
