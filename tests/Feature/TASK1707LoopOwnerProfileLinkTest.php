<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1707 M3 — le nom du proprietaire d'une Boucle mene a son profil.
 *
 * Cyril l'a demande a deux endroits : la fiche d'une Boucle (« Animee par Ofsh
 * Cent ») et la carte de la liste des Boucles. Ce sont les deux SEULS appels de
 * `x-loops.owner-names` : la correction y vit une fois.
 *
 * ## Ce que ce fichier mesure, et pourquoi il existe pour un simple lien
 *
 * La regle de calibration dit qu'un lien ne merite pas un protocole complet.
 * La garde ici ne porte donc pas sur le lien mais sur son **bornage au
 * tenant** : `profile.show` est une route FRONT, et la route courte resout
 * l'Organization PAR DEFAUT. Depuis `/org/{slug}/...`, un lien non borne fait
 * basculer de tenant — c'est la famille de defauts que TASK-1697 a ferme sur
 * neuf liens, et elle est invisible a l'oeil.
 */
class TASK1707LoopOwnerProfileLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private User $visiteur;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1707-owner',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->proprietaire = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'first_name' => 'Ofsh',
            'name' => 'Cent',
        ]);

        $this->visiteur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Boucle de OGSH100 sur Qualiopi',
            'status' => 'active',
        ]);

        $this->boucle->members()->create([
            'user_id' => $this->proprietaire->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        // Une SECONDE Boucle, sans quoi le catalogue redirige vers l'unique
        // Boucle au lieu de se rendre (decision T1679) — et la garde posee sur
        // la carte de liste ne mesurerait rien.
        Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Une autre Boucle 1707',
            'status' => 'active',
        ]);
    }

    private function profilAttendu(): string
    {
        return route('organization.profile.show', [
            'organization' => $this->organization->slug,
            'user' => $this->proprietaire->profileRouteKey(),
        ]);
    }

    private function liens(string $chemin): array
    {
        app()->forgetInstance('current_organization');

        $html = $this->actingAs($this->visiteur)
            ->get('/org/'.$this->organization->slug.$chemin)
            ->assertOk()
            ->getContent();

        preg_match_all('/<a[^>]*href="([^"]+)"[^>]*\sdata-loop-owner-profile[\s>]/', $html, $m);

        return $m[1];
    }

    public function test_la_fiche_d_une_boucle_lie_le_nom_du_proprietaire(): void
    {
        $this->assertContains(
            $this->profilAttendu(),
            $this->liens('/loops/'.$this->boucle->slug),
            'Le nom du proprietaire ne mene pas a son profil, ou le lien n\'est pas borne a cette Organization.'
        );
    }

    public function test_la_liste_des_boucles_lie_aussi_le_nom_du_proprietaire(): void
    {
        $this->assertContains(
            $this->profilAttendu(),
            $this->liens('/loops'),
            'Sur la carte de la liste, le nom du proprietaire ne mene pas a son profil.'
        );
    }

    /**
     * Le lien est borne a l'Organization de l'URL, et JAMAIS a la route courte.
     *
     * C'est le seul volet qui justifie ce fichier : la route courte resout
     * l'Organization par defaut, donc un lien non borne ferait basculer de
     * tenant.
     */
    public function test_aucun_lien_ne_passe_par_la_route_non_bornee(): void
    {
        foreach (['/loops/'.$this->boucle->slug, '/loops'] as $chemin) {
            foreach ($this->liens($chemin) as $href) {
                $this->assertStringContainsString(
                    '/org/'.$this->organization->slug.'/',
                    $href,
                    "Un lien de profil n'est pas borne a l'Organization : {$href}"
                );
            }
        }
    }

    /**
     * Un compte desactive n'est PAS lie.
     *
     * Son nom est deja remplace par « Utilisateur desactive » : pointer vers
     * son profil promettrait une page qui ne dit rien de lui.
     */
    public function test_un_compte_desactive_n_est_pas_lie(): void
    {
        $this->proprietaire->update(['banned_at' => now()]);

        $this->assertSame(
            [],
            $this->liens('/loops/'.$this->boucle->slug),
            'Le profil d\'un compte desactive est encore propose.'
        );
    }
}
