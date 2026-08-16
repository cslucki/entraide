<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\PersonalDocumentsRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Un refus d'acces doit se comprendre — sans rien apprendre.
 *
 * ## Le defaut
 *
 * m3 partage un Dossier avec m4, puis retire le partage. m4 recharge son
 * ancienne URL et tombait sur la page « Forbidden » nue du framework : pas de
 * marque, pas un mot de francais, aucune sortie.
 *
 * ## Les deux exigences, tenues ensemble
 *
 * 1. **Comprehensible** : un titre, une phrase, deux sorties.
 * 2. **Muet** : la vue ne recoit pas le Dossier. Elle ne sait pas ce qui a ete
 *    demande, donc elle ne peut rien en dire — ni le nom, ni le proprietaire,
 *    ni les membres, ni les fichiers, ni les Articles. Le texte ne confirme pas
 *    davantage la CAUSE : « votre acces a peut-etre ete retire » couvre autant
 *    un partage revoque qu'une ressource indisponible.
 *
 * Le statut reste **403** : il n'est jamais converti en 200.
 *
 * Ces tests portent des marqueurs volontairement improbables — `ZZSECRET…` — de
 * sorte qu'une fuite ne puisse pas se confondre avec un mot du gabarit.
 */
class TASK1145DossierAccessDeniedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $m3;

    private User $m4;

    private Dossier $racine;

    /** Le Dossier partage puis retire. */
    private Dossier $dossier;

    private Dossier $descendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->m3 = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'ZZSECRETOWNER',
        ]);
        $this->m4 = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->racine = app(PersonalDocumentsRoot::class)->resolve($this->org->id, $this->m3->id);
        $this->dossier = Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $this->racine->id,
            'name' => 'ZZSECRETFOLDER',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $this->descendant = Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $this->dossier->id,
            'name' => 'ZZSECRETCHILD',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->fichier($this->dossier, 'ZZSECRETFILE.pdf');
        $this->article($this->dossier, 'ZZSECRETARTICLE');
    }

    private function fichier(Dossier $dossier, string $nom): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->m3->id,
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

    private function article(Dossier $dossier, string $titre): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $dossier->organization_id,
            'user_id' => $this->m3->id,
            'title' => $titre,
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);

        DossierBlogPost::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->m3->id,
        ]);

        return $post;
    }

    private function partager(User $invite, string $role = DossierMember::ROLE_READER): DossierMember
    {
        return DossierMember::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'user_id' => $invite->id,
            'role' => $role,
            'added_by' => $this->m3->id,
        ]);
    }

    private function retirer(User $invite): void
    {
        DossierMember::where('dossier_id', $this->dossier->id)
            ->where('user_id', $invite->id)
            ->delete();
    }

    private function ouvrir(User $acteur, ?Dossier $cible = null): TestResponse
    {
        return $this->actingAs($acteur)->get(route('organization.dossiers.show', [
            'organization' => $this->org->slug,
            'dossier' => ($cible ?? $this->dossier)->getKey(),
        ]));
    }

    // =====================================================================
    // 1 a 3 — le scenario reel, et le statut HTTP
    // =====================================================================

    public function test_the_shared_member_can_open_the_dossier(): void
    {
        $this->partager($this->m4);

        $this->ouvrir($this->m4)->assertOk();
    }

    public function test_removing_the_share_closes_the_dossier(): void
    {
        $this->partager($this->m4);
        $this->ouvrir($this->m4)->assertOk();

        $this->retirer($this->m4);

        $this->ouvrir($this->m4)->assertForbidden();
    }

    public function test_the_refusal_keeps_the_canonical_403_status(): void
    {
        $this->partager($this->m4);
        $this->retirer($this->m4);

        // Un refus rendu en 200 mentirait aux caches, aux robots et aux
        // clients d'API : le rendu change, jamais le code.
        $this->assertSame(403, $this->ouvrir($this->m4)->status());
    }

    // =====================================================================
    // 4 a 6 — la page est comprehensible et offre une sortie
    // =====================================================================

    public function test_the_refusal_page_explains_itself(): void
    {
        $this->partager($this->m4);
        $this->retirer($this->m4);

        $this->ouvrir($this->m4)
            ->assertForbidden()
            ->assertSee(__('dossiers.access_denied_title'))
            ->assertSee(__('dossiers.access_denied_message'))
            ->assertSee(__('dossiers.access_denied_hint'));
    }

    public function test_the_refusal_page_offers_a_way_back_to_my_folders(): void
    {
        $this->partager($this->m4);
        $this->retirer($this->m4);

        $this->ouvrir($this->m4)
            ->assertForbidden()
            ->assertSee(__('dossiers.access_denied_back'))
            ->assertSee(route('organization.dossiers.index', ['organization' => $this->org->slug]), false);
    }

    public function test_the_refusal_page_offers_the_shared_with_me_space(): void
    {
        $this->partager($this->m4);
        $this->retirer($this->m4);

        $this->ouvrir($this->m4)
            ->assertForbidden()
            ->assertSee(__('dossiers.access_denied_shared'))
            // L'URL porte deux parametres : dans un attribut `href` ses `&`
            // sont echappes en `&amp;`. On compare donc a la forme echappee,
            // celle qui est reellement dans le HTML.
            ->assertSee(e(route('organization.dossiers.index', [
                'organization' => $this->org->slug,
                'espace' => 'partages',
                'vue' => 'avec-moi',
            ])), false);
    }

    // =====================================================================
    // 7 a 10 — la page ne divulgue rien
    // =====================================================================

    /**
     * Le test central : aucune donnee du Dossier refuse dans le HTML.
     *
     * Les marqueurs sont improbables a dessein — une correspondance ne peut
     * pas etre un mot du gabarit.
     */
    public function test_the_refusal_page_leaks_nothing_about_the_dossier(): void
    {
        $this->partager($this->m4);
        $this->retirer($this->m4);

        $html = $this->ouvrir($this->m4)->assertForbidden()->getContent();

        foreach ([
            'ZZSECRETFOLDER',   // nom du Dossier
            'ZZSECRETCHILD',    // nom d'un descendant, donc le fil d'Ariane prive
            'ZZSECRETOWNER',    // prenom du proprietaire
            'ZZSECRETFILE',     // nom d'un fichier
            'ZZSECRETARTICLE',  // titre d'un Article
            $this->m3->email,   // email du proprietaire
            $this->dossier->getKey(),   // identifiant metier
            $this->racine->getKey(),
        ] as $aiguille) {
            $this->assertStringNotContainsString((string) $aiguille, $html,
                'Le refus divulgue « '.$aiguille.' ».');
        }
    }

    /** La vue ne recoit meme pas le Dossier : elle ne peut rien en dire. */
    public function test_the_refusal_view_never_receives_the_dossier(): void
    {
        $this->partager($this->m4);
        $this->retirer($this->m4);

        $reponse = $this->ouvrir($this->m4)->assertForbidden();

        // On interroge la vue rendue elle-meme : `viewData()` echouerait sur
        // une cle absente, alors que c'est precisement l'absence qu'on veut
        // constater.
        $donnees = $reponse->original->getData();

        $this->assertArrayNotHasKey('dossier', $donnees);
        $this->assertArrayNotHasKey('governingDossier', $donnees);
        $this->assertArrayNotHasKey('driveFolders', $donnees);

        // La garde generale : AUCUN Dossier ne circule vers cette vue, quel
        // que soit le nom de la variable. Le layout partage y injecte ses
        // propres cles (organisation courante, marque) — on ne les enumere
        // pas, on verifie seulement qu'aucune ne transporte de Dossier.
        foreach ($donnees as $cle => $valeur) {
            $this->assertNotInstanceOf(Dossier::class, $valeur, 'La cle « '.$cle.' » transporte un Dossier.');

            if ($valeur instanceof Collection) {
                foreach ($valeur as $element) {
                    $this->assertNotInstanceOf(Dossier::class, $element, 'La cle « '.$cle.' » contient un Dossier.');
                }
            }
        }
    }

    /** Le refus ne nomme pas sa cause, et ne propose pas de la lever. */
    public function test_the_refusal_never_names_its_cause_nor_offers_to_request_access(): void
    {
        $this->partager($this->m4);
        $this->retirer($this->m4);

        $html = $this->ouvrir($this->m4)->assertForbidden()->getContent();

        // Ni « retire par X », ni « demander l'acces », ni « contacter le
        // proprietaire » : chacune de ces formules confirmerait que le Dossier
        // existe encore et qu'un tiers a agi dessus.
        $this->assertStringNotContainsString($this->m3->publicDisplayName(), $html);
        $this->assertStringNotContainsString('demander', mb_strtolower($html));
        $this->assertStringNotContainsString('propriétaire', mb_strtolower($html));
    }

    /** Un Dossier jamais partage refuse de la meme facon — indistinguable. */
    public function test_a_never_shared_dossier_is_refused_identically(): void
    {
        $jamaisPartage = Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $this->racine->id,
            'name' => 'ZZSECRETNEVERSHARED',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $reponse = $this->ouvrir($this->m4, $jamaisPartage)->assertForbidden();

        // Meme page, meme statut : impossible de deviner, en comparant, si le
        // Dossier a ete partage un jour.
        $reponse->assertSee(__('dossiers.access_denied_message'));
        $this->assertStringNotContainsString('ZZSECRETNEVERSHARED', $reponse->getContent());
    }

    // =====================================================================
    // 11 — aucune fuite inter-Organization
    // =====================================================================

    public function test_another_organization_never_leaks(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);

        // La donnee est reellement candidate : un Dossier ouvrable, avec du
        // contenu. Seule la garde de tenant l'ecarte — et elle rend 404, pas la
        // page de refus, car pour cet utilisateur l'Organization n'existe pas.
        $reponse = $this->actingAs($etranger)->get(route('organization.dossiers.show', [
            'organization' => $this->org->slug,
            'dossier' => $this->dossier->getKey(),
        ]));

        $this->assertContains($reponse->status(), [403, 404]);
        $this->assertStringNotContainsString('ZZSECRETFOLDER', $reponse->getContent());
        $this->assertStringNotContainsString('ZZSECRETOWNER', $reponse->getContent());
    }

    // =====================================================================
    // 12 a 14 — les acces legitimes ne changent pas
    // =====================================================================

    public function test_the_owner_is_unaffected(): void
    {
        $this->ouvrir($this->m3)
            ->assertOk()
            ->assertSee('ZZSECRETFOLDER');
    }

    public function test_a_reader_and_an_editor_are_unaffected(): void
    {
        foreach ([DossierMember::ROLE_READER, DossierMember::ROLE_EDITOR] as $role) {
            $invite = User::factory()->create(['organization_id' => $this->org->id]);

            DossierMember::create([
                'organization_id' => $this->org->id,
                'dossier_id' => $this->dossier->id,
                'user_id' => $invite->id,
                'role' => $role,
                'added_by' => $this->m3->id,
            ]);

            $this->ouvrir($invite)->assertOk();
            // L'acces herite du descendant tient toujours.
            $this->ouvrir($invite, $this->descendant)->assertOk();
        }
    }

    public function test_a_loop_drive_is_unaffected(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
            'type' => 'general',
            'created_by' => $this->m3->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->m3->id, 'joined_at' => now(),
        ]);

        $racineBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'loop_id' => $loop->id,
            'name' => 'Documents',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);

        // Le membre de la Boucle entre ; celui qui n'en est pas se voit refuser
        // par la meme page, sans rien apprendre de la Boucle.
        $this->ouvrir($this->m3, $racineBoucle)->assertOk();

        $this->ouvrir($this->m4, $racineBoucle)
            ->assertForbidden()
            ->assertSee(__('dossiers.access_denied_message'));
    }

    /** Les libelles existent dans les deux langues. */
    public function test_the_labels_exist_in_both_locales(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (['title', 'message', 'hint', 'back', 'shared'] as $suffixe) {
                $cle = 'dossiers.access_denied_'.$suffixe;
                $this->assertNotSame($cle, __($cle), $cle.' manque en '.$locale);
            }
        }
    }
}
