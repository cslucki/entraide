<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopPresetConfigurator;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1872, correctif 07 — deleguer « Personnaliser les outils » au
 * PROPRIETAIRE, Boucle par Boucle.
 *
 * ## Le diagnostic, qui n'est pas celui qu'on attendait
 *
 * Cyril, 21/11 : « actuellement uniquement l'administrateur de l'organisation
 * peut modifier les outils de la boucle ».
 *
 * Le cahier des charges demandait de chercher parmi quatre causes : le
 * reglage d'Organization, `$peutOutils`, la visibilite du menu, ou un droit
 * serveur. Mesure faite : la chaine est COHERENTE de bout en bout —
 * `$peutOutils` lit `canCustomiseTools`, qui lit
 * `LoopPresetConfigurator::canConfigure()`, exactement l'autorite que
 * `LoopToolsController` applique. Aucun ecart entre ce que l'ecran montre et
 * ce que le serveur accepte.
 *
 * La cause est ailleurs, et elle est entiere : `canConfigure()` laissait DEJA
 * passer le proprietaire, a condition que son Organization l'autorise. Or
 * `loop_composition_policy` vaut `'locked'` par defaut, les dix Organizations
 * de la base locale y etaient, et **aucune vue ne poste vers l'endpoint qui
 * ecrit cette colonne**. La fonctionnalite etait donc morte-nee, pas absente.
 *
 * ## La forme retenue, choisie par Cyril
 *
 * Une premiere version inversait le defaut de l'Organization et mettait a
 * jour les dix lignes existantes. Cyril a prefere un INTERRUPTEUR, et par
 * BOUCLE, sur les deux ecrans d'edition — SuperAdmin et admin
 * d'Organization. C'est le grain le plus fin, et surtout le seul qui ne
 * decide rien a la place de personne.
 *
 * ## Ce que ces tests gardent
 *
 * TROIS etats, et le `null` est celui qui compte : tant qu'un administrateur
 * n'a rien bascule, rien ne change pour aucune Boucle. Les deux autres sont
 * tenus dans les DEUX sens — autoriser malgre l'Organization, et refuser
 * malgre elle.
 *
 * Et les trois refus que ce correctif ne doit surtout PAS emporter —
 * l'animateur, le membre, la Boucle archivee — sont re-verifies, parce qu'une
 * delegation est exactement le genre de geste qui en emporte un sans qu'on
 * s'en apercoive.
 */
