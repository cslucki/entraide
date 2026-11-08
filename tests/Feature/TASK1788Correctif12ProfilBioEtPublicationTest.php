<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1788 correctif 12 — profil public (`/org/{organization}/profile/{slug}`).
 *
 * Trois demandes de Cyril :
 *
 * 1. la limite de la presentation passe de 2000 a 3500 caracteres — couverte
 *    dans `TASK1762FormulaireProfilReorganiseTest` (le test existant, mis a
 *    jour sur place plutot que duplique) ;
 * 2. un bouton « Publier dans le blog » a cote de « Modifier mon profil » ;
 * 3. un lien YouTube « nu » depose dans la bio devient un lecteur integre au
 *    rendu public — voir le docbloc de `App\Support\Markdown\YoutubeEmbedExtension`
 *    pour pourquoi cette reconnaissance vit cote serveur et non dans un
 *    second noeud TipTap.
 *
 * La troisieme demande (couleur des boutons de la barre en darkmode, et les
 * boutons qui disparaissent au plein ecran) n'a pas d'equivalent testable en
 * HTTP : c'est une question de COULEUR CALCULEE, invisible a une assertion de
 * chaine. Elle a sa propre recette navigateur, consignee dans la taskfile.
 */
class TASK1788Correctif12ProfilBioEtPublicationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788c12',
            'is_active' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    private function pageDuProprietaire(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $this->membre->profileRouteKey(),
            ]))
            ->assertOk()
            ->getContent();
    }

    // ── Le bouton « Publier dans le blog » ──────────────────────────────────

    public function test_le_proprietaire_voit_le_bouton_publier_dans_le_blog(): void
    {
        $html = $this->pageDuProprietaire();

        preg_match('#<a[^>]*data-profile-publish-blog.*?</a>#s', $html, $trouve);
        $this->assertNotEmpty($trouve, 'Le bouton « Publier dans le blog » n\'est pas rendu.');

        $balise = $trouve[0];
        $this->assertStringContainsString(
            e(route('organization.blog.create', ['organization' => $this->organization->slug])),
            $balise,
            'Le bouton ne vise pas la redaction de blog DE CETTE Organization — '.
            'un lien sans prefixe resoudrait l\'Organization par defaut de la plateforme.'
        );
        $this->assertStringContainsString(__('profile.publish_to_blog'), $balise);
    }

    /** Un visiteur, meme connecte, n'edite pas la fiche d'un autre : pas de bouton. */
    public function test_un_visiteur_ne_voit_pas_le_bouton_publier(): void
    {
        $visiteur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $html = $this->actingAs($visiteur)
            ->get(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $this->membre->profileRouteKey(),
            ]))
            ->getContent();

        $this->assertStringNotContainsString('data-profile-publish-blog', $html);
    }

    // ── La video YouTube integree depuis la bio ─────────────────────────────

    private function enregistrerBio(string $bio): void
    {
        $this->actingAs($this->membre)->put(route('profile.update'), [
            'first_name' => $this->membre->first_name,
            'name' => $this->membre->name,
            'email' => $this->membre->email,
            'phone' => $this->membre->phone,
            'slogan' => 'Mon slogan de test.',
            'bio' => $bio,
        ])->assertSessionHasNoErrors();
    }

    public function test_un_lien_youtube_nu_devient_un_lecteur_integre(): void
    {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $this->enregistrerBio("Une presentation.\n\n[{$url}]({$url})");

        $html = $this->pageDuProprietaire();
        $bio = substr($html, strpos($html, 'data-profile-bio'));

        $this->assertStringContainsString('data-media-embed', $bio, 'La video n\'a pas ete integree.');
        $this->assertStringContainsString(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            $bio,
            'La source de l\'iframe ne vient pas de l\'identifiant valide.'
        );
        // ⚠️ L'URL fournie par le membre ne doit JAMAIS apparaitre telle
        // quelle dans l'iframe — seul l'identifiant, revalide cote serveur,
        // construit la source.
        $this->assertStringNotContainsString($url, $bio);
    }

    /**
     * ⚠️ Un lien YouTube mentionne dans une PHRASE reste un lien bleu
     * ordinaire : seule l'URL deposee SEULE sur sa ligne (ce que fait le
     * bouton de la barre d'outils) devient un lecteur. Sans cette
     * discrimination, toute bio qui cite une video en passant perdrait son
     * texte.
     */
    public function test_un_lien_youtube_dans_une_phrase_reste_un_lien(): void
    {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $this->enregistrerBio("Regarde [ma video]({$url}) ici.");

        $html = $this->pageDuProprietaire();
        $bio = substr($html, strpos($html, 'data-profile-bio'));

        $this->assertStringNotContainsString('data-media-embed', $bio);
        $this->assertStringContainsString('ma video', $bio);
    }

    public function test_une_url_qui_ne_termine_pas_par_un_identifiant_valide_n_est_pas_integree(): void
    {
        $url = 'https://www.youtube.com/watch?v=short';
        $this->enregistrerBio("[{$url}]({$url})");

        $html = $this->pageDuProprietaire();
        $bio = substr($html, strpos($html, 'data-profile-bio'));

        $this->assertStringNotContainsString('data-media-embed', $bio);
    }
}
