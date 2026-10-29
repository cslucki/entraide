<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\User;
use App\Services\Users\Exceptions\UserOrganizationImmutableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1726 — l'Organization d'un compte est definitive.
 *
 * ## La decision
 *
 * `Organization` EST le tenant ; `Loop` ne l'est pas. Deplacer un compte d'un
 * tenant vers un autre laissait derriere lui tout ce qu'il avait produit — 48
 * tables portent `organization_id` — tout en emportant ce qui se compte sur le
 * compte lui-meme : `points_balance`, `rating`, `membership_value`. Le compte
 * arrivait donc avec un solde gagne ailleurs, et son ancien tenant gardait des
 * lignes dont l'auteur n'etait plus membre. Arbitrage MASTER : pour rejoindre
 * une autre Organization, on aura un compte DISTINCT, au besoin avec le meme
 * email.
 *
 * ## Les deux sens, et le troisieme
 *
 * Une immuabilite ne se teste pas qu'en refus. Trois choses doivent etre vraies
 * ensemble, et ce fichier les tient toutes les trois :
 *
 *   1. aucun chemin ne deplace un compte — ni HTTP, ni le modele ;
 *   2. ce qui n'est PAS un deplacement continue de marcher : creer un compte
 *      dans une Organization, editer une fiche sans toucher au tenant,
 *      rattacher un compte ORPHELIN ;
 *   3. les exceptions legitimes recensees a l'audit du 29/10 restent
 *      possibles : la suppression d'Organization detache ses membres, et la
 *      reparation en masse des orphelins les rattache.
 *
 * Sans (2) et (3), un `throw` inconditionnel dans `User::booted()` resterait
 * vert sur (1) tout en cassant la creation de comptes et la suppression d'un
 * tenant.
 */
class TASK1726OrganizationImmuableTest extends TestCase
{
    use RefreshDatabase;

    private Organization $origine;

