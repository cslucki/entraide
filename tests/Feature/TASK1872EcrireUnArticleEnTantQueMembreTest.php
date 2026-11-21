<?php

namespace Tests\Feature;

use App\Livewire\LoopArticleCard;
use App\Livewire\LoopDossiersCard;
use App\Models\Dossier;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1872, correctif 04 — ecrire un Article quand on est MEMBRE d'une Boucle.
 *
 * ## Le defaut, tel que Cyril l'a rapporte
 *
 * « Quand on est dans une boucle en tant que membre et si la card Articles ou
 * Dossiers sont presentes, on doit pouvoir ecrire un article. » Or :
 *
 * - le bouton « + Ecrire un Article » de la Card Articles pointait vers
 *   l'ecran du Dossier au lieu d'ouvrir la saisie ;
 * - le FAB « + Nouveau » du module Dossiers n'offrait pas l'entree.
 *
 * ## Pourquoi une ability neuve, et non une ligne dans la matrice
 *
 * `attachArticle` gardait ces deux surfaces et lit `dossiers.create_article` ;
 * `detachArticle` lui delegue. Accorder cette capacite au socle du membre
 * aurait donc ouvert, sans que personne ne le demande : detacher l'Article
 * d'un autre, voir les brouillons non publies des autres, et les deplacer.
 *
 * **La moitie de ce fichier existe pour cela** : prouver que l'ouverture est
 * restee etroite. Un test qui ne verifierait que le cas heureux passerait tout
 * aussi bien avec l'elargissement qu'on a refuse.
 */
