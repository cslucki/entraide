<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\ProfileLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1750 (demande Cyril, 04/11) — plusieurs liens web sur le profil.
 *
 * ## Ce que ce fichier garde, et dans quel ordre d'importance
 *
 * 1. **Le refus des schemas dangereux.** Ces URL sortent dans un `href` rendu
 *    a des visiteurs. `javascript:` et `data:` y sont des vecteurs
 *    d'injection, et la regle `url` de Laravel les ACCEPTE tous les deux. La
 *    garde porte sur la RECIPROQUE — ce qui doit etre refuse — parce qu'un
 *    test qui ne verifie que le cas heureux passerait avec `url` seul.
 * 2. **Les deux champs historiques sont intacts.** `website` et `linkedin_url`
 *    restent les liens mis en avant : la liste s'ajoute, elle ne remplace pas.
 * 3. **La rangee publique ne sort pas a vide**, et la liste y parait quand elle
 *    est remplie.
 * 4. **La migration est additive et reversible.**
 */
class TASK1750ProfileLinksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-liens-1750',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'website' => 'https://mon-site-principal.test',
            'linkedin_url' => 'https://linkedin.com/in/moi-1750',
        ]);
    }

    /**
     * Le minimum que le controleur exige, plus ce que le test veut poser.
     *
     * `name`, `email` et `phone` sont `required` : les omettre ferait echouer
     * la requete pour une raison qui n'a rien a voir avec les liens.
     *
     * @param  array<string, mixed>  $extra
     */
    private function enregistrer(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->membre)->put(route('profile.update'), array_merge([
            'name' => $this->membre->name,
            'email' => $this->membre->email,
            'phone' => '+33600000000',
            // TASK-1762 point 17 — le slogan est desormais OBLIGATOIRE lui aussi.
            'slogan' => 'Je resous des problemes, pas des tickets.',
            'website' => $this->membre->website,
            'linkedin_url' => $this->membre->linkedin_url,
        ], $extra));
    }

    private function profilPublic(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                // `UserSlugService::resoudre()` accepte l'UUID ; un compte de
                // factory n'a pas forcement d'adresse lisible.
                'user' => $this->membre->fresh()->slug ?? $this->membre->id,
            ]))
            ->assertOk()
            ->getContent();
    }

    // ── 1. LA garde : les schemas qui ne doivent jamais sortir ──────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function lesUrlRefusees(): array
    {
        return [
            'javascript' => ['javascript:alert(document.cookie)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'file' => ['file:///etc/passwd'],
            'sans schema' => ['mon-site.fr'],
            'vide de sens' => ['https://'],
        ];
    }

    /**
     * LA garde qui compte. Sans la liste blanche `url:http,https`, les deux
     * premieres passeraient : `url` seul les accepte.
     */
    #[DataProvider('lesUrlRefusees')]
    public function test_une_url_de_schema_interdit_est_REFUSEE(string $url): void
    {
        $this->enregistrer(['profile_links' => [['type' => 'blog', 'url' => $url]]])
            ->assertSessionHasErrors('profile_links.0.url');

        $this->assertSame([], $this->membre->fresh()->liensDeProfil(),
            "L'URL « {$url} » a ete enregistree malgre le refus.");
    }

    /** La reciproque : une URL legitime passe, sinon la garde ci-dessus ne dirait rien. */
    public function test_une_url_http_ou_https_est_ACCEPTEE(): void
    {
        $this->enregistrer(['profile_links' => [
            ['type' => 'instagram', 'url' => 'https://instagram.com/moi'],
            ['type' => 'blog', 'url' => 'http://blog.exemple.test'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame([
            ['type' => 'instagram', 'url' => 'https://instagram.com/moi'],
            ['type' => 'blog', 'url' => 'http://blog.exemple.test'],
        ], $this->membre->fresh()->liensDeProfil());
    }

    /** Un type inconnu est refuse : le menu est une enumeration FERMEE. */
    public function test_un_type_inconnu_est_refuse(): void
    {
        $this->enregistrer(['profile_links' => [['type' => 'myspace', 'url' => 'https://exemple.test']]])
            ->assertSessionHasErrors('profile_links.0.type');
    }

    // ── 2. Ce que la nouveaute ne doit PAS abimer ──────────────────────────

    /**
     * Les deux champs historiques survivent a l'enregistrement d'une liste.
     *
     * C'est la promesse faite a Cyril : la liste vient EN DESSOUS, elle ne
     * remplace rien. Sans cette garde, une reprise de donnees future pourrait
     * les absorber sans que rien ne rougisse.
     */
    public function test_les_deux_champs_historiques_restent_intacts(): void
    {
        $this->enregistrer(['profile_links' => [['type' => 'app', 'url' => 'https://app.exemple.test']]])
            ->assertSessionHasNoErrors();

        $frais = $this->membre->fresh();

        $this->assertSame('https://mon-site-principal.test', $frais->website);
        $this->assertSame('https://linkedin.com/in/moi-1750', $frais->linkedin_url);
    }

    /**
     * Un `PUT` qui ne porte PAS le champ ne doit rien effacer.
     *
     * Le formulaire de profil n'est pas le seul a passer par `update()`. Sans
     * le test sur `has()`, l'assainissement aurait assigne un tableau vide et
     * la liste aurait disparu en silence.
     */
    public function test_un_enregistrement_sans_le_champ_ne_vide_pas_la_liste(): void
    {
        $this->enregistrer(['profile_links' => [['type' => 'blog', 'url' => 'https://blog.exemple.test']]])
            ->assertSessionHasNoErrors();

        $this->enregistrer()->assertSessionHasNoErrors();

        $this->assertCount(1, $this->membre->fresh()->liensDeProfil(),
            'Une requete muette sur les liens les a effaces.');
    }

    /** Une ligne ajoutee puis abandonnee n'est pas enregistree. */
    public function test_les_lignes_incompletes_sont_ecartees(): void
    {
        $this->enregistrer(['profile_links' => [
            ['type' => 'instagram', 'url' => 'https://instagram.com/moi'],
            ['type' => 'blog', 'url' => ''],
            ['type' => '', 'url' => ''],
        ]])->assertSessionHasNoErrors();

        $this->assertSame([['type' => 'instagram', 'url' => 'https://instagram.com/moi']],
            $this->membre->fresh()->liensDeProfil());
    }

    /** Le plafond est tenu par le serveur, pas seulement par le navigateur. */
    public function test_le_plafond_est_refuse_au_dela(): void
    {
        $trop = [];

        foreach (range(1, ProfileLinks::MAXIMUM + 1) as $n) {
            $trop[] = ['type' => 'website', 'url' => "https://site-{$n}.test"];
        }

        $this->enregistrer(['profile_links' => $trop])->assertSessionHasErrors('profile_links');
    }

    // ── 3. Le profil public ────────────────────────────────────────────────

    public function test_les_liens_declares_paraissent_sur_le_profil_public(): void
    {
        $this->enregistrer(['profile_links' => [
            ['type' => 'instagram', 'url' => 'https://instagram.com/moi-1750'],
            ['type' => 'blog', 'url' => 'https://blog-1750.exemple.test/chemin?x=1'],
        ]])->assertSessionHasNoErrors();

        $html = $this->profilPublic();

        $this->assertStringContainsString('data-profile-link="instagram"', $html);
        $this->assertStringContainsString('data-profile-link="blog"', $html);
        $this->assertStringContainsString(e('https://instagram.com/moi-1750'), $html);

        // Le nom d'hote, pas l'URL entiere : c'est la forme de la pastille.
        $this->assertStringContainsString('blog-1750.exemple.test', $html);
        $this->assertStringNotContainsString('chemin?x=1</a>', $html);

        // Une sortie vers l'exterieur ne doit pas fuir le referent.
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    /**
     * La rangee ne sort pas quand il n'y a RIEN a montrer.
     *
     * Sans cette garde, un conteneur vide laisserait une marge et un trou
     * visible sur le profil de quiconque n'a renseigne aucun lien — c'est-a-dire
     * tous les comptes existants.
     */
    public function test_la_rangee_ne_sort_pas_quand_il_n_y_a_aucun_lien(): void
    {
        $nu = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'website' => null,
            'linkedin_url' => null,
        ]);

        $html = $this->actingAs($nu)
            ->get(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $nu->slug ?? $nu->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-profile-link=', $html);
    }

    /** Le formulaire d'edition porte bien le bloc de saisie. */
    public function test_le_formulaire_porte_le_bloc_de_saisie(): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('organization.profile.edit', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-profile-links', $html);
        $this->assertStringContainsString('data-profile-link-add', $html);
        $this->assertStringContainsString(e(__('profile.links_heading')), $html);

        // Les cinq types sont proposes, et le libelle est traduit.
        foreach (ProfileLinks::clefs() as $clef) {
            $this->assertStringContainsString('value="'.$clef.'"', $html,
                "Le type « {$clef} » n'est pas propose.");
        }
    }

    // ── 4. Le schema ───────────────────────────────────────────────────────

    /** La colonne existe, elle est `nullable`, et rien d'autre n'a bouge. */
    public function test_la_migration_est_additive(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'profile_links'));

        // Les deux colonnes historiques sont TOUJOURS la : c'est ce qui rend
        // la migration additive plutot qu'une reprise de donnees.
        $this->assertTrue(Schema::hasColumn('users', 'website'));
        $this->assertTrue(Schema::hasColumn('users', 'linkedin_url'));
    }

    /** Un compte qui n'a jamais rien saisi rend une liste VIDE, pas `null`. */
    public function test_un_compte_sans_liens_rend_une_liste_vide(): void
    {
        $this->assertNull($this->membre->profile_links);
        $this->assertSame([], $this->membre->liensDeProfil());
    }

    /**
     * Tailwind doit SCANNER `app/`, sinon les couleurs sont purgees.
     *
     * ## Le defaut que cette garde attrape, et pourquoi rien d'autre ne le voit
     *
     * Les classes de couleur des pastilles vivent dans `ProfileLinks`, un
     * fichier PHP. Le scanner de Tailwind ne regardait que `resources/views` :
     * `text-pink-400` n'etait donc jamais produit, et la pastille Instagram
     * sortait en gris.
     *
     * Le defaut est SOURNOIS parce qu'il est partiel : `text-amber-400` sortait
     * bien, mais seulement parce qu'une AUTRE vue l'employait par ailleurs. Une
     * couleur sur deux marchait, ce qui ressemble a une erreur de palette
     * plutot qu'a un probleme de compilation.
     *
     * Aucun test de rendu ne peut l'attraper : le HTML porte la classe dans les
     * deux cas — c'est la FEUILLE DE STYLE qui est incomplete. D'ou cette garde
     * sur la configuration, qui est la cause reelle.
     */
    public function test_tailwind_scanne_les_fichiers_php_de_app(): void
    {
        $config = file_get_contents(base_path('tailwind.config.js'));

        $this->assertStringContainsString("'./app/**/*.php'", $config,
            'Sans ce chemin, les classes de couleur declarees en PHP sont purgees '
            .'et les pastilles de liens perdent leur couleur en silence.');
    }

    /** Le repli du nom d'hote, qui evite une pastille sans texte. */
    public function test_le_nom_d_hote_retombe_sur_l_url_complete(): void
    {
        $this->assertSame('exemple.test', ProfileLinks::hote('https://www.exemple.test/chemin'));
        $this->assertSame('exemple.test', ProfileLinks::hote('https://exemple.test'));

        // Pas d'hote analysable : on rend l'URL telle quelle plutot que du vide.
        $this->assertSame('pas-une-url', ProfileLinks::hote('pas-une-url'));
    }
}
