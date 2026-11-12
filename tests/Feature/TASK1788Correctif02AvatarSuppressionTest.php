<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1788, correctif 02 — supprimer sa photo de profil.
 *
 * On pouvait la REMPLACER depuis TASK-1749, jamais l'ENLEVER : qui en avait
 * depose une n'avait plus aucun chemin pour revenir a ses initiales generees.
 *
 * Le second volet du correctif — le crayon du profil public colle a la photo —
 * est une correction de MISE EN PAGE. Un test PHP ne mesure pas une position a
 * l'ecran ; ce qu'il peut tenir, c'est que la classe qui la gouverne est bien
 * posee, et que le crayon reste reserve a la personne concernee. La preuve
 * visuelle appartient a la recette.
 */
class TASK1788Correctif02AvatarSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788-02', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'avatar' => 'avatars/une-photo-deja-posee.jpg',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function enregistrer(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->membre)->put(route('profile.update'), array_merge([
            'name' => $this->membre->name,
            'email' => $this->membre->email,
            'phone' => '+33600000000',
            'slogan' => 'Je resous des problemes, pas des tickets.',
        ], $extra));
    }

    // ── Le bouton ────────────────────────────────────────────────────────────

    public function test_le_bouton_de_suppression_est_propose_a_qui_a_une_photo(): void
    {
        $page = $this->actingAs($this->membre)->get(route('profile.edit'))->assertOk()->getContent();

        // TEMOIN : la zone de depot de la photo est bien rendue. Sans lui, une
        // page tronquee ferait passer n'importe quelle assertion d'absence.
        $this->assertStringContainsString('id="avatar"', $page, 'Temoin absent : le bloc avatar n\'a pas ete rendu.');

        $this->assertStringContainsString('data-avatar-remove', $page, 'Le bouton de suppression manque alors qu\'une photo est posee.');
        $this->assertStringContainsString('name="supprimer_avatar"', $page, 'Le champ qui porte l\'intention manque.');
    }

    /** Offrir le geste a qui n'a pas de photo serait un bouton sans effet. */
    public function test_le_bouton_de_suppression_n_est_pas_propose_sans_photo(): void
    {
        $this->membre->update(['avatar' => null]);

        $page = $this->actingAs($this->membre)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('id="avatar"', $page, 'Temoin absent : le bloc avatar n\'a pas ete rendu.');

        $this->assertStringNotContainsString('data-avatar-remove', $page, 'Un bouton de suppression est propose sans photo a supprimer.');
        $this->assertStringNotContainsString('name="supprimer_avatar"', $page, 'Le champ d\'intention est rendu sans photo a supprimer.');
    }

    // ── L'enregistrement ─────────────────────────────────────────────────────

    public function test_on_peut_supprimer_sa_photo_de_profil(): void
    {
        $this->enregistrer(['supprimer_avatar' => '1'])->assertSessionHasNoErrors();

        $this->membre->refresh();

        $this->assertNull($this->membre->avatar, 'La photo n\'a pas ete supprimee.');
        $this->assertStringContainsString('ui-avatars.com', $this->membre->avatar_url,
            'Apres suppression, le profil doit retomber sur les initiales generees.');
    }

    /** Sans la demande, rien ne bouge : le formulaire de profil sert a tout. */
    public function test_enregistrer_son_profil_ne_supprime_pas_la_photo(): void
    {
        $this->enregistrer(['supprimer_avatar' => '0'])->assertSessionHasNoErrors();

        $this->membre->refresh();
        $this->assertSame('avatars/une-photo-deja-posee.jpg', $this->membre->avatar,
            'Un enregistrement ordinaire a efface la photo.');

        // Et meme sans le champ du tout — un autre formulaire, un vieil onglet.
        $this->enregistrer()->assertSessionHasNoErrors();

        $this->membre->refresh();
        $this->assertSame('avatars/une-photo-deja-posee.jpg', $this->membre->avatar,
            'L\'absence du champ a ete lue comme une demande de suppression.');
    }

    /**
     * Les deux gestes se contredisent : le FICHIER l'emporte.
     *
     * On est venu avec une photo en main. L'ecran les rend exclusifs, mais un
     * formulaire renvoye ne se croit pas sur parole.
     */
    public function test_une_photo_deposee_l_emporte_sur_la_demande_de_suppression(): void
    {
        $this->enregistrer([
            'avatar' => UploadedFile::fake()->image('la-nouvelle.jpg', 600, 600),
            'supprimer_avatar' => '1',
        ])->assertSessionHasNoErrors();

        $this->membre->refresh();

        $this->assertNotNull($this->membre->avatar, 'La photo deposee a ete perdue au profit de la suppression.');
        $this->assertStringContainsString('la-nouvelle.jpg', $this->membre->avatar, 'Ce n\'est pas la photo deposee qui a ete gardee.');
    }

    /**
     * `supprimer_avatar` est une INTENTION, pas une colonne — et le controleur
     * doit la retirer AVANT le `fill()`.
     *
     * ⚠️ Ma premiere version de ce test ne mesurait RIEN, et le sabotage l'a
     * montre : retirer le `unset()` du controleur la laissait VERTE. Elle
     * cherchait la cle dans `getAttributes()` apres coup — or `fill()` ecarte
     * en silence tout ce qui n'est pas `fillable`, donc la cle n'y serait
     * jamais, `unset()` ou pas.
     *
     * Le defaut ne se voit qu'en mode STRICT : `preventSilentlyDiscardingAttributes()`
     * transforme cet abandon silencieux en exception. C'est la toute la raison
     * du `unset()` — sans lui, le jour ou quelqu'un active le mode strict,
     * l'enregistrement du profil casse sans que rien n'explique pourquoi.
     *
     * Le drapeau est GLOBAL et statique : il est remis en etat dans un
     * `finally`, sinon il fuirait sur les tests suivants du meme processus.
     */
    public function test_l_intention_de_suppression_est_retiree_avant_le_fill(): void
    {
        \Illuminate\Database\Eloquent\Model::preventSilentlyDiscardingAttributes(true);

        try {
            $this->enregistrer(['supprimer_avatar' => '1'])
                ->assertSessionHasNoErrors()
                ->assertRedirect();
        } finally {
            \Illuminate\Database\Eloquent\Model::preventSilentlyDiscardingAttributes(false);
        }

        $this->membre->refresh();

        // TEMOIN : l'enregistrement a bien eu lieu sous mode strict. Sans lui,
        // une requete refusee en amont rendrait ce test vert pour rien.
        $this->assertNull($this->membre->avatar, 'La suppression n\'a pas abouti sous mode strict.');
    }

    // ── La fenetre « changer ma photo » du profil public ─────────────────────

    public function test_la_fenetre_propose_aussi_de_supprimer_la_photo(): void
    {
        $page = $this->actingAs($this->membre)
            ->get(route('profile.show', $this->membre))
            ->assertOk()
            ->getContent();

        // TEMOIN : la fenetre est bien rendue (elle n'existe que pour soi-meme).
        $this->assertStringContainsString('id="avatar-modal"', $page, 'Temoin absent : la fenetre n\'est pas rendue.');

        $this->assertStringContainsString('data-avatar-remove-modal', $page, 'La fenetre ne propose pas de supprimer la photo.');
        $this->assertStringContainsString('name="supprimer_avatar"', $page, 'Le champ d\'intention manque dans la fenetre.');
    }

    public function test_la_fenetre_ne_propose_pas_la_suppression_sans_photo(): void
    {
        $this->membre->update(['avatar' => null]);

        $page = $this->actingAs($this->membre)
            ->get(route('profile.show', $this->membre))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="avatar-modal"', $page, 'Temoin absent : la fenetre n\'est pas rendue.');
        $this->assertStringNotContainsString('data-avatar-remove-modal', $page, 'La fenetre propose de supprimer une photo inexistante.');
    }

    public function test_on_peut_supprimer_sa_photo_depuis_la_fenetre(): void
    {
        $this->actingAs($this->membre)
            ->post(route('profile.avatar'), ['supprimer_avatar' => '1'])
            ->assertSessionHasNoErrors();

        $this->membre->refresh();

        $this->assertNull($this->membre->avatar, 'La fenetre n\'a pas supprime la photo.');
        $this->assertStringContainsString('ui-avatars.com', $this->membre->avatar_url,
            'Apres suppression, le profil doit retomber sur les initiales generees.');
    }

    /**
     * La route garde son exigence quand on ne demande PAS de suppression.
     *
     * Sans ce test, assouplir `required` pour servir la suppression aurait pu
     * laisser passer un envoi vide — un no-op silencieux la ou l'ancienne
     * version signalait une erreur.
     */
    public function test_la_fenetre_refuse_toujours_un_envoi_sans_photo_ni_suppression(): void
    {
        $this->actingAs($this->membre)
            ->post(route('profile.avatar'), ['supprimer_avatar' => '0'])
            ->assertSessionHasErrors('avatar');

        $this->membre->refresh();
        $this->assertSame('avatars/une-photo-deja-posee.jpg', $this->membre->avatar, 'La photo a bouge sur un envoi refuse.');
    }

    public function test_dans_la_fenetre_aussi_la_photo_deposee_l_emporte(): void
    {
        $this->actingAs($this->membre)
            ->post(route('profile.avatar'), [
                'avatar' => UploadedFile::fake()->image('depuis-la-fenetre.jpg', 600, 600),
                'supprimer_avatar' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->membre->refresh();

        $this->assertNotNull($this->membre->avatar, 'La photo deposee a ete perdue au profit de la suppression.');
        $this->assertStringContainsString('depuis-la-fenetre.jpg', $this->membre->avatar, 'Ce n\'est pas la photo deposee qui a ete gardee.');
    }

    // ── Le crayon du profil public ───────────────────────────────────────────

    /**
     * ⚠️ Ce que ce test tient, et ce qu'il ne tient PAS.
     *
     * Il tient que `sm:self-start` est pose sur le conteneur du crayon. C'est
     * la classe qui empeche ce bloc de s'etirer sur toute la hauteur de la
     * colonne voisine — `align-items: stretch` est le defaut d'un conteneur
     * flex — et qui ramene donc le crayon, pose en `bottom-0`, au bas de la
     * PHOTO plutot qu'au bas de la carte.
     *
     * Il ne tient pas la position a l'ecran : aucun test PHP ne la mesure.
     * C'est la recette qui la voit.
     */
    public function test_le_conteneur_du_crayon_ne_s_etire_plus_sur_la_hauteur_de_la_carte(): void
    {
        $page = $this->actingAs($this->membre)
            ->get(route('profile.show', $this->membre))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-avatar-edit', $page, 'Temoin absent : le crayon n\'est pas rendu sur son propre profil.');
        $this->assertMatchesRegularExpression(
            '/<div class="relative flex-shrink-0 mx-auto sm:mx-0 sm:self-start">/',
            $page,
            'Le conteneur du crayon s\'etire encore sur toute la hauteur de la carte.'
        );
    }

    /** Le crayon reste reserve a la personne concernee. */
    public function test_le_crayon_n_est_pas_rendu_sur_le_profil_de_quelqu_un_d_autre(): void
    {
        $autre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $page = $this->actingAs($autre)
            ->get(route('profile.show', $this->membre))
            ->assertOk()
            ->getContent();

        // TASK-1796 — le temoin comparait le nom BRUT a du HTML ECHAPPE.
        //
        // Defaut PREEXISTANT, decouvert par la CI : `User::factory()->complete()`
        // ne fixe pas le nom, et Faker a produit « Alycia O'Connell ». Blade
        // rend `{{ $user->fullName }}` par `e()`, qui transforme l'apostrophe en
        // `&#039;` — mesure : `e($membre->fullName)` = « Alycia O&#039;Connell »,
        // present dans la page, alors que le nom BRUT ne l'est jamais des qu'il
        // porte une apostrophe. Confirme comme present sur `develop`, sans
        // rapport avec TASK-1796 : le fichier de test n'y est pas modifie.
        //
        // Seul le TEMOIN change — pas la garde de securite qui suit, ni la
        // logique du composant.
        $this->assertStringContainsString(e($this->membre->fullName), $page, 'Temoin absent : ce n\'est pas le bon profil.');
        $this->assertStringNotContainsString('data-avatar-edit', $page, 'Le crayon est propose sur le profil de quelqu\'un d\'autre.');
    }

    /**
     * Preuve DETERMINISTE, independante du hasard Faker.
     *
     * Le defaut ci-dessus n'apparaissait que les jours ou Faker tirait un nom
     * avec apostrophe — la raison precise pour laquelle il a dormi jusqu'a ce
     * qu'une CI le revele. Ce test fixe « O'Connell » explicitement : il
     * echoue a coup sur sur l'ancienne assertion, et reste vert avec la
     * nouvelle, sans dependre d'aucun tirage.
     */
    public function test_le_temoin_reconnait_un_nom_avec_apostrophe(): void
    {
        $this->membre->forceFill(['first_name' => 'Alycia', 'name' => "O'Connell"])->save();

        $autre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $page = $this->actingAs($autre)
            ->get(route('profile.show', $this->membre))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($this->membre->fullName, $page, 'le nom BRUT ne doit jamais apparaitre tel quel dans du HTML echappe');
        $this->assertStringContainsString(e($this->membre->fullName), $page, 'le nom ECHAPPE doit apparaitre : c\'est ce que Blade rend reellement');
        $this->assertStringNotContainsString('data-avatar-edit', $page, 'Le crayon est propose sur le profil de quelqu\'un d\'autre.');
    }
}
