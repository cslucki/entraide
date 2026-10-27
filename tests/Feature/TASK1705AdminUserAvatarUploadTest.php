<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1705 — poser la photo d'un compte depuis la LISTE des utilisateurs.
 *
 * Cyril : « sur /admin/users, je voudrais que tu ajoutes un bouton "Upload
 * photos" dans la colonne action, qui permette d'indiquer la photo de
 * l'utilisateur, comme si on allait sur [la page Modifier]. Ceci car certaines
 * personnes ne prennent pas le temps d'uploader leur photo et je vais
 * recuperer leur photo pour eux et la telecharger. »
 *
 * ## Pourquoi un point d'entree a part
 *
 * `updateUser()` valide un formulaire COMPLET — `name` et `email` y sont
 * requis — et reecrit une trentaine de champs. Lui envoyer un seul fichier
 * echouerait ; lui envoyer la ligne entiere depuis la liste reviendrait a
 * reposter des valeurs que personne n'a voulu modifier, et a ecraser toute
 * modification faite ailleurs entre l'affichage de la liste et le clic.
 *
 * ## Ce qui est mesure, par ordre d'importance
 *
 * 1. **Qui peut ecrire.** Un nouveau point d'entree en ECRITURE sur une
 *    surface d'administration est la chose a garder.
 * 2. **Qu'il ne touche QUE la photo.** C'est sa raison d'etre et sa surete.
 * 3. **La regle TASK-1654 sur la banque d'avatars**, qui est la subtilite pour
 *    laquelle le code a ete extrait plutot que recopie.
 */
