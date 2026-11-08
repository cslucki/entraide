<?php

namespace Tests\Feature;

use App\Models\FeedPostComment;
use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1788, correctif 09 — le Flux sur telephone et en PWA.
 *
 * Cyril : « cette page n'est pas du tout responsive et pas adaptee au
 * smartphone et a la PWA ».
 *
 * ## ⚠️ Ce que ces tests tiennent, et ce qu'ils ne tiennent PAS
 *
 * Aucun test PHP ne mesure une mise en page. Ce fichier tient donc les
 * DECISIONS de mise en page — les classes qui les portent — et rien d'autre :
 *
 * - l'en-tete s'empile sous `sm` au lieu d'ecraser le titre entre deux
 *   boutons epingles a droite ;
 * - les commandes qu'on presse au doigt font au moins 44 px.
 *
 * La preuve visuelle appartient a la recette, sur un vrai telephone. Elle est
 * demandee explicitement : je ne peux pas ouvrir de session, et le Flux n'est
 * pas accessible sans compte (302).
 */
class TASK1788Correctif09FluxResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788-09', 'is_active' => true, 'locale' => 'fr',
            // PRECONDITION : sans ce mode, `FeedPostPolicy::create()` refuse a
            // un membre ordinaire et le bloc des actions n'est jamais rendu —
            // le test mesurerait une absence sans rapport avec la mise en page.
            'feed_post_publish_mode' => 'members',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.flux', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    public function test_l_en_tete_s_empile_sur_telephone_au_lieu_d_ecraser_le_titre(): void
    {
        $html = $this->page();

        // TEMOIN : c'est bien la page du Flux.
        $this->assertStringContainsString(e(__('feed.title')), $html,
            'Temoin absent : la page du Flux n\'est pas rendue.');

        $this->assertStringContainsString(
            'flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between',
            $html,
            'L\'en-tete ne s\'empile plus sous `sm` : le titre serait ecrase entre les boutons.'
        );
        $this->assertStringNotContainsString(
            '<div class="mb-6 flex items-start justify-between gap-4">',
            $html,
            'L\'ancienne rangee sans point de rupture est encore rendue.'
        );
    }

    /**
     * Les deux boutons de l'en-tete : pleine largeur au doigt, rangee au-dela.
     *
     * `shrink-0` ne doit plus s'appliquer qu'a partir de `sm` — c'est lui qui
     * reservait la largeur des boutons au detriment du titre.
     */
    public function test_les_boutons_de_l_en_tete_prennent_la_largeur_sur_telephone(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-feed-actions', $html,
            'Temoin absent : le bloc des actions n\'est pas rendu (droit de publier ?).');
        $this->assertStringContainsString('flex w-full items-center gap-2 sm:w-auto sm:shrink-0', $html,
            'Les boutons ne prennent pas la largeur sur telephone.');
    }

    // ── L'en-tete allege ─────────────────────────────────────────────────────

    /**
     * « Organisation » et le sous-titre sont retires.
     *
     * Trois lignes avant le premier contenu sur un telephone, pour dire ce que
     * le titre dit deja. Les cles restent DEFINIES : ce correctif est un
     * changement d'ecran, pas un menage de lexique — un test le tient, pour
     * qu'un futur passage ne les supprime pas par distraction.
     */
    public function test_l_en_tete_ne_porte_plus_ni_eyebrow_ni_sous_titre(): void
    {
        $html = $this->page();

        $this->assertStringContainsString(e(__('feed.title')), $html,
            'Temoin absent : la page du Flux n\'est pas rendue.');

        $this->assertStringNotContainsString(e(__('feed.organization')), $html,
            'Le libelle « Organisation » est encore rendu.');
        $this->assertStringNotContainsString(e(__('feed.subtitle')), $html,
            'Le sous-titre est encore rendu.');
    }

    public function test_les_cles_retirees_de_l_ecran_restent_definies(): void
    {
        foreach (['feed.organization', 'feed.subtitle', 'feed.view_comments'] as $cle) {
            $this->assertNotSame($cle, __($cle), "La cle `{$cle}` n'est plus definie : c'est un menage, pas ce correctif.");
        }
    }

    // ── Les commentaires ─────────────────────────────────────────────────────

    /**
     * ⚠️ LA cause du « on voit 2 fois son commentaire ».
     *
     * La carte affichait un apercu du DERNIER commentaire au-dessus de la
     * barre, et le fil complet s'ouvrait en dessous : apres avoir poste, on
     * voyait donc son texte deux fois. Retirer l'apercu corrige les deux
     * demandes d'un coup — « on ne devrait en voir aucun » et le doublon.
     */
    public function test_aucun_commentaire_n_est_affiche_avant_d_ouvrir_le_fil(): void
    {
        $annonce = $this->annonce();

        $this->commentaire($annonce, 'UN COMMENTAIRE QUI NE DOIT PAS SE REPETER');

        $html = $this->page();

        // TEMOIN : la carte EST rendue, et le compteur voit bien le commentaire.
        $this->assertStringContainsString('Une annonce de mesure', $html,
            'Temoin absent : la carte n\'est pas rendue.');

        // Le fil est dans le document mais replie (`x-show="showComments"`,
        // `false` au depart) : il ne doit donc paraitre QU'UNE fois, dans le
        // bloc repliable — jamais en apercu au-dessus de la barre.
        $this->assertSame(1, substr_count($html, 'UN COMMENTAIRE QUI NE DOIT PAS SE REPETER'),
            'Le commentaire est rendu deux fois : l\'apercu « dernier commentaire » est revenu.');
    }

    /** Le lien « Voir les N commentaires » et sa fenetre ont disparu. */
    public function test_le_lien_et_la_fenetre_des_commentaires_ont_disparu(): void
    {
        $annonce = $this->annonce();

        foreach (['Premier commentaire', 'Second commentaire'] as $contenu) {
            $this->commentaire($annonce, $contenu);
        }

        $html = $this->page();

        $this->assertStringContainsString('Une annonce de mesure', $html,
            'Temoin absent : la carte n\'est pas rendue.');

        $this->assertStringNotContainsString("open-modal', 'comments-", $html,
            'Le declencheur de la fenetre des commentaires est encore rendu.');
        $this->assertStringNotContainsString('comments-'.$annonce->id, $html,
            'La fenetre des commentaires est encore rendue.');
    }

    /** Pas de fabrique pour `FeedPost` dans ce depot : creation directe. */
    private function annonce(): FeedPost
    {
        return FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->membre->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'title' => 'Une annonce de mesure',
            'content' => 'Le corps de l\'annonce.',
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
        ]);
    }

    /**
     * ⚠️ `is_approved` est OBLIGATOIRE : la relation `comments()` filtre
     * dessus. Un commentaire cree sans lui ne serait jamais charge, et le test
     * du doublon mesurerait une absence.
     */
    private function commentaire(FeedPost $annonce, string $contenu): void
    {
        FeedPostComment::create([
            'feed_post_id' => $annonce->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->membre->id,
            'content' => $contenu,
            'is_approved' => true,
        ]);
    }

    /**
     * La disposition d'un fil de commentaires, « comme Facebook ».
     *
     * Trois choses mesurees ici, et la troisieme est celle qui se serait
     * cassee en silence : l'ORDRE. La relation `comments()` est `latest()` —
     * le plus recent d'abord. Avec le champ en bas, il faut l'inverse, sinon
     * la reponse qu'on vient d'ecrire apparait tout en haut, loin du champ.
     */
    public function test_le_fil_a_les_avatars_a_gauche_et_le_champ_en_bas(): void
    {
        $annonce = $this->annonce();

        $this->commentaire($annonce, 'LE PLUS ANCIEN');
        $this->travel(2)->minutes();
        $this->commentaire($annonce, 'LE PLUS RECENT');
        $this->travelBack();

        $html = $this->page();

        $debut = strpos($html, '<article id="feed-post-');
        $this->assertNotFalse($debut, 'Temoin absent : la carte n\'est pas rendue.');
        $carte = substr($html, $debut, strpos($html, '</article>', $debut) - $debut);

        // 1. un avatar par commentaire, a gauche.
        $this->assertSame(2, substr_count($carte, 'data-feed-comment>'),
            'Les commentaires ne sont pas rendus dans leur rangee a avatar.');

        // 2. le champ vient APRES les commentaires.
        $positionFormulaire = strpos($carte, 'data-feed-comment-form');
        $this->assertNotFalse($positionFormulaire, 'Temoin absent : le champ de saisie n\'est pas rendu.');
        $this->assertGreaterThan(strrpos($carte, 'data-feed-comment>'), $positionFormulaire,
            'Le champ de saisie passe AVANT les commentaires.');

        // 3. du plus ancien au plus recent.
        $this->assertLessThan(
            strpos($carte, 'LE PLUS RECENT'),
            strpos($carte, 'LE PLUS ANCIEN'),
            'Le fil est rendu du plus recent au plus ancien : la derniere reponse atterrit loin du champ.'
        );
    }

    /**
     * L'avatar d'un commentaire mene au PROFIL PUBLIC de son auteur.
     *
     * Le lien passe par `FeedEventRenderer::lienProfil()` — la meme autorite
     * que la ligne d'evenement de la carte. Elle rend `null` pour une personne
     * desactivee, et la pastille reste alors une image : un lien qui mene a un
     * 404 est pire que pas de lien.
     */
    public function test_l_avatar_d_un_commentaire_mene_au_profil_public(): void
    {
        $annonce = $this->annonce();

        $auteur = User::factory()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Ottoline', 'name' => 'Kaminski',
        ]);

        \App\Models\FeedPostComment::create([
            'feed_post_id' => $annonce->id,
            'organization_id' => $this->organization->id,
            'user_id' => $auteur->id,
            'content' => 'Un commentaire signe.',
            'is_approved' => true,
        ]);

        $html = $this->page();

        $this->assertStringContainsString('Un commentaire signe.', $html,
            'Temoin absent : le commentaire n\'est pas rendu.');

        $this->assertStringContainsString('data-feed-comment-avatar="'.$auteur->id.'"', $html,
            'L\'avatar du commentaire n\'est pas un lien.');

        $attendu = route('organization.profile.show', [
            'organization' => $this->organization->slug,
            'user' => $auteur->slug ?: $auteur->getKey(),
        ]);
        $this->assertStringContainsString(e($attendu), $html,
            'L\'avatar ne mene pas au profil public de son auteur.');
    }

    /** Une personne desactivee n'a pas de profil : la pastille reste une image. */
    public function test_l_avatar_d_un_compte_desactive_n_est_pas_un_lien(): void
    {
        $annonce = $this->annonce();

        $auteur = User::factory()->create(['organization_id' => $this->organization->id]);

        \App\Models\FeedPostComment::create([
            'feed_post_id' => $annonce->id,
            'organization_id' => $this->organization->id,
            'user_id' => $auteur->id,
            'content' => 'Un commentaire orphelin.',
            'is_approved' => true,
        ]);

        $auteur->update(['banned_at' => now()]);

        $html = $this->page();

        // TEMOIN : le commentaire est bien rendu, c'est l'avatar qui change.
        $this->assertStringContainsString('Un commentaire orphelin.', $html,
            'Temoin absent : le commentaire n\'est pas rendu.');
        $this->assertStringNotContainsString('data-feed-comment-avatar="'.$auteur->id.'"', $html,
            'L\'avatar d\'un compte desactive est rendu comme un lien.');
    }

    /**
     * La pastille du fil est PLEINE, comme dans les ChatLoops.
     *
     * `x-user-avatar` dessinait un disque translucide (`/12`, `/25` en sombre) :
     * sur le fond sombre du Flux, il ne restait que les initiales, sans
     * pastille. La variante `solid` remplit le disque.
     *
     * ⚠️ Elle ne REPREND PAS la recette du ChatLoop : la sienne passe par
     * `avatar_url`, qui retombe sur ui-avatars.com — un appel a un tiers a qui
     * on envoie le nom de la personne. On copie l'aspect, pas le moyen ; un
     * test le tient.
     */
    public function test_la_pastille_du_fil_est_pleine_et_ne_sort_pas_vers_un_tiers(): void
    {
        $annonce = $this->annonce();
        $this->commentaire($annonce, 'Un commentaire avec pastille.');

        $html = $this->page();

        $debut = strpos($html, '<article id="feed-post-');
        $this->assertNotFalse($debut, 'Temoin absent : la carte n\'est pas rendue.');
        $carte = substr($html, $debut, strpos($html, '</article>', $debut) - $debut);

        $this->assertStringContainsString('Un commentaire avec pastille.', $carte,
            'Temoin absent : le commentaire n\'est pas rendu.');

        // Pleine, pas translucide.
        $this->assertStringContainsString('bg-[var(--bp-primary)] text-white', $carte,
            'La pastille du fil n\'est pas pleine.');
        $this->assertStringNotContainsString('bg-[var(--bp-primary)]/25', $carte,
            'La pastille translucide est encore rendue dans le fil.');

        // Et aucun appel a un tiers pour un membre sans photo.
        $this->assertStringNotContainsString('ui-avatars.com', $carte,
            'Le fil appelle ui-avatars.com : le nom de la personne part chez un tiers.');
    }

    /**
     * ⚠️ Ce test existe parce qu'un SABOTAGE est reste vert.
     *
     * Basculer le DEFAUT de `x-user-avatar` de `soft` a `solid` ne faisait
     * rougir aucun test : les sept autres surfaces qui emploient ce composant
     * (Boucles, Dossiers, roster de gouvernance, ajout de membres…) auraient
     * change d'aspect en silence.
     *
     * La variante ajoutee par ce correctif doit rester OPT-IN. Ce test garde
     * le defaut, pas le Flux.
     */
    public function test_la_pastille_reste_translucide_par_defaut_ailleurs(): void
    {
        $quelquun = User::factory()->create([
            'organization_id' => $this->organization->id,
            'avatar' => null,
        ]);

        $rendu = \Illuminate\Support\Facades\Blade::render(
            '<x-user-avatar :user="$user" size="md" />',
            ['user' => $quelquun]
        );

        $this->assertStringContainsString('bg-[var(--bp-primary)]/12', $rendu,
            'Le defaut de `x-user-avatar` n\'est plus le disque translucide : sept surfaces changent d\'aspect.');
        $this->assertStringNotContainsString('bg-[var(--bp-primary)] text-white', $rendu,
            'Le defaut de `x-user-avatar` est devenu la pastille pleine.');
    }

    /**
     * La palette de reactions se REFERME au choix, et tient moins de place.
     *
     * Elle restait ouverte apres le clic : on avait reagi, et la rangee de six
     * pastilles continuait de pousser la carte vers le bas sans plus rien
     * proposer.
     *
     * ⚠️ Ce que ce test tient : la fermeture est DECLAREE sur chaque pastille,
     * et la taille est descendue a 36 px. Ce qu'il ne tient pas : le
     * comportement a l'ecran — aucun test PHP n'execute Alpine. La recette le
     * voit.
     */
    public function test_la_palette_de_reactions_se_referme_au_choix(): void
    {
        $this->annonce();

        $html = $this->page();

        $debut = strpos($html, 'data-feed-reactions');
        $this->assertNotFalse($debut, 'Temoin absent : la palette n\'est pas rendue.');

        $palette = substr($html, $debut, strpos($html, '</div>', $debut) - $debut);

        // Six pastilles, chacune refermant la palette APRES l'enregistrement.
        $this->assertSame(6, substr_count($palette, 'data-feed-reaction="'),
            'La palette ne propose plus six reactions.');

        // ⚠️ UN seul gestionnaire, et il est ordonne : `$wire.…().then(…)`.
        // Deux ecouteurs independants sur le meme clic — `wire:click` plus
        // `@click` — laissaient l'un cacher l'element pendant que l'autre
        // devait encore composer sa requete : changer de reaction ou annuler
        // la sienne ne produisait plus rien.
        $this->assertSame(6, substr_count($palette, '.then(() => showReactions = false)'),
            'La fermeture de la palette n\'attend pas l\'enregistrement de la reaction.');
        $this->assertStringNotContainsString('wire:click="toggleReaction', $palette,
            'Le double gestionnaire (`wire:click` + `@click`) est revenu.');

        // Plus petite : 36 px, et plus les 44 des commandes principales.
        $this->assertStringContainsString('min-h-[36px] min-w-[36px]', $palette,
            'Les pastilles de reaction ne sont pas passees a 36 px.');
        $this->assertStringNotContainsString('min-h-[44px] min-w-[44px]', $palette,
            'Les pastilles de reaction occupent encore 44 px.');
    }

    /**
     * La commande montre MA reaction, facon Facebook.
     *
     * Et le serveur, lui, etait deja juste : meme type -> la ligne est
     * supprimee ; type different -> elle est modifiee. Ce test le fixe aussi,
     * pour qu'on ne cherche plus la cause du mauvais cote le jour ou l'ecran
     * ne suit pas.
     */
    public function test_la_commande_affiche_ma_propre_reaction(): void
    {
        $annonce = $this->annonce();

        $html = $this->page();
        $this->assertStringContainsString('data-feed-reaction-trigger=""', $html,
            'Sans reaction de ma part, la commande ne doit porter aucun type.');
        $this->assertStringContainsString('👍', $html, 'Le pouce d\'invitation a disparu.');

        // Je reagis avec un coeur.
        Livewire::actingAs($this->membre)
            ->test(\App\Livewire\OrganizationFeed::class)
            ->call('toggleReaction', $annonce->id, 'heart');

        $this->assertDatabaseHas('reactions', [
            'user_id' => $this->membre->id,
            'reactionable_id' => $annonce->id,
            'reaction_type' => 'heart',
        ]);

        $html = $this->page();
        $this->assertStringContainsString('data-feed-reaction-trigger="heart"', $html,
            'La commande n\'affiche pas MA reaction.');
        $this->assertStringContainsString(\App\Models\Reaction::emojiMap()['heart'], $html,
            'L\'emoji de ma reaction n\'est pas rendu.');
    }

    /**
     * ⚠️ Le serveur etait JUSTE — mesure avant d'y toucher.
     *
     * « Aucun changement » quand on change ou annule sa reaction etait un
     * defaut de NAVIGATEUR, pas de logique. Ce test fixe la logique pour que
     * la prochaine enquete ne reparte pas de ce cote.
     */
    public function test_changer_puis_annuler_sa_reaction_agit_bien_en_base(): void
    {
        $annonce = $this->annonce();

        $composant = Livewire::actingAs($this->membre)->test(\App\Livewire\OrganizationFeed::class);

        $composant->call('toggleReaction', $annonce->id, 'thumbs_up');
        $this->assertSame(1, \App\Models\Reaction::count(), 'La premiere reaction n\'a pas ete creee.');
        $this->assertSame('thumbs_up', \App\Models\Reaction::first()->reaction_type);

        // Un autre type REMPLACE, il n'ajoute pas.
        $composant->call('toggleReaction', $annonce->id, 'heart');
        $this->assertSame(1, \App\Models\Reaction::count(), 'Changer de reaction a ajoute une ligne au lieu de la modifier.');
        $this->assertSame('heart', \App\Models\Reaction::first()->reaction_type, 'Le changement de reaction n\'a pas pris.');

        // Le meme type ANNULE.
        $composant->call('toggleReaction', $annonce->id, 'heart');
        $this->assertSame(0, \App\Models\Reaction::count(), 'Recliquer la meme reaction ne l\'annule pas.');
    }

    /**
     * Le fil se rafraichit sans rechargement — point 10.
     *
     * Un commentaire ou une reaction de quelqu'un d'autre n'arrivait qu'au
     * rechargement de la page.
     *
     * ⚠️ `.visible` est la moitie qui compte : sans lui, un onglet laisse
     * ouvert en arriere-plan interrogerait le serveur toutes les 15 secondes
     * pour un ecran que personne ne regarde — sur une PWA posee sur un
     * telephone, c'est la batterie.
     *
     * Cout mesure : **9 requetes** par passage sur un fil de 15 annonces.
     */
    public function test_le_fil_se_rafraichit_seul_et_seulement_s_il_est_visible(): void
    {
        $this->annonce();

        $html = $this->page();

        $this->assertStringContainsString('data-feed-poll', $html,
            'Temoin absent : la racine du fil n\'est pas rendue.');
        $this->assertStringContainsString('wire:poll.visible.15s', $html,
            'Le fil ne se rafraichit pas tout seul.');
        $this->assertStringNotContainsString('wire:poll="', $html,
            'Un sondage sans `.visible` interrogerait le serveur onglet ferme.');
    }

    /**
     * Ce qu'un passage de sondage rapporte : ce que les AUTRES ont fait.
     *
     * Le test ne simule pas le minuteur — il refait ce que fait le passage,
     * un `$refresh`, et verifie que le rendu suivant porte bien le
     * commentaire ecrit entre-temps par quelqu'un d'autre.
     */
    public function test_un_passage_rapporte_le_commentaire_d_un_autre(): void
    {
        $annonce = $this->annonce();

        $composant = Livewire::actingAs($this->membre)->test(\App\Livewire\OrganizationFeed::class);
        $composant->assertDontSee('ECRIT PAR QUELQU UN D AUTRE');

        $autre = User::factory()->create(['organization_id' => $this->organization->id]);
        \App\Models\FeedPostComment::create([
            'feed_post_id' => $annonce->id,
            'organization_id' => $this->organization->id,
            'user_id' => $autre->id,
            'content' => 'ECRIT PAR QUELQU UN D AUTRE',
            'is_approved' => true,
        ]);

        $composant->call('$refresh')->assertSee('ECRIT PAR QUELQU UN D AUTRE');
    }

    /**
     * ⚠️ LE point « PWA » : on presse au DOIGT.
     *
     * Les commandes du fil faisaient une vingtaine de pixels de haut. 44 px est
     * la cible que le reste du produit respecte deja.
     */
    public function test_les_commandes_du_fil_sont_pressables_au_doigt(): void
    {
        $this->annonce();

        $html = $this->page();

        // TEMOIN : la carte EST rendue — sans elle, aucune commande a mesurer.
        $this->assertStringContainsString('Une annonce de mesure', $html,
            'Temoin absent : la carte de l\'annonce n\'est pas rendue.');

        // ⚠️ L'assertion doit porter sur la CARTE, pas sur la page : les deux
        // boutons de l'en-tete portent eux aussi `min-h-[44px]`, et les
        // compter faisait passer le test meme quand la carte, elle, n'avait
        // plus aucune cible tactile. Le sabotage l'a montre.
        $debut = strpos($html, '<article id="feed-post-');
        $this->assertNotFalse($debut, 'Temoin absent : la carte n\'est pas rendue.');

        $carte = substr($html, $debut, strpos($html, '</article>', $debut) - $debut);

        $this->assertGreaterThanOrEqual(3, substr_count($carte, 'min-h-[44px]'),
            'Les commandes de la carte ne declarent pas de cible tactile de 44 px.');
    }
}
