<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Users\LoginAccountResolver;
use App\Services\Users\PasswordResetAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1733 (A7) — une adresse se cherche partout avec `LOWER()`, ou nulle part.
 *
 * ## Le defaut, et il etait MESURE avant d'etre corrige
 *
 * Tout le cote ECRITURE de la plateforme pose la question de l'adresse en
 * `LOWER(email)` : la regle `UniqueEmailInOrganization`, les deux index uniques
 * de T1729, le service d'invitation. Les deux resolveurs de LECTURE — celui du
 * login (T1728) et celui de la reinitialisation (T1727) — comparaient
 * exactement.
 *
 * Or une adresse PEUT porter une majuscule : `/admin/users` n'impose
 * deliberement pas de regle `lowercase` (constat T1729), et `email` est
 * sensible a la casse sous PostgreSQL. Mesure sur le banc avant correction, pour
 * un compte enregistre `Majuscule@example.test` :
 *
 *     login  candidats('majuscule@…')   = 0
 *     reset  comptesCibles('majuscule@…') = 0
 *     login HTTP minuscule -> authentifie = false
 *
 * **La personne etait enfermee dehors**, et les deux portes mentaient
 * differemment :
 *
 *  - le LOGIN rendait un refus generique (T1728, arbitrage D2 : ne jamais dire
 *    quelle adresse existe) — indiscernable d'un mot de passe oublie ;
 *  - la REINITIALISATION rendait « Aucun compte ne correspond a cette adresse
 *    e-mail. », donc une negation EXPLICITE et FAUSSE : le compte existait.
 *
 * J'avais d'abord ecrit que les deux refus etaient silencieux et generiques.
 * **La mesure dit le contraire pour le reset**, et c'est consigne ici plutot
 * que corrige en douce : `PasswordResetLinkController` rend
 * `Password::INVALID_USER` au champ quand rien n'est trouve (comportement
 * anterieur, conserve a l'identique par T1727). Au passage, c'est un oracle
 * d'enumeration PREEXISTANT sur `/forgot-password` — signale a MASTER,
 * deliberement HORS perimetre A7 : c'est un choix produit, pas un defaut de
 * casse.
 *
 * ## Ce que A7 NE fait pas
 *
 * Elle ne reecrit aucune donnee, ne normalise aucune colonne, n'ajoute aucune
 * regle `lowercase` sur les deux portes d'administration qui n'en ont pas, et
 * ne touche ni au refus pour ambiguite (T1728, arbitrage D2) ni a la clef de
 * limitation. Deux lignes, et les commentaires devenus faux.
 */