class TASK1705AdminUserAvatarUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $organization = Organization::factory()->create(['is_active' => true]);
        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $organization->id]);
        $this->membre = User::factory()->create(['is_admin' => false, 'organization_id' => $organization->id]);
    }

    private function url(?User $cible = null): string
    {
        return route('admin.users.avatar.update', $cible ?? $this->membre);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('portrait.jpg', 300, 300);
    }

    // ── 1. Qui peut ecrire ──────────────────────────────────────────────────

    public function test_un_visiteur_anonyme_est_refuse(): void
    {
        $this->post($this->url(), ['avatar' => $this->photo()])->assertRedirect(route('login'));

        $this->assertNull($this->membre->fresh()->avatar);
    }

    /**
     * Un membre ordinaire est refuse.
     *
     * Sans cette garde, n'importe quel compte connecte pourrait remplacer la
     * photo de n'importe qui — une usurpation visuelle silencieuse.
     */
    public function test_un_membre_ordinaire_est_refuse(): void
    {
        $this->actingAs($this->membre)
            ->post($this->url(), ['avatar' => $this->photo()])
            ->assertForbidden();

        $this->assertNull($this->membre->fresh()->avatar);
    }

    public function test_un_superadmin_peut_poser_la_photo(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->url(), ['avatar' => $this->photo()])
            ->assertRedirect();

        $avatar = $this->membre->fresh()->avatar;

        $this->assertNotNull($avatar, 'La photo n\'a pas ete enregistree.');
        $this->assertStringStartsWith('avatars/', $avatar, 'La photo n\'est pas rangee avec les autres avatars.');
        Storage::disk('public')->assertExists($avatar);
    }

    // ── 2. Il ne touche QUE la photo ────────────────────────────────────────

    /**
     * Aucun autre champ ne bouge.
     *
     * C'est la propriete qui rend ce point d'entree sur : il n'y a pas de
     * champ a oublier de valider. Un correctif qui le ferait passer par
     * `updateUser()` echouerait ici.
     */
    public function test_aucun_autre_champ_n_est_modifie(): void
    {
        $avant = $this->membre->fresh();

        $this->actingAs($this->superAdmin)->post($this->url(), [
            'avatar' => $this->photo(),
            // Des champs que `updateUser()` accepterait, glisses dans la MEME
            // requete : ils ne doivent avoir aucun effet.
            'name' => 'Nom detourne',
            'email' => 'detourne@exemple.test',
            'is_admin' => true,
            'banned' => true,
        ])->assertRedirect();

        $apres = $this->membre->fresh();

        $this->assertSame($avant->name, $apres->name, 'Le nom a ete modifie par l\'envoi d\'une photo.');
        $this->assertSame($avant->email, $apres->email, 'L\'adresse a ete modifiee par l\'envoi d\'une photo.');
        $this->assertSame($avant->is_admin, $apres->is_admin, 'Le droit d\'administration a ete modifie.');
        $this->assertSame($avant->banned_at, $apres->banned_at, 'Le bannissement a ete modifie.');
        $this->assertNotNull($apres->avatar);
    }

    // ── 3. Ce qui est refuse comme fichier ──────────────────────────────────

    public function test_un_fichier_qui_n_est_pas_une_image_est_refuse(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->url(), ['avatar' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($this->membre->fresh()->avatar);
    }

    public function test_une_image_trop_lourde_est_refusee(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->url(), ['avatar' => UploadedFile::fake()->image('enorme.jpg')->size(3000)])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($this->membre->fresh()->avatar);
    }

    public function test_une_requete_sans_fichier_est_refusee(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->url(), [])
            ->assertSessionHasErrors('avatar');
    }

    // ── 4. La regle TASK-1654 sur la banque d'avatars ───────────────────────

    /** Un upload individuel remplace est bien SUPPRIME : pas de fuite de stockage. */
    public function test_l_ancien_upload_individuel_est_supprime(): void
    {
        $this->actingAs($this->superAdmin)->post($this->url(), ['avatar' => $this->photo()]);
        $premier = $this->membre->fresh()->avatar;
        Storage::disk('public')->assertExists($premier);

        $this->actingAs($this->superAdmin)->post($this->url(), ['avatar' => $this->photo()]);
        $second = $this->membre->fresh()->avatar;

        $this->assertNotSame($premier, $second);
        Storage::disk('public')->assertMissing($premier);
        Storage::disk('public')->assertExists($second);
    }

    /**
     * Un avatar de BANQUE n'est jamais supprime.
     *
     * TASK-1654 : il est publie a un chemin PARTAGE, sans discriminant
     * d'Organization. Le supprimer ici priverait de son image tout persona, de
     * toute sandbox, qui declare la meme cle. C'est precisement la subtilite
     * pour laquelle ce code a ete EXTRAIT de `updateUser()` plutot que
     * recopie — une copie oubliee serait exactement ce defaut.
     */
    public function test_un_avatar_de_banque_n_est_jamais_supprime(): void
    {
        $banque = $this->cheminDeBanque();
        Storage::disk('public')->put($banque, '<svg/>');
        $this->membre->update(['avatar' => $banque]);

        $this->actingAs($this->superAdmin)->post($this->url(), ['avatar' => $this->photo()]);

        Storage::disk('public')->assertExists($banque);
        $this->assertNotSame($banque, $this->membre->fresh()->avatar);
    }

    /** Un chemin de banque REEL, demande a la banque elle-meme. */
    private function cheminDeBanque(): string
    {
        foreach (glob(base_path('resources/scenario-manifest/avatar-banks/*')) as $fichier) {
            $banque = pathinfo($fichier, PATHINFO_FILENAME);
            $cles = ManifestAvatarBank::keys($banque);

            if ($cles) {
                $chemin = ManifestAvatarBank::publishedPath($banque, $cles[0]);

                // Non-vacuite : si la banque cessait de reconnaitre ses
                // propres chemins, ce test deviendrait vert sans rien mesurer.
                $this->assertTrue(ManifestAvatarBank::isPublishedAsset($chemin));

                return $chemin;
            }
        }

        $this->fail('Aucune banque d\'avatars : le volet TASK-1654 ne mesurerait rien.');
    }

    // ── 5. Le bouton est dans la liste, et bien forme ───────────────────────

    public function test_le_bouton_est_rendu_dans_la_colonne_actions(): void
    {
        $html = $this->actingAs($this->superAdmin)->get('/admin/users')->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('/data-user-avatar-upload="'.preg_quote($this->membre->id, '/').'"/', $html),
            'Le bouton « Upload photos » manque sur la ligne du membre.'
        );
        $this->assertStringContainsString(e(__('admin.users_avatar_button')), $html);
    }

    /**
     * Le formulaire est un FRERE, jamais un imbrique.
     *
     * La cellule porte deja celui de « Se connecter sous ». Un `<form>` dans
     * un `<form>` est invalide en HTML et reste INVISIBLE aux tests serveur :
     * le navigateur en ignore un, et le geste echoue sans erreur. C'est le
     * defaut que TASK-1668 a corrige ailleurs.
     */
    public function test_le_formulaire_n_est_pas_imbrique(): void
    {
        $html = $this->actingAs($this->superAdmin)->get('/admin/users')->assertOk()->getContent();

        $this->assertSame(
            0,
            preg_match('#<form[^>]*>(?:(?!</form>).)*?<form#s', $html),
            'Un formulaire est imbrique dans un autre : le navigateur en ignorera un.'
        );
    }

    /**
     * La racine Alpine de la page existe TOUJOURS.
     *
     * C'est le piege que ce fichier Blade nomme lui-meme en tete (TASK-1640) :
     * « une directive Alpine sans racine `x-data` sur un ancetre est inerte ET
     * silencieuse ». Le bouton se rendrait, le fichier s'ouvrirait, et rien ne
     * partirait — sans la moindre erreur. Aucune autre garde de ce fichier ne
     * le verrait : elles mesurent toutes le HTML, et le HTML serait correct.
     *
     * La mesure porte sur l'ORDRE : la racine doit PRECEDER le champ dans le
     * document, faute de quoi elle ne l'enveloppe pas.
     */
    public function test_la_racine_alpine_enveloppe_le_champ(): void
    {
        $html = $this->actingAs($this->superAdmin)->get('/admin/users')->assertOk()->getContent();

        $racine = strpos($html, '<div x-data="{}">');
        $champ = strpos($html, 'data-user-avatar-upload=');

        $this->assertNotFalse($racine, 'La racine Alpine de la page a disparu : le bouton serait inerte et SILENCIEUX.');
        $this->assertNotFalse($champ);
        $this->assertLessThan($champ, $racine, 'La racine Alpine ne precede plus le champ : elle ne l\'enveloppe pas.');
    }

    /** Le champ declenche l'envoi au choix du fichier. */
    public function test_le_champ_declenche_l_envoi(): void
    {
        $html = $this->actingAs($this->superAdmin)->get('/admin/users')->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('/data-user-avatar-upload="[^"]*"(?:(?!<\/label>).)*?x-on:change="\$el\.form\.submit\(\)"/s', $html),
            'Le champ ne declenche pas l\'envoi : le bouton ne ferait rien.'
        );
    }
}