    private Organization $destination;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->origine = Organization::factory()->create(['slug' => 'imm-origine', 'is_active' => true]);
        $this->destination = Organization::factory()->create(['slug' => 'imm-destination', 'is_active' => true]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->origine->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── 1. aucun chemin ne deplace un compte ─────────────────────────────────

    public function test_le_modele_refuse_un_deplacement_vers_une_autre_organization(): void
    {
        $compte = User::factory()->create(['organization_id' => $this->origine->id]);

        $this->expectException(UserOrganizationImmutableException::class);

        try {
            $compte->update(['organization_id' => $this->destination->id]);
        } finally {
            // Dans le `finally`, pour que l'assertion de PERSISTANCE soit
            // evaluee meme si l'exception remonte. Une garde qui leve APRES
            // avoir ecrit serait verte sur `expectException()` seul.
            $this->assertSame($this->origine->id, $compte->fresh()->organization_id);
        }
    }

    public function test_le_modele_refuse_aussi_un_detachement_vers_null(): void
    {
        // L'autre sens du meme defaut, et le moins evident : detacher n'est pas
        // « ne rien faire », c'est perdre le tenant. Seul l'effacement de
        // l'Organization elle-meme a le droit de le faire (voir plus bas).
        $compte = User::factory()->create(['organization_id' => $this->origine->id]);

        $this->expectException(UserOrganizationImmutableException::class);

        try {
            $compte->organization_id = null;
            $compte->save();
        } finally {
            $this->assertSame($this->origine->id, $compte->fresh()->organization_id);
        }
    }

    public function test_le_modele_refuse_meme_par_forceFill(): void
    {
        // `forceFill()` contourne `$fillable`. C'est le chemin qu'emprunte
        // deja le code du depot pour poser `approved_at` ou `must_set_password`
        // : si la garde tenait a la liste des champs remplissables, elle
        // serait muette ici — et muette la ou on force, c'est muette la ou ca
        // compte.
        $compte = User::factory()->create(['organization_id' => $this->origine->id]);

        $this->expectException(UserOrganizationImmutableException::class);

        try {
            $compte->forceFill(['organization_id' => $this->destination->id])->save();
        } finally {
            $this->assertSame($this->origine->id, $compte->fresh()->organization_id);
        }
    }

    public function test_un_updateOrCreate_par_email_seul_ne_vole_plus_un_compte(): void
    {
        // La forme exacte des packs de scenario. Trois d'entre eux cherchaient
        // par `['email' => …]` seul puis reecrivaient `organization_id` : le
        // pack ne provisionnait pas un persona, il DEPLACAIT un compte reel
        // dans sa sandbox, avec son nom, sa bio, et un mot de passe connu.
        //
        // Les packs cherchent desormais par (email, Organization) — mais c'est
        // la garde du modele qui rend le vol IMPOSSIBLE, quelle que soit la
        // forme de la recherche. Ce test mesure le filet, pas le correctif :
        // c'est lui qui tiendra pour le quatrieme pack, celui que personne n'a
        // encore ecrit.
        $reel = User::factory()->create([
            'organization_id' => $this->origine->id,
            'email' => 'personne-reelle-1726@exemple.test',
        ]);

        $this->expectException(UserOrganizationImmutableException::class);

        try {
            User::updateOrCreate(
                ['email' => 'personne-reelle-1726@exemple.test'],
                ['organization_id' => $this->destination->id, 'name' => 'Persona vole'],
            );
        } finally {
            $reel->refresh();
            $this->assertSame($this->origine->id, $reel->organization_id);
            $this->assertNotSame('Persona vole', $reel->name);
        }
    }

    public function test_l_exception_nomme_le_compte_et_les_deux_organizations(): void
    {
        // Un refus doit etre diagnosticable. Le message est ce que lira la
        // personne qui tombera dessus dans six mois, dans un seeder ou un job.
        $compte = User::factory()->create(['organization_id' => $this->origine->id]);

        try {
            $compte->update(['organization_id' => $this->destination->id]);
            $this->fail('le deplacement aurait du etre refuse');
        } catch (UserOrganizationImmutableException $e) {
            $this->assertSame($compte->id, $e->userId);
            $this->assertSame($this->origine->id, $e->fromOrganizationId);
            $this->assertSame($this->destination->id, $e->toOrganizationId);
            $this->assertStringContainsString($this->origine->id, $e->getMessage());
            $this->assertStringContainsString($this->destination->id, $e->getMessage());
        }
    }

    public function test_la_fiche_admin_ne_deplace_pas_le_compte_meme_si_le_champ_est_poste(): void
    {
        // Le defaut historique vu par HTTP. `updateUser()` lisait
        // `organization_id` dans la requete ; le champ est desormais inconnu
        // du controleur. On le poste quand meme — c'est ce que ferait un rejeu
        // de formulaire ou un client d'API — et il ne doit rien produire : ni
        // erreur, ni mouvement.
        $compte = User::factory()->create([
            'organization_id' => $this->origine->id,
            'name' => 'Nom initial',
        ]);

        $this->actingAs($this->superAdmin)
            ->from(route('admin.users.edit', $compte))
            ->put(route('admin.users.update', $compte), [
                'name' => 'Nom corrige',
                'email' => $compte->email,
                'organization_id' => $this->destination->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $compte->refresh();
        $this->assertSame('Nom corrige', $compte->name, 'le reste du formulaire doit continuer de s\'enregistrer');
        $this->assertSame($this->origine->id, $compte->organization_id);
    }

    public function test_un_PUT_sans_organization_id_ne_verse_plus_le_compte_dans_l_organization_par_defaut(): void
    {
        // LE defaut du perimetre 4, et le plus silencieux de tous.
        //
        // `updateUser()` repliait sur `DefaultOrganizationResolver` quand le
        // champ etait ABSENT. Un PUT partiel — exactement celui qu'on ecrit
        // ici — deplacait donc le compte vers l'Organization par defaut de la
        // plateforme sans que personne n'ait demande quoi que ce soit. Le
        // formulaire complet masquait le probleme en repostant toujours la
        // valeur courante : le defaut n'etait visible QUE hors du navigateur.
        //
        // L'Organization par defaut est posee ici explicitement, et distincte
        // de celle du compte : sans elle, l'ancien code aurait pu rester vert
        // par coincidence.
        $defaut = Organization::factory()->create(['slug' => 'imm-defaut', 'is_active' => true, 'is_default' => true]);
        $compte = User::factory()->create(['organization_id' => $this->origine->id]);

        $this->assertNotSame($defaut->id, $this->origine->id);

        $this->actingAs($this->superAdmin)
            ->from(route('admin.users.edit', $compte))
            ->put(route('admin.users.update', $compte), [
                'name' => $compte->name,
                'email' => $compte->email,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($this->origine->id, $compte->fresh()->organization_id);
    }

    // ── 2. ce qui n'est pas un deplacement marche toujours ───────────────────

    public function test_la_creation_d_un_compte_pose_toujours_son_organization(): void
    {
        // `organization_id` reste dans `$fillable` : le retirer aurait fait
        // disparaitre la valeur en SILENCE a la creation. Ce test est ce qui
        // interdit de « durcir » la garde par ce raccourci.
        $compte = User::create([
            'organization_id' => $this->origine->id,
            'name' => 'Compte neuf',
            'email' => 'compte-neuf-1726@exemple.test',
            'password' => Hash::make('password'),
        ]);

        $this->assertSame($this->origine->id, $compte->fresh()->organization_id);
    }

    public function test_l_ecran_de_creation_admin_cree_toujours_dans_l_organization_par_defaut(): void
    {
        $defaut = Organization::factory()->create(['slug' => 'imm-defaut-store', 'is_active' => true, 'is_default' => true]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.store'), [
                'name' => 'Cree par admin',
                'email' => 'cree-par-admin-1726@exemple.test',
                'password' => 'motdepasse1726',
                'password_confirmation' => 'motdepasse1726',
                'points' => 10,
            ])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('success');

        $compte = User::where('email', 'cree-par-admin-1726@exemple.test')->sole();
        $this->assertSame($defaut->id, $compte->organization_id);
        $this->assertSame(10, $compte->points_balance);
        $this->assertSame(1, PointLedger::where('user_id', $compte->id)->count());
    }

    public function test_un_compte_orphelin_peut_encore_etre_rattache(): void
    {
        // NULL vers une Organization n'est pas un deplacement : c'est la
        // premiere affectation d'un compte que la suppression d'un tenant a
        // laisse sans rien. Le refus porte sur la valeur D'ORIGINE.
        $orphelin = User::factory()->create(['organization_id' => null]);

        $orphelin->update(['organization_id' => $this->destination->id]);

        $this->assertSame($this->destination->id, $orphelin->fresh()->organization_id);
    }

    public function test_une_sauvegarde_qui_reecrit_la_MEME_organization_passe(): void
    {
        // Une garde posee sur « le champ est present » plutot que sur « la
        // valeur change » aurait casse tous les appels qui repostent la valeur
        // courante — c'est-a-dire la moitie du depot.
        $compte = User::factory()->create(['organization_id' => $this->origine->id]);

        $compte->update([
            'organization_id' => $this->origine->id,
            'bio' => 'Bio modifiee',
        ]);

        $this->assertSame('Bio modifiee', $compte->fresh()->bio);
        $this->assertSame($this->origine->id, $compte->fresh()->organization_id);
    }

    // ── 3. les exceptions legitimes de l'audit ───────────────────────────────

    public function test_la_suppression_d_une_organization_detache_toujours_ses_membres(): void
    {
        // Exception legitime n°1. Elle passe par un UPDATE de masse
        // (`$organization->users()->update(...)`), qui ne leve aucun evenement
        // de modele : la garde ne la voit pas, et c'est voulu. Il n'y a pas de
        // destination a choisir — le tenant n'existe plus.
        $aSupprimer = Organization::factory()->create(['slug' => 'imm-a-supprimer']);
        $membre = User::factory()->create(['organization_id' => $aSupprimer->id]);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.organizations.destroy', $aSupprimer))
            ->assertSessionHas('success');

        $this->assertNull($membre->fresh()->organization_id);
    }

    public function test_la_reparation_en_masse_rattache_les_orphelins_sans_toucher_aux_autres(): void
    {
        // Exception legitime n°2 : `/admin/outils/assign-data`, borne par
        // `whereNull('organization_id')`. Les deux assertions comptent autant
        // l'une que l'autre — la seconde est celle qui prouve que la borne
        // tient encore.
        $orphelin = User::factory()->create(['organization_id' => null]);
        $enPlace = User::factory()->create(['organization_id' => $this->origine->id]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $this->destination->id,
                'confirm' => '1',
                'confirmation' => 'REASSIGN USERS',
            ])
            ->assertSessionHas('success');

        $this->assertSame($this->destination->id, $orphelin->fresh()->organization_id);
        $this->assertSame($this->origine->id, $enPlace->fresh()->organization_id,
            'un compte DEJA rattache ne doit pas bouger');
    }
}