class TASK1872EcrireUnArticleEnTantQueMembreTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $membre;

    private User $etranger;

    private $loop;

    private Dossier $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->owner, 'Boucle d\'ecriture', type: 'project');
        $service->addMember($this->loop, $this->membre, 'member');

        /* ⚠️ LES DEUX CARTES SONT ALLUMEES EXPLICITEMENT, et ce n'est pas un
           detail de decor.
           ------------------------------------------------------------------
           `writing.compose` porte `requires_card => core.article`. Le preset
           du type `project` ne contient PAS cette carte — seul le type
           `writing` la porte, et il est encore `available => false`. Sans ces
           lignes, le membre serait refuse pour une raison qui n'a rien a voir
           avec ce correctif, et j'ai d'abord pris ce rouge pour une limite du
           produit : c'etait ma fixture.

           `createLoop()` a DEJA pose les lignes du preset : un `create()` sec
           violait la contrainte d'unicite. On se contente donc d'allumer
           celle qui manque, sans toucher aux autres. */
        LoopCard::updateOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.article'],
            ['organization_id' => $this->organization->id, 'enabled' => true],
        );

        $this->racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();
    }

    /* ─────────── LE MEMBRE PEUT ECRIRE ─────────── */

    public function test_un_membre_simple_peut_creer_un_article_dans_la_boucle(): void
    {
        $this->assertTrue($this->membre->can('createArticle', $this->racine));
    }

    /** Et le proprietaire n'a evidemment rien perdu. */
    public function test_le_proprietaire_peut_toujours_creer_un_article(): void
    {
        $this->assertTrue($this->owner->can('createArticle', $this->racine));
    }

    /* ─────────── ET RIEN D'AUTRE NE S'EST OUVERT ─────────── */

    /**
     * La contre-mesure qui donne son sens a la premiere.
     *
     * Si l'ouverture avait ete posee en ajoutant `dossiers.create_article` au
     * socle du membre, CHACUNE de ces quatre assertions tomberait — et avec
     * elles le verrou du detachement et celui des brouillons d'autrui.
     */
    public function test_ecrire_n_ouvre_n_i_le_rattachement_n_i_le_detachement_n_i_l_administration(): void
    {
        $this->assertFalse($this->membre->can('attachArticle', $this->racine),
            'Rattacher l\'Article d\'un autre reste ferme au membre simple.');
        $this->assertFalse($this->membre->can('detachArticle', $this->racine),
            'Detacher l\'Article d\'un autre reste ferme au membre simple.');
        $this->assertFalse($this->membre->can('update', $this->racine));
        $this->assertFalse($this->membre->can('manageFiles', $this->racine));
    }

    /** Qui n'est pas de la Boucle n'ecrit pas dedans, meme dans son Organization. */
    public function test_un_non_membre_de_la_boucle_ne_peut_pas_ecrire(): void
    {
        $this->assertFalse($this->etranger->can('createArticle', $this->racine));
    }

    /** Une Boucle archivee est en lecture seule — pour TOUT LE MONDE. */
    public function test_une_boucle_archivee_refuse_l_ecriture_y_compris_au_proprietaire(): void
    {
        $this->loop->update(['status' => 'archived']);
        $this->racine->refresh()->load('loop');

        $this->assertFalse($this->membre->can('createArticle', $this->racine->fresh()));
        $this->assertFalse($this->owner->can('createArticle', $this->racine->fresh()),
            'L\'archivage etait tenu par `authorize(update)` dans le controleur : '
            .'si la verification passait APRES le raccourci proprietaire, elle serait perdue.');
    }

    /** Un compte desactive ne passe plus, quelle que soit son appartenance. */
    public function test_un_compte_desactive_ne_peut_pas_ecrire(): void
    {
        $this->membre->update(['banned_at' => now()]);

        $this->assertFalse($this->membre->fresh()->can('createArticle', $this->racine));
    }

    /* ─────────── LES DEUX SURFACES PROPOSENT LE GESTE ─────────── */

    /** La Card Articles ouvre la saisie, au lieu de renvoyer vers le Dossier. */
    public function test_la_card_articles_propose_la_saisie_au_membre(): void
    {
        Livewire::actingAs($this->membre)
            ->test(LoopArticleCard::class, ['loop' => $this->loop])
            ->assertSee('data-article-card-write-form', false)
            // Le formulaire poste vers le parcours EXISTANT, celui que la Card
            // Dossiers emprunte deja : un seul chemin d'ecriture.
            ->assertSee(route('organization.loops.dossier.articles.store', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop,
            ]), false);
    }

    /** La Card Dossiers aussi : elle demandait `update`, donc le role owner. */
    public function test_la_card_dossiers_propose_la_saisie_au_membre(): void
    {
        Livewire::actingAs($this->membre)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.cards.dossiers.create_article'));
    }

    /** Une Boucle archivee n'affiche le geste sur aucune des deux. */
    public function test_une_boucle_archivee_ne_propose_la_saisie_nulle_part(): void
    {
        $this->loop->update(['status' => 'archived']);

        Livewire::actingAs($this->membre)
            ->test(LoopArticleCard::class, ['loop' => $this->loop->fresh()])
            ->assertDontSee('data-article-card-write-form', false);

        Livewire::actingAs($this->membre)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop->fresh()])
            ->assertDontSee(__('loops.cards.dossiers.create_article'));
    }

    /**
     * LA FRONTIERE DE CE CORRECTIF, ecrite noir sur blanc.
     *
     * L'ouverture passe par `writing.compose`, qui porte
     * `requires_card => core.article`. Eteindre la carte Articles d'une Boucle
     * referme donc l'ecriture pour le membre — c'est le mecanisme de
     * composition des Cards qui joue, pas une regle ajoutee ici, et une
     * Organization qui veut l'ouvrir l'allume.
     *
     * Ce test existe parce que ce rouge m'a d'abord fait croire a un defaut du
     * produit : le preset du type `project` ne porte pas cette carte, seul le
     * type `writing` la porte. Qui lira ce fichier saura tout de suite ou
     * regarder.
     *
     * Qui administre la Boucle n'est pas concerne : il passe par
     * `attachArticle`, qui ne depend pas de cette carte.
     */
    public function test_sans_la_carte_articles_le_membre_ne_peut_pas_ecrire(): void
    {
        LoopCard::where('loop_id', $this->loop->id)
            ->where('card_key', 'core.article')
            ->update(['enabled' => false]);

        $this->assertFalse($this->membre->can('createArticle', $this->racine->fresh()),
            'La composition des Cards ne ferme plus l\'ecriture au membre.');

        $this->assertTrue($this->owner->can('createArticle', $this->racine->fresh()),
            'Qui administre la Boucle ne depend pas de cette carte, et ne doit rien perdre.');
    }

    /* ─────────── LE SERVEUR TIENT LA MEME LIGNE ─────────── */

    /** Le bouton ne ment pas : la route accepte reellement le membre. */
    public function test_la_route_d_ecriture_accepte_le_membre(): void
    {
        $this->actingAs($this->membre)
            ->post(route('organization.loops.dossier.articles.store', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop,
            ]), ['title' => 'Mon premier Article de Boucle'])
            ->assertRedirect();

        $this->assertDatabaseHas('blog_posts', [
            'title' => 'Mon premier Article de Boucle',
            'user_id' => $this->membre->id,
            'status' => 'draft',
        ]);
    }

    /** Et elle refuse toujours qui n'est pas de la Boucle. */
    public function test_la_route_d_ecriture_refuse_un_non_membre(): void
    {
        $this->actingAs($this->etranger)
            ->post(route('organization.loops.dossier.articles.store', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop,
            ]), ['title' => 'Article force'])
            ->assertForbidden();

        $this->assertDatabaseMissing('blog_posts', ['title' => 'Article force']);
    }
}