class TASK1872OutilsDeBoucleAuProprietaireTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $proprietaire;

    private User $animateur;

    private User $membre;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);

        // ⚠️ `loop_composition_policy` n'est PAS pose ici, et c'est le sujet :
        // ce test mesure le DEFAUT du produit. Le poser aurait mesure la
        // fixture.
        $this->org = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'is_default' => false,
        ]);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->org->id]);
        $this->animateur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);

        $this->boucle = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->proprietaire->id, 'visibility' => 'private',
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->boucle);

        $this->rejoindre($this->proprietaire, 'owner');
        $this->rejoindre($this->animateur, 'facilitator');
        $this->rejoindre($this->membre, 'member');

        app()->instance('current_organization', $this->org);
    }

    private function rejoindre(User $user, string $role): void
    {
        LoopMember::factory()->create([
            'loop_id' => $this->boucle->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function configurateur(): LoopPresetConfigurator
    {
        return app(LoopPresetConfigurator::class);
    }

    private function workspace(User $user): string
    {
        return $this->actingAs($user)
            ->get(route('organization.loops.show', [
                'organization' => $this->org->slug, 'loop' => $this->boucle->id,
            ]))
            ->assertOk()
            ->getContent();
    }

    private function lienOutils(): string
    {
        return route('organization.loops.tools', [
            'organization' => $this->org->slug, 'loop' => $this->boucle->id,
        ]);
    }

    /* ─────────── LES TROIS ETATS ─────────── */

    /**
     * `null` — RIEN NE CHANGE. C'est l'assertion la plus importante du
     * fichier : la migration est additive, et tant qu'aucun administrateur
     * n'a bascule l'interrupteur, chaque Boucle se comporte exactement comme
     * avant cette TASK.
     */
    public function test_sans_rien_decider_la_boucle_herite_de_son_organization(): void
    {
        $this->assertNull($this->boucle->owner_may_compose);
        $this->assertFalse($this->org->fresh()->allowsOwnerComposition(),
            'Temoin : l\'Organization est verrouillee, comme toutes aujourd\'hui.');

        $this->assertFalse($this->configurateur()->canConfigure($this->proprietaire, $this->boucle));
    }

    /** `true` — autorise CETTE Boucle, quoi que dise l'Organization. */
    public function test_l_interrupteur_autorise_le_proprietaire_malgre_une_organization_verrouillee(): void
    {
        $this->boucle->update(['owner_may_compose' => true]);

        $this->assertFalse($this->org->fresh()->allowsOwnerComposition(), 'Temoin : elle est bien verrouillee.');
        $this->assertTrue($this->configurateur()->canConfigure($this->proprietaire, $this->boucle->fresh()));
    }

    /**
     * `false` — refuse CETTE Boucle, meme si l'Organization autorise ailleurs.
     *
     * Sans ce sens-la, l'interrupteur ne serait qu'un demi-interrupteur :
     * on pourrait ouvrir, jamais refermer pour un cas particulier.
     */
    public function test_l_interrupteur_refuse_cette_boucle_malgre_une_organization_ouverte(): void
    {
        $this->org->update(['loop_composition_policy' => Organization::COMPOSITION_OWNER_ALLOWED]);
        $this->boucle->update(['owner_may_compose' => false]);

        $this->assertTrue($this->org->fresh()->allowsOwnerComposition(), 'Temoin : elle est bien ouverte.');
        $this->assertFalse($this->configurateur()->canConfigure($this->proprietaire, $this->boucle->fresh()));
    }

    /** Le reglage d'Organization gouverne toujours ce qui n'a rien decide. */
    public function test_une_organization_ouverte_suffit_quand_la_boucle_n_a_rien_decide(): void
    {
        $this->org->update(['loop_composition_policy' => Organization::COMPOSITION_OWNER_ALLOWED]);

        $this->assertTrue($this->configurateur()->canConfigure($this->proprietaire, $this->boucle->fresh()));
    }

    /* ─────────── LE MENU LE MONTRE, ET LE SERVEUR L'ACCEPTE ─────────── */

    /**
     * Le menu de la Boucle porte l'entree — desktop ET mobile.
     *
     * Les deux ne sont pas deux rendus : le partial en produit UN seul, dont
     * le declencheur affiche le libelle au-dessus de 768 px et le ⋮ en
     * dessous. Verifier le lien couvre donc les deux, et c'est precisement ce
     * que Cyril demande (« en haut a droite sur desktop et menu a 3 points
     * sur mobile »).
     */
    public function test_le_menu_de_la_boucle_propose_personnaliser_les_outils_au_proprietaire(): void
    {
        $this->boucle->update(['owner_may_compose' => true]);

        $html = $this->workspace($this->proprietaire->fresh());

        $this->assertStringContainsString(e($this->lienOutils()), $html,
            'Le lien « Personnaliser les outils » manque dans le menu du proprietaire.');
        $this->assertStringContainsString(__('loops.owner_tools_action'), $html);
    }

    /** Et le bouton ne ment pas : l'ecran s'ouvre vraiment. */
    public function test_l_ecran_des_outils_s_ouvre_pour_le_proprietaire(): void
    {
        $this->boucle->update(['owner_may_compose' => true]);

        $this->actingAs($this->proprietaire)->get($this->lienOutils())->assertOk();
    }

    /* ─────────── LES DEUX ECRANS D'ADMINISTRATION ─────────── */

    /**
     * L'ecran d'admin d'Organization enregistre l'interrupteur.
     *
     * Sans ce test, la colonne, la regle et la vue pourraient toutes etre
     * justes pendant que le formulaire oublie le champ — le cas le plus
     * banal, et celui qu'aucune assertion de permission ne verrait.
     */
    public function test_l_admin_d_organization_bascule_l_interrupteur(): void
    {
        $this->org->update(['admin_id' => $this->proprietaire->id]);

        $this->actingAs($this->proprietaire->fresh())
            ->put(route('organization.admin.loops.update', [
                'organization' => $this->org->slug, 'loop' => $this->boucle->id,
            ]), [
                'name' => $this->boucle->name,
                'type' => $this->boucle->type,
                'owner_may_compose' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue($this->boucle->fresh()->owner_may_compose);
    }

    /** Et l'ecran SuperAdmin aussi, avec la meme semantique. */
    public function test_le_superadmin_bascule_l_interrupteur(): void
    {
        $super = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $this->actingAs($super)
            ->put(route('admin.loops.update', $this->boucle), [
                'name' => $this->boucle->name,
                'visibility' => $this->boucle->visibility,
                'owner_may_compose' => '0',
            ])
            ->assertRedirect();

        $this->assertFalse($this->boucle->fresh()->owner_may_compose);
    }

    /**
     * « Rien choisi » revient a `null`, pas a `false`.
     *
     * Le `<select>` renvoie une CHAINE VIDE pour l'heritage. Sans le test
     * explicite des controleurs, elle serait devenue `false` au cast
     * booleen — un REFUS pose par un formulaire ou personne n'a rien decide,
     * et donc la perte silencieuse du troisieme etat.
     */
    public function test_le_choix_heriter_revient_bien_a_null(): void
    {
        $this->boucle->update(['owner_may_compose' => true]);
        $this->org->update(['admin_id' => $this->proprietaire->id]);

        $this->actingAs($this->proprietaire->fresh())
            ->put(route('organization.admin.loops.update', [
                'organization' => $this->org->slug, 'loop' => $this->boucle->id,
            ]), [
                'name' => $this->boucle->name,
                'type' => $this->boucle->type,
                'owner_may_compose' => '',
            ])
            ->assertRedirect();

        $this->assertNull($this->boucle->fresh()->owner_may_compose);
    }

    /**
     * Le reglage LARGE, sur la fiche de l'Organization.
     *
     * Demande de Cyril : « ajouter un interrupteur dans le reglage de
     * l'organisation […] ainsi ce sera plus rapide. Et ensuite j'aurais un
     * reglage plus fin au niveau des boucles. » Les deux niveaux coexistent,
     * et ce test tient le premier : une case cochee une fois ouvre TOUTES les
     * Boucles de l'Organization.
     */
    public function test_le_superadmin_ouvre_la_composition_pour_toute_l_organization(): void
    {
        $super = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $this->assertFalse($this->org->allowsOwnerComposition(), 'Temoin : elle part verrouillee.');

        $this->actingAs($super)
            ->put(route('admin.organizations.update', $this->org), [
                'name' => $this->org->name,
                'welcome_points' => 0,
                'loop_composition_policy' => Organization::COMPOSITION_OWNER_ALLOWED,
            ])
            ->assertRedirect();

        $this->assertTrue($this->org->fresh()->allowsOwnerComposition());
        $this->assertTrue($this->configurateur()->canConfigure($this->proprietaire, $this->boucle->fresh()));
    }

    /** Case decochee = champ absent du POST = verrouille. */
    public function test_decocher_la_case_reverrouille_l_organization(): void
    {
        $this->org->update(['loop_composition_policy' => Organization::COMPOSITION_OWNER_ALLOWED]);
        $super = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $this->actingAs($super)
            ->put(route('admin.organizations.update', $this->org), [
                'name' => $this->org->name,
                'welcome_points' => 0,
                // Le champ n'est PAS envoye : c'est ce que fait un navigateur
                // d'une case decochee, et c'est ce que le controleur doit
                // lire comme un refus.
            ])
            ->assertRedirect();

        $this->assertFalse($this->org->fresh()->allowsOwnerComposition());
    }

    /* ─────────── CE QUE LE CHANGEMENT NE DOIT PAS EMPORTER ─────────── */

    /**
     * Trois refus, re-verifies.
     *
     * « Un animateur anime, il ne recompose pas » : cette doctrine tient
     * depuis T1083, et un changement de defaut est exactement le genre de
     * geste qui l'emporterait sans qu'on s'en apercoive.
     */
    public function test_l_animateur_et_le_membre_ne_configurent_toujours_pas(): void
    {
        // L'interrupteur est sur OUI : s'il emportait l'animateur ou le
        // membre, c'est ici que cela se verrait.
        $this->boucle->update(['owner_may_compose' => true]);

        $this->assertFalse($this->configurateur()->canConfigure($this->animateur, $this->boucle));
        $this->assertFalse($this->configurateur()->canConfigure($this->membre, $this->boucle));

        $this->actingAs($this->animateur)->get($this->lienOutils())->assertForbidden();
        $this->actingAs($this->membre)->get($this->lienOutils())->assertForbidden();

        $html = $this->workspace($this->membre);
        $this->assertStringNotContainsString(e($this->lienOutils()), $html,
            'Le menu d\'un membre propose une porte que le serveur lui ferme.');
    }

    /**
     * Une Boucle archivee ne se recompose pas, proprietaire compris.
     *
     * ⚠️ Ma premiere version de ce test interrogeait `canConfigure()` et
     * rougissait. Le code avait raison : cette methode est documentee comme
     * disant « LE DROIT, PAS L'ETAT ». Elle laisse donc passer sur une
     * archivee — `loops.archive` est justement la seule capacite qui traverse
     * l'archivage, puisque c'est elle qui reactive.
     *
     * L'archivage est refuse LA OU IL DOIT L'ETRE : a l'ouverture de l'ecran
     * (`abort_if($loop->isArchived())`), a l'ecriture
     * (`assertConfigurable()`), et dans le menu (`canCustomiseTools` porte
     * `! $loop->isArchived()`). C'est cela que ce test mesure desormais — le
     * comportement, et non une methode interrogee hors de son contrat.
     */
    public function test_une_boucle_archivee_ne_se_recompose_pas(): void
    {
        $this->boucle->update(['status' => 'archived', 'owner_may_compose' => true]);

        $this->actingAs($this->proprietaire)->get($this->lienOutils())->assertForbidden();

        $this->assertStringNotContainsString(e($this->lienOutils()), $this->workspace($this->proprietaire),
            'Le menu d\'une Boucle archivee propose encore de la recomposer.');
    }

    /** Et jamais depuis une autre Organization. */
    public function test_un_proprietaire_d_une_autre_organization_ne_configure_pas(): void
    {
        $this->boucle->update(['owner_may_compose' => true]);

        $ailleurs = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $etranger = User::factory()->create(['organization_id' => $ailleurs->id]);

        $this->assertFalse($this->configurateur()->canConfigure($etranger, $this->boucle));
    }
}
