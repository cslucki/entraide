<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1752 — l'audience des evenements de Flux.
 *
 * ## La garde n°1 du plan de tests du CDC
 *
 * Un membre de la Boucle A ne doit PAS voir l'evenement restreint de la
 * Boucle B — et doit voir celui de sa propre Boucle. **Les deux sens sont
 * testes** : une garde de masquage qui ne mesure que l'absence passerait au
 * vert sur un code qui cache tout (lecon T1744).
 *
 * ## Le risque reel que cela ferme
 *
 * « Marie a rejoint la Boucle *Conseil de direction* » affiche a toute
 * l'Organization revelerait une appartenance que le produit n'expose nulle
 * part. T1075 a rendu la BOUCLE decouvrable — nom, nombre de membres,
 * animateurs — jamais sa liste de membres.
 *
 * La regle retenue (arbitrage Cyril, §8.2) est celle que le depot applique
 * deja aux Evenements : « une Boucle privee ne remonte jamais ».
 */
class TASK1752FeedEventAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Loop $publique;

    private Loop $privee;

    private User $membreDeLaPrivee;

    private User $etranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-aud-1752', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->membreDeLaPrivee = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->publique = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => $this->membreDeLaPrivee->id,
        ]);

        $this->privee = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'private',
            'status' => 'active',
            'created_by' => $this->membreDeLaPrivee->id,
        ]);

        LoopMember::create([
            'loop_id' => $this->privee->id,
            'user_id' => $this->membreDeLaPrivee->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => $this->organization->id,
        ]);
    }

    private function emetteur(): FeedEventEmitter
    {
        return app(FeedEventEmitter::class);
    }

    /** @return list<string> les identifiants visibles par cette personne */
    private function visiblesPour(User $user): array
    {
        return FeedPost::forOrganization($this->organization->id)
            ->published()
            ->visibleTo($user)
            ->pluck('id')
            ->all();
    }

    // ── L'audience selon la Boucle ─────────────────────────────────────────

    /**
     * Une adhesion a une Boucle PRIVEE ne sort pas de la Boucle.
     *
     * C'est la fuite d'appartenance que le CDC classe en gravite HAUTE.
     */
    public function test_une_adhesion_a_une_boucle_PRIVEE_reste_dans_la_boucle(): void
    {
        $ligne = $this->emetteur()->emettre(
            FeedEventCatalogue::ADHESION_BOUCLE,
            $this->privee,
            $this->membreDeLaPrivee,
            $this->organization,
            $this->privee,
        );

        $this->assertNotNull($ligne);
        $this->assertSame(FeedEventCatalogue::VISIBILITE_BOUCLE, $ligne->visibility);

        $this->assertNotContains($ligne->id, $this->visiblesPour($this->etranger),
            'Une appartenance a une Boucle privee a fuit dans le Flux de l\'Organization.');
    }

    /**
     * LA RECIPROQUE, sans laquelle la garde ci-dessus ne mesure rien.
     *
     * Un code qui cacherait TOUT la passerait sans rien prouver.
     */
    public function test_le_membre_de_la_boucle_privee_VOIT_cette_adhesion(): void
    {
        $ligne = $this->emetteur()->emettre(
            FeedEventCatalogue::ADHESION_BOUCLE,
            $this->privee,
            $this->membreDeLaPrivee,
            $this->organization,
            $this->privee,
        );

        $this->assertContains($ligne->id, $this->visiblesPour($this->membreDeLaPrivee));
    }

    /** Une Boucle PUBLIQUE, elle, remonte a toute l'Organization. */
    public function test_une_adhesion_a_une_boucle_PUBLIQUE_remonte_a_l_organization(): void
    {
        $ligne = $this->emetteur()->emettre(
            FeedEventCatalogue::ADHESION_BOUCLE,
            $this->publique,
            $this->membreDeLaPrivee,
            $this->organization,
            $this->publique,
        );

        $this->assertSame(FeedEventCatalogue::VISIBILITE_ORGANIZATION, $ligne->visibility);
        $this->assertContains($ligne->id, $this->visiblesPour($this->etranger));
    }

    /**
     * La CREATION d'une Boucle privee, elle, remonte — et ce n'est pas une
     * incoherence.
     *
     * Depuis T1075, « privee » veut dire « contenu verrouille », pas
     * « cachee » : toute Boucle active figure deja au catalogue avec son nom,
     * son nombre de membres et ses animateurs. L'annoncer ne revele rien de
     * nouveau. Une ADHESION, si.
     */
    public function test_la_creation_d_une_boucle_privee_remonte_quand_meme(): void
    {
        $ligne = $this->emetteur()->emettre(
            FeedEventCatalogue::BOUCLE_CREEE,
            $this->privee,
            $this->membreDeLaPrivee,
            $this->organization,
        );

        $this->assertSame(FeedEventCatalogue::VISIBILITE_ORGANIZATION, $ligne->visibility);
        $this->assertContains($ligne->id, $this->visiblesPour($this->etranger));
    }

    // ── L'URL directe ──────────────────────────────────────────────────────

    /**
     * Le filtre de liste ne suffit pas : la fiche doit refuser aussi.
     *
     * Sans la regle dans la policy, une ligne restreinte disparaitrait du Flux
     * mais resterait atteignable par son adresse — la garde ne protegerait que
     * ceux qui ne cherchent pas. Et comme `react`/`comment` delegent a `view`,
     * c'est aussi ce qui les ferme.
     */
    public function test_la_fiche_d_une_ligne_restreinte_est_refusee_a_un_etranger(): void
    {
        $ligne = $this->emetteur()->emettre(
            FeedEventCatalogue::ADHESION_BOUCLE,
            $this->privee,
            $this->membreDeLaPrivee,
            $this->organization,
            $this->privee,
        );

        $this->assertFalse($this->etranger->can('view', $ligne));
        $this->assertFalse($this->etranger->can('react', $ligne));
        $this->assertFalse($this->etranger->can('comment', $ligne));

        // Et la reciproque, pour le membre.
        $this->assertTrue($this->membreDeLaPrivee->can('view', $ligne));
        $this->assertTrue($this->membreDeLaPrivee->can('react', $ligne));
    }

    // ── L'idempotence, qui est STRUCTURELLE ────────────────────────────────

    /**
     * Deux emissions du meme fait ne produisent qu'UNE ligne.
     *
     * C'est la contrainte de base qui l'achete, pas une verification
     * applicative : le docblock de `MemberNotification` l'a deja paye — « un
     * `create()` direct suffisait a tout contourner. Une convention n'est pas
     * une frontiere. »
     */
    public function test_emettre_deux_fois_le_meme_fait_ne_cree_qu_une_ligne(): void
    {
        $a = $this->emetteur()->emettre(
            FeedEventCatalogue::BOUCLE_CREEE, $this->publique, $this->membreDeLaPrivee, $this->organization,
        );
        $b = $this->emetteur()->emettre(
            FeedEventCatalogue::BOUCLE_CREEE, $this->publique, $this->membreDeLaPrivee, $this->organization,
        );

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, FeedPost::where('type', FeedEventCatalogue::BOUCLE_CREEE)->count());
    }

    /**
     * LA garde qui mesure LA BASE, et pas le code.
     *
     * Le test precedent passerait **meme sans la contrainte** : l'emetteur
     * relit avant d'ecrire, donc il mesure sa propre prudence. Le CDC le dit
     * explicitement — « sinon il mesure le code, pas la base ».
     *
     * Celui-ci court-circuite le service et ecrit deux fois en direct. Si
     * quelqu'un retire l'index unique, ce test — et lui seul — rougit.
     *
     * C'est exactement la lecon du docblock de `MemberNotification` : « un
     * `create()` direct suffisait a tout contourner. Une convention n'est pas
     * une frontiere. »
     */
    public function test_la_contrainte_d_unicite_existe_EN_BASE(): void
    {
        $attributs = [
            'organization_id' => $this->organization->id,
            'user_id' => $this->membreDeLaPrivee->id,
            'type' => FeedEventCatalogue::BOUCLE_CREEE,
            'object_type' => Loop::class,
            'object_id' => $this->publique->id,
            'status' => FeedPost::STATUS_PUBLISHED,
            'visibility' => 'organization',
            'published_at' => now(),
        ];

        FeedPost::create($attributs);

        $this->expectException(\Illuminate\Database\QueryException::class);

        FeedPost::create($attributs);
    }

    /**
     * Et sa RECIPROQUE : les annonces humaines ne sont PAS contraintes entre
     * elles.
     *
     * Elles portent `object_type` et `object_id` a NULL, et les deux moteurs
     * traitent deux NULL comme distincts dans un index unique. Sans cette
     * garde, on pourrait croire que la contrainte interdit a une Organization
     * d'avoir deux annonces — ce qui serait une regression majeure et
     * silencieuse.
     */
    public function test_les_annonces_humaines_ne_sont_pas_contraintes_entre_elles(): void
    {
        foreach (['Premiere annonce', 'Seconde annonce'] as $texte) {
            FeedPost::create([
                'organization_id' => $this->organization->id,
                'user_id' => $this->membreDeLaPrivee->id,
                'type' => FeedPost::TYPE_ANNOUNCEMENT,
                'content' => $texte,
                'status' => FeedPost::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        }

        $this->assertSame(2, FeedPost::where('type', FeedPost::TYPE_ANNOUNCEMENT)->count());
    }

    /** Un type absent du registre est REFUSE — fail-closed. */
    public function test_un_type_inconnu_n_ecrit_rien(): void
    {
        $this->assertNull($this->emetteur()->emettre(
            'event.invente', $this->publique, $this->membreDeLaPrivee, $this->organization,
        ));

        $this->assertSame(0, FeedPost::where('type', 'event.invente')->count());
    }

    /** Un objet qui ne correspond pas au type est refuse, pas ecrit a vide. */
    public function test_un_objet_du_mauvais_type_n_ecrit_rien(): void
    {
        $this->assertNull($this->emetteur()->emettre(
            FeedEventCatalogue::BOUCLE_CREEE, $this->etranger, $this->membreDeLaPrivee, $this->organization,
        ));
    }

    /** L'interrupteur de l'Organization eteint reellement l'emission. */
    public function test_l_interrupteur_eteint_eteint_l_emission(): void
    {
        $this->organization->forceFill(['feed_auto_events_enabled' => false])->save();

        $this->assertNull($this->emetteur()->emettre(
            FeedEventCatalogue::BOUCLE_CREEE, $this->publique, $this->membreDeLaPrivee, $this->organization->fresh(),
        ));
    }

    /** `published_at` peut etre antidate — mais jamais dans le FUTUR. */
    public function test_la_date_peut_etre_antidatee_mais_jamais_future(): void
    {
        $passe = now()->subMonths(3);

        $ancienne = $this->emetteur()->emettre(
            FeedEventCatalogue::BOUCLE_CREEE, $this->publique, $this->membreDeLaPrivee, $this->organization, null, $passe,
        );

        $this->assertSame($passe->toDateString(), $ancienne->published_at->toDateString());
        // `created_at` dit la verite : la ligne a ete ecrite aujourd'hui.
        $this->assertSame(now()->toDateString(), $ancienne->created_at->toDateString());

        $future = $this->emetteur()->emettre(
            FeedEventCatalogue::ADHESION_BOUCLE, $this->publique, $this->membreDeLaPrivee,
            $this->organization, $this->publique, now()->addYear(),
        );

        $this->assertFalse($future->published_at->isFuture(),
            'Une ligne datee du futur flotterait en tete ou disparaitrait des listes.');
    }
}
