<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Rules\NomDePersonneAcceptable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * TASK-1712 — un nom de personne, pas un support publicitaire.
 *
 * Le 39e compte de la PROD s'etait inscrit sous
 * `+2.84567197 BTC. GET ->>>  graph.org/...`. Aucune injection : 76 caracteres
 * ASCII, pas une balise formee, pas un guillemet, pas un motif SQL. Un leurre
 * TEXTUEL, et il est ressorti dans le sujet de son email de bienvenue — deux
 * fois.
 *
 * ## Les deux moities de ce fichier, et pourquoi aucune ne suffit
 *
 * Une regle qui refuse tout passerait les tests de refus. Une regle qui accepte
 * tout passerait les tests d'acceptation. Les deux sont donc mesurees, et le
 * volet des noms LEGITIMES est le plus important : c'est lui qui protege les
 * inscriptions reelles.
 *
 * Les quinze noms acceptes ci-dessous ne sont pas inventes au hasard — ils
 * couvrent l'apostrophe droite et typographique (lecon de TASK-1672 sur
 * « Julien O'Hara »), les traits d'union composes, l'initiale pointee, et des
 * ecritures arabe, chinoise et vietnamienne.
 */
class TASK1712PersonNameValidationTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> les motifs d'echec, vide si le nom passe */
    private function echecs(string $nom): array
    {
        $v = Validator::make(['name' => $nom], ['name' => [new NomDePersonneAcceptable]]);

        return $v->errors()->get('name');
    }

    // ── Les noms LEGITIMES passent ──────────────────────────────────────────

    #[DataProvider('nomsLegitimes')]
    public function test_un_nom_legitime_est_accepte(string $nom): void
    {
        $this->assertSame([], $this->echecs($nom), "Le nom « {$nom} » est refuse a tort.");
    }

    public static function nomsLegitimes(): array
    {
        return array_map(fn ($n) => [$n], [
            'Jean-Michel HOUSSAY',
            "Julien O'Hara",
            "Julien O\u{2019}Hara",          // apostrophe typographique
            'J. Dupont',
            'Mendrikiniaina Henri Ratoveloson', // le plus long nom reel de la PROD : 32
            'María José García-López',
            'Nguyễn Thị Hương',
            'Björn Ødegård',
            'محمد بن سلمان',
            '李 明',
            'Dupont, fils',
            'Louis II',
            "Anne-Sophie de La Tour d'Auvergne",
            "O'Brien-Smith",
            'Élodie Lefèvre',
        ]);
    }

    // ── Les payloads sont refuses ───────────────────────────────────────────

    #[DataProvider('payloads')]
    public function test_un_payload_est_refuse(string $nom, string $motif): void
    {
        $echecs = $this->echecs($nom);

        $this->assertNotSame([], $echecs, "Le nom « {$nom} » est accepte alors qu'il devrait etre refuse.");
        $this->assertStringContainsString(
            $motif,
            implode(' ', $echecs),
            "Refuse, mais pas pour la raison attendue ({$motif})."
        );
    }

    /**
     * Chaque critere est exerce SEPAREMENT.
     *
     * Le payload reel contient `->>>`, donc des chevrons : il est refuse par ce
     * seul critere, ce qui masquerait la faiblesse des autres. D'ou les
     * variantes sans chevron — une URL nue comme `graph.org/abc` n'a ni `http`
     * ni `www`, et c'est pourtant deja cliquable pour qui la recopie.
     */
    public static function payloads(): array
    {
        return [
            'le payload reel de la PROD' => ['+2.84567197 BTC. GET ->>>  graph.org/bsjTOXAD-09-01?RL4uZEfu SVWVE2194MTGJNF', 'caractère'],
            'URL nue, sans chevron' => ['2.84 BTC graph.org/abc', 'adresse web'],
            'URL sans schema ni www' => ['Gagnez sur telegra.ph/promo', 'adresse web'],
            'schema explicite' => ['Visitez https://evil.test', 'adresse web'],
            'forme www' => ['www.evil.test ici', 'adresse web'],
            'suite de chiffres' => ['Jean 123456', 'chiffres consécutifs'],
            'chiffres majoritaires' => ['1 2 3 4 5 6 7 8', 'plus de chiffres'],
            'espace sans largeur' => ["Jean\u{200B}Dupont", 'invisibles'],
            'retour a la ligne' => ["Jean\nDupont", 'invisibles'],
            'chevrons seuls' => ['Jean<script>', 'caractère'],
            'trop long' => [str_repeat('a', 101), 'trop long'],
        ];
    }

    // ── La regle est branchee la ou il faut ─────────────────────────────────

    /**
     * L'inscription refuse le payload, et dit pourquoi.
     *
     * C'est le volet qui compte : une regle non branchee ne protege rien, et
     * une garde posee sur la seule classe de regle resterait verte.
     */
    public function test_l_inscription_refuse_le_payload(): void
    {
        $organisation = Organization::factory()->create(['slug' => 'org-1712-reg', 'is_active' => true]);
        app()->instance('current_organization', $organisation);

        $reponse = $this->post('/register', [
            'name' => '+2.84567197 BTC. GET ->>>  graph.org/bsjTOXAD-09-01?RL4uZEfu',
            'first_name' => 'Kimberly',
            'email' => 'spam-1712@example.test',
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
        ]);

        $reponse->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('users', ['email' => 'spam-1712@example.test']);
    }

    /**
     * Le profil aussi : sans cela, il suffirait de s'inscrire proprement puis
     * de se renommer.
     */
    public function test_la_mise_a_jour_du_profil_refuse_le_payload(): void
    {
        $organisation = Organization::factory()->create(['slug' => 'org-1712-prof', 'is_active' => true]);
        $membre = User::factory()->create([
            'organization_id' => $organisation->id,
            'name' => 'Nom Correct',
            'email_verified_at' => now(),
        ]);

        $reponse = $this->actingAs($membre)->put('/profile', [
            'name' => 'Gagnez 2 BTC sur graph.org/promo',
            'email' => $membre->email,
        ]);

        $reponse->assertSessionHasErrors('name');
        $this->assertSame('Nom Correct', $membre->fresh()->name);
    }

    // ── Les sorties restent saines ──────────────────────────────────────────

    /**
     * Les sujets d'email n'injectent pas d'en-tete, et ne l'ont jamais fait.
     *
     * Mesure plutot qu'affirmation : Symfony encode le sujet, donc un `\r\n`
     * dans un nom ne fabrique pas d'en-tete. Le risque de ce lot etait SOCIAL,
     * pas technique — et cette garde empeche qu'une future reecriture du
     * composeur d'emails le rende technique.
     */
    public function test_un_nom_avec_crlf_ne_fabrique_pas_d_en_tete_d_email(): void
    {
        $courriel = new Email;
        $courriel->subject("Bienvenue, Jean\r\nBcc: victime@example.test");

        $this->assertSame(
            0,
            preg_match('/^(Bcc|X-[A-Za-z-]+):/mi', $courriel->getHeaders()->toString()),
            'Un nom porteur de CRLF a fabrique un en-tete d\'email.'
        );
    }
}