class TASK1733CaseInsensitiveAccountLookupTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'a7-alpha', 'is_active' => true, 'is_public' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'a7-beta', 'is_active' => true, 'is_public' => true]);
    }

    private const SECRET = 'un-mot-de-passe-valide-2026';

    /**
     * Un compte dont l'adresse porte une MAJUSCULE.
     *
     * Ce n'est pas un cas tordu : c'est ce que produit `/admin/users`, dont les
     * deux portes n'ont deliberement pas de regle `lowercase` (T1729).
     */
    private function compte(Organization $organization, string $email): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'email' => $email,
            'password' => Hash::make(self::SECRET),
        ]);
    }

    // ── Le resolveur de LOGIN ──────────────────────────────────────────────

    /**
     * @param  string  $stocke  la casse enregistree en base
     * @param  string  $saisi  la casse tapee par la personne
     */
    #[DataProvider('cassesEquivalentes')]
    public function test_the_login_resolver_finds_the_account_whatever_the_casing(string $stocke, string $saisi): void
    {
        $compte = $this->compte($this->orgA, $stocke);

        $trouve = app(LoginAccountResolver::class)->compteUnique($saisi, $this->orgA);

        $this->assertNotNull($trouve, "« {$saisi} » doit atteindre le compte enregistre « {$stocke} ».");
        $this->assertSame($compte->id, $trouve->id);
    }

    /** @return array<string, array{string, string}> */
    public static function cassesEquivalentes(): array
    {
        return [
            'majuscule stockee, minuscule saisie' => ['Majuscule@example.test', 'majuscule@example.test'],
            'minuscule stockee, majuscule saisie' => ['minuscule@example.test', 'MINUSCULE@example.test'],
            'casse melee des deux cotes' => ['MeLee@Example.Test', 'mELEE@eXAMPLE.tEST'],
            'identique, non-regression' => ['exact@example.test', 'exact@example.test'],
        ];
    }

    /** Le parcours HTTP reel, et c'est lui qui etait casse pour la personne. */
    public function test_a_person_logs_in_typing_their_address_in_lower_case(): void
    {
        $compte = $this->compte($this->orgA, 'Majuscule@example.test');

        $this->post('/org/a7-alpha/login', [
            'email' => 'majuscule@example.test',
            'password' => self::SECRET,
        ]);

        $this->assertAuthenticatedAs($compte->fresh());
    }

    // ── Le resolveur de REINITIALISATION ───────────────────────────────────

    public function test_the_reset_resolver_finds_the_account_whatever_the_casing(): void
    {
        $compte = $this->compte($this->orgA, 'Majuscule@example.test');

        $cibles = app(PasswordResetAccountResolver::class)
            ->comptesCibles('majuscule@example.test', $this->orgA);

        $this->assertCount(1, $cibles);
        $this->assertSame($compte->id, $cibles->first()->id);
    }

    /**
     * Le parcours HTTP, et le piege de sa mesure.
     *
     * On n'asserte pas SEULEMENT le message « un lien vous a ete envoye » : un
     * message est ce que la page dit, pas ce que l'application a fait. Avant
     * A7, cette demande rendait le refus et personne ne recevait rien.
     *
     * On mesure donc l'EFFET — la trace d'envoi et le jeton en base, tous deux
     * rattaches a l'`id` du compte. C'est la seule preuve qu'un courriel est
     * reellement parti vers la bonne personne.
     */
    public function test_a_reset_link_actually_goes_out_when_the_casing_differs(): void
    {
        $compte = $this->compte($this->orgA, 'Majuscule@example.test');

        $this->post('/org/a7-alpha/forgot-password', ['email' => 'majuscule@example.test'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $compte->id,
            'organization_id' => $this->orgA->id,
        ]);
        $this->assertDatabaseHas('password_reset_tokens', ['user_id' => $compte->id]);
    }

    /**
     * Une adresse vraiment inconnue est refusee — et A7 ne change pas ce refus.
     *
     * J'attendais ici le meme « un lien vous a ete envoye » que pour un compte
     * trouve, en croyant la porte non-enumerante. **Elle ne l'est pas** : elle
     * rend `Password::INVALID_USER` au champ `email`. C'est le comportement
     * anterieur, que T1727 a conserve a l'identique, et A7 n'y touche pas.
     *
     * Le test asserte donc ce qui EST, et il devient du meme coup le temoin qui
     * rougira si quelqu'un rend cette porte silencieuse sans le decider.
     */
    public function test_a_genuinely_unknown_address_is_refused_and_a7_does_not_change_that(): void
    {
        $this->post('/org/a7-alpha/forgot-password', ['email' => 'personne@example.test'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    // ── Les freres, et c'est le cas qui porte la valeur ───────────────────

    /**
     * Deux comptes freres de CASSES DIFFERENTES.
     *
     * Avant A7, `Alice@x` dans une org et `alice@x` dans l'autre etaient deux
     * comptes que la plateforme ne savait pas rapprocher : chaque recherche
     * n'en voyait qu'un, celui dont la casse coincidait. Le login par org
     * atteignait l'un et pas l'autre, et la reinitialisation globale n'envoyait
     * qu'un seul lien — silencieusement.
     */
    public function test_two_siblings_of_different_casings_are_both_reachable(): void
    {
        $dansA = $this->compte($this->orgA, 'Frere@example.test');
        $dansB = $this->compte($this->orgB, 'frere@example.test');

        $resolveur = app(LoginAccountResolver::class);

        // Chaque Organization atteint SON compte, quelle que soit la casse tapee.
        $this->assertSame($dansA->id, $resolveur->compteUnique('frere@example.test', $this->orgA)->id);
        $this->assertSame($dansA->id, $resolveur->compteUnique('FRERE@EXAMPLE.TEST', $this->orgA)->id);
        $this->assertSame($dansB->id, $resolveur->compteUnique('Frere@example.test', $this->orgB)->id);
    }

    /**
     * Le refus pour ambiguite s'applique MAINTENANT a la paire de casses
     * differentes — et c'est la correction, pas un effet de bord.
     *
     * Avant A7 la recherche globale n'en voyait qu'un : elle rendait UN candidat
     * et connectait ce compte-la. Deux personnes distinctes, deux tenants, et la
     * casse decidait laquelle entrait. Depuis A7 les deux sont vues, donc
     * l'adresse est ambigue, donc **refus generique sans eprouver les mots de
     * passe** (T1728, arbitrage MASTER D2 — inchange).
     */
    public function test_the_global_login_refuses_an_ambiguous_address_across_casings(): void
    {
        $this->compte($this->orgA, 'Frere@example.test');
        $this->compte($this->orgB, 'frere@example.test');

        $resolveur = app(LoginAccountResolver::class);

        $this->assertCount(2, $resolveur->candidats('frere@example.test'), 'Les deux comptes doivent etre VUS.');
        $this->assertNull($resolveur->compteUnique('frere@example.test'), 'Une adresse ambigue se refuse.');
        $this->assertNull($resolveur->compteUnique('FRERE@EXAMPLE.TEST'), 'Et la casse ne contourne pas le refus.');
    }

    /** Le refus n'eprouve AUCUN mot de passe : la session reste fermee. */
    public function test_the_ambiguous_refusal_does_not_authenticate_anyone(): void
    {
        $this->compte($this->orgA, 'Frere@example.test');
        $this->compte($this->orgB, 'frere@example.test');

        $this->post('/login', ['email' => 'frere@example.test', 'password' => self::SECRET]);

        $this->assertFalse(auth()->check(), 'Une adresse ambigue ne doit connecter personne.');
    }

    /**
     * La reinitialisation GLOBALE envoie DEUX liens, un par compte frere, et
     * chacun porte son propre token.
     *
     * C'est la forme que T1727 avait preparee — Collection et boucle, jamais
     * `first()`. A4 l'a rendue reelle ; A7 la rend insensible a la casse. Les
     * trois etapes se rejoignent ici, et c'est le meilleur endroit pour le
     * verifier.
     */
    public function test_the_global_reset_sends_one_link_per_sibling_whatever_the_casing(): void
    {
        $dansA = $this->compte($this->orgA, 'Frere@example.test');
        $dansB = $this->compte($this->orgB, 'frere@example.test');

        $this->post('/forgot-password', ['email' => 'FRERE@example.test'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('password_reset_tokens', ['user_id' => $dansA->id]);
        $this->assertDatabaseHas('password_reset_tokens', ['user_id' => $dansB->id]);
        $this->assertDatabaseCount('password_reset_tokens', 2);

        // Deux jetons DISTINCTS : un lien n'ouvre qu'un compte.
        $jetons = \DB::table('password_reset_tokens')->pluck('token');
        $this->assertCount(2, $jetons->unique(), 'Chaque compte doit porter SON token.');
    }

    /**
     * Et le jeton rend bien le compte qu'il designe, pas son frere — y compris
     * quand l'adresse de l'URL n'a pas la casse stockee.
     */
    public function test_a_token_resolves_to_its_own_account_across_casings(): void
    {
        $dansA = $this->compte($this->orgA, 'Frere@example.test');
        $dansB = $this->compte($this->orgB, 'frere@example.test');

        $resolveur = app(PasswordResetAccountResolver::class);
        $jetonDeA = Password::broker()->createToken($dansA);

        $this->assertSame(
            $dansA->id,
            $resolveur->compteDuToken('frere@example.test', $jetonDeA)?->id,
            'Le token de A doit designer A, meme interroge en minuscules.'
        );
        $this->assertNotSame($dansB->id, $resolveur->compteDuToken('frere@example.test', $jetonDeA)?->id);
    }

    // ── La portee de tenant n'est pas affaiblie ───────────────────────────

    /**
     * `LOWER()` elargit la recherche sur la CASSE, et sur rien d'autre. Un
     * compte d'une autre Organization reste invisible depuis un parcours borne
     * a un tenant — collection VIDE, indiscernable d'une adresse inconnue.
     */
    public function test_case_insensitivity_does_not_widen_the_tenant_scope(): void
    {
        $this->compte($this->orgA, 'Ailleurs@example.test');

        $login = app(LoginAccountResolver::class);
        $reset = app(PasswordResetAccountResolver::class);

        $this->assertNull($login->compteUnique('ailleurs@example.test', $this->orgB));
        $this->assertCount(0, $login->candidats('AILLEURS@example.test', $this->orgB));
        $this->assertCount(0, $reset->comptesCibles('ailleurs@example.test', $this->orgB));

        // Et aucun lien ne part depuis le mauvais tenant : le compte d'une
        // autre Organization est traite comme une adresse inconnue — meme
        // refus, mot pour mot, donc rien ne dit « cette personne existe, mais
        // ailleurs ».
        $this->post('/org/a7-beta/forgot-password', ['email' => 'ailleurs@example.test'])
            ->assertSessionHasErrors('email');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /** Un compte desactive reste refuse, la casse n'ouvre aucune derogation. */
    public function test_a_banned_account_stays_refused_whatever_the_casing(): void
    {
        $compte = $this->compte($this->orgA, 'Banni@example.test');
        $compte->forceFill(['banned_at' => now()])->save();

        $this->post('/org/a7-alpha/login', [
            'email' => 'banni@example.test',
            'password' => self::SECRET,
        ]);

        $this->assertFalse(auth()->check());
    }

    /**
     * La requete emise porte bien `LOWER(email)` des DEUX cotes.
     *
     * Mesure sur le SQL, et pas sur le resultat : c'est la forme exacte que
     * portent les index de T1729 (`users_email_lower_index`), et c'est elle qui
     * fait que ces lectures restent indexees. Une comparaison ecrite autrement
     * — `ILIKE`, ou `LOWER()` d'un seul cote — donnerait le bon resultat en
     * parcourant toute la table.
     */
    public function test_the_lookup_is_written_in_the_form_the_indexes_carry(): void
    {
        $this->compte($this->orgA, 'Forme@example.test');

        $journal = [];
        \DB::listen(function ($requete) use (&$journal): void {
            $journal[] = strtolower($requete->sql);
        });

        app(LoginAccountResolver::class)->candidats('forme@example.test', $this->orgA);
        app(PasswordResetAccountResolver::class)->comptesCibles('forme@example.test', $this->orgA);

        $surUsers = array_values(array_filter($journal, fn (string $sql): bool => str_contains($sql, 'from "users"')));

        $this->assertCount(2, $surUsers, 'Les deux resolveurs doivent avoir interroge `users`.');

        foreach ($surUsers as $sql) {
            $this->assertStringContainsString('lower(email) = ?', $sql, 'La comparaison doit porter sur LOWER(email).');
            $this->assertStringNotContainsString('ilike', $sql, 'Pas d\'ILIKE : ce n\'est pas la forme que les index portent.');
        }
    }
}
