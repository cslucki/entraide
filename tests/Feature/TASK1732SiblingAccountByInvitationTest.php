<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\OrganizationInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1732 (A6) — rejoindre une Organization sur invitation, meme quand
 * l'adresse porte deja un compte ailleurs.
 *
 * ## Ce que A6 ouvre, et pourquoi c'etait la DERNIERE porte
 *
 * A4 (T1729) avait deja ouvert cinq portes de creation de compte au compte
 * frere, toutes par la meme regle `UniqueEmailInOrganization` : inscription
 * web, inscription API, profil, creation et modification depuis
 * `/admin/users`. L'invitation etait la sixieme, et la seule encore fermee —
 * non par le schema, qui l'autorisait depuis A4, mais par une DECISION que le
 * code nommait « Cas C » et documentait comme « en attente de A6 ».
 *
 * A6 la leve. Ce qu'elle N'ouvre pas, et ces tests le prouvent autant que le
 * reste : aucun acces au compte existant, aucune liaison A5b automatique,
 * aucune information sur les comptes des autres Organizations.
 *
 * ## Les trois pieges que ces tests existent pour attraper
 *
 * 1. **La session.** `Auth::login()` etait appele sans aucune regeneration.
 *    Inoffensif tant que seul un visiteur anonyme pouvait arriver la ; depuis
 *    A6 la personne qui clique peut etre connectee sur son compte d'un AUTRE
 *    tenant, et heriterait de sa session. `regenerate()` n'aurait pas suffi :
 *    il CONSERVE le contenu (lecon T1730).
 * 2. **L'orphelin.** La paire « compte sans Organization + membre » n'est
 *    exprimable par aucun index unique. En ouvrant cette porte on en fait le
 *    sixieme site a devoir la tenir — sous verrou, sinon une suppression
 *    d'Organization concurrente la rompt.
 * 3. **La fuite.** L'ancien refus ANNONCAIT a l'administrateur de l'org A que
 *    l'adresse avait un compte ailleurs. A6 devait supprimer cette phrase, pas
 *    la deplacer.
 */
class TASK1732SiblingAccountByInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        // orgA : la ou l'adresse a DEJA un compte. orgB : celle qui invite.
        // Nom DISTINCTIF a dessein : les noms de fabrique sont des mots
        // aleatoires, et l'un d'eux s'est trouve present dans le gabarit de la
        // page d'administration — l'assertion de non-fuite rougissait pour une
        // coincidence. Un nom qui ne peut apparaitre que s'il a fui.
        $this->orgA = Organization::factory()->create([
            'slug' => 'a6-deja', 'name' => 'Organisation Temoin A6 Ailleurs',
            'is_active' => true, 'is_public' => true,
        ]);
        $this->orgB = Organization::factory()->create(['slug' => 'a6-invite', 'is_active' => true, 'is_public' => true]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** Le compte qui existe deja, dans orgA, avec SON mot de passe. */
    private function compteExistant(string $email = 'frere@example.test'): User
    {
        return User::factory()->create([
            'organization_id' => $this->orgA->id,
            'email' => $email,
            'name' => 'Nom Dans Org A',
            'first_name' => 'PrenomOrgA',
            'password' => Hash::make('le-mot-de-passe-d-org-a'),
        ]);
    }

    private function invitationDansOrgB(string $email = 'frere@example.test', array $overrides = []): OrganizationInvitation
    {
        return OrganizationInvitation::factory()->create(array_merge([
            'organization_id' => $this->orgB->id,
            'recipient_email' => $email,
            'recipient_first_name' => 'PrenomInvite',
            'recipient_name' => 'NomInvite',
        ], $overrides));
    }

    private function frereDansOrgB(string $email = 'frere@example.test'): ?User
    {
        return User::query()
            ->where('organization_id', $this->orgB->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
    }

    // ── critere 1 : l'invitation PART ──────────────────────────────────────

    /**
     * Le coeur d'A6, mesure sur le service et non sur l'ecran : le cas que le
     * code nommait « Cas C » rend desormais `CASE_CREATED`.
     */
    public function test_inviting_an_address_held_in_another_organization_creates_the_invitation(): void
    {
        $existant = $this->compteExistant();

        $resultat = app(OrganizationInvitationService::class)->invite(
            $this->orgB, $this->superAdmin(), 'frere@example.test', 'PrenomInvite', 'NomInvite'
        );

        $this->assertSame(OrganizationInvitationService::CASE_CREATED, $resultat['case']);
        $this->assertNotNull($resultat['invitation']);
        $this->assertSame($this->orgB->id, $resultat['invitation']->organization_id);
        // Le compte de l'autre tenant n'est ni rendu, ni touche (T1726).
        $this->assertNull($resultat['user'], 'Le service ne doit pas rendre le compte d\'un autre tenant.');
        $this->assertSame($this->orgA->id, $existant->fresh()->organization_id);
    }

    /** Et l'inverse reste vrai : deja membre ICI, on n'invite pas deux fois. */
    public function test_an_address_already_member_of_the_inviting_organization_is_still_refused(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->orgB->id, 'email' => 'frere@example.test']);

        $resultat = app(OrganizationInvitationService::class)->invite(
            $this->orgB, $this->superAdmin(), 'frere@example.test', 'P', 'N'
        );

        $this->assertSame(OrganizationInvitationService::CASE_ALREADY_MEMBER, $resultat['case']);
        $this->assertSame($membre->id, $resultat['user']->id);
        $this->assertDatabaseMissing('organization_invitations', ['recipient_email' => 'frere@example.test']);
    }

    // ── critere 2 : le clic cree un compte FRERE ───────────────────────────

    public function test_the_click_creates_a_sibling_account_in_the_inviting_organization(): void
    {
        $existant = $this->compteExistant();
        $invitation = $this->invitationDansOrgB();

        $this->post(route('organization-invitations.accept', $invitation->token))->assertRedirect();

        $frere = $this->frereDansOrgB();
        $this->assertNotNull($frere, 'Le compte frere doit naitre dans l\'Organization invitante.');
        $this->assertNotSame($existant->id, $frere->id, 'C\'est un AUTRE compte, pas celui d\'orgA.');
        $this->assertSame($this->orgB->id, $frere->organization_id);
        // Et l'invitation est consommee par le compte qu'elle vient de creer.
        $this->assertSame(OrganizationInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertSame($frere->id, $invitation->fresh()->accepted_by_user_id);
    }

    /**
     * Les trois drapeaux, et le bonus — tous poses sur le FRERE, dans orgB.
     *
     * `email_verified_at` : le jeton est parti a cette adresse exacte, le clic
     * prouve la possession. `must_set_password` : le compte nait avec un secret
     * aleatoire. `approved_at` : une invitation EST une approbation (T1714).
     */
    public function test_the_sibling_is_verified_owes_a_password_and_is_approved(): void
    {
        $this->compteExistant();
        $invitation = $this->invitationDansOrgB();

        $this->post(route('organization-invitations.accept', $invitation->token));

        $frere = $this->frereDansOrgB();
        $this->assertNotNull($frere->email_verified_at);
        $this->assertTrue((bool) $frere->must_set_password);
        $this->assertNotNull($frere->approved_at);
    }

    /** Arbitrage MASTER D : le bonus est maintenu, et il est LOCAL a orgB. */
    public function test_the_welcome_bonus_lands_in_the_inviting_organization_only(): void
    {
        $existant = $this->compteExistant();
        $soldeInitial = $existant->points_balance;
        $invitation = $this->invitationDansOrgB();

        $this->post(route('organization-invitations.accept', $invitation->token));

        $frere = $this->frereDansOrgB();
        $this->assertSame(100, (int) $frere->points_balance);
        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $frere->id,
            'organization_id' => $this->orgB->id,
            'reason' => 'welcome_bonus',
            'delta' => 100,
        ]);
        // Rien n'est credite ni debite sur le compte de l'autre tenant.
        $this->assertSame($soldeInitial, $existant->fresh()->points_balance);
        $this->assertDatabaseMissing('point_ledger', ['user_id' => $existant->id, 'reason' => 'welcome_bonus']);
    }

    // ── critere 3 : le frere n'HERITE de rien ──────────────────────────────

    /**
     * Exigence MASTER 3 vue de l'interieur : non seulement on ne dit rien du
     * compte d'orgA, mais on n'en RECOPIE rien. Le frere est bati sur
     * l'invitation — c'est-a-dire sur ce que l'administrateur d'orgB a saisi —
     * et sur rien d'autre.
     */
    public function test_the_sibling_inherits_nothing_from_the_account_in_the_other_organization(): void
    {
        $existant = $this->compteExistant();
        $existant->forceFill(['phone' => '+33600000000', 'country_code' => 'FR'])->save();
        $invitation = $this->invitationDansOrgB();

        $this->post(route('organization-invitations.accept', $invitation->token));

        $frere = $this->frereDansOrgB();
        $this->assertSame('PrenomInvite', $frere->first_name, 'Le prenom vient de l\'invitation.');
        $this->assertStringContainsString('NomInvite', (string) $frere->name);
        $this->assertStringNotContainsString('OrgA', (string) $frere->name);
        $this->assertStringNotContainsString('OrgA', (string) $frere->first_name);
        $this->assertNull($frere->phone, 'Aucune donnee de profil ne traverse la frontiere de tenant.');
        $this->assertNull($frere->country_code);

        // Les deux secrets sont independants : c'est ce qui rend A5b possible,
        // et c'est ce qui empeche l'un des comptes d'ouvrir l'autre.
        $this->assertFalse(
            Hash::check('le-mot-de-passe-d-org-a', $frere->password),
            'Le frere ne doit jamais partager le secret du compte d\'orgA.'
        );
        $this->assertTrue(
            Hash::check('le-mot-de-passe-d-org-a', $existant->fresh()->password),
            'Et le secret d\'orgA reste celui d\'orgA.'
        );
    }

    // ── critere 5 : la Boucle cible ────────────────────────────────────────

    /** Loop != Tenant : l'adhesion se pose dans orgB, exactement comme avant A6. */
    public function test_the_sibling_joins_the_target_loop_of_the_inviting_organization(): void
    {
        $this->compteExistant();
        $loop = Loop::factory()->create([
            'organization_id' => $this->orgB->id,
            'status' => 'active',
            'visibility' => 'private',
        ]);
        $invitation = $this->invitationDansOrgB('frere@example.test', ['loop_id' => $loop->id]);

        $this->post(route('organization-invitations.accept', $invitation->token));

        $frere = $this->frereDansOrgB();
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $frere->id,
            'organization_id' => $this->orgB->id,
            'status' => 'active',
        ]);
    }

    // ── critere 6 : le jeton n'ouvre JAMAIS le compte d'ailleurs ───────────

    /**
     * Le defaut que ce test rend impossible : un jeton envoye par courriel qui
     * authentifierait le compte d'orgA. Ce serait le pire resultat d'A6 —
     * transformer une invitation en prise de controle inter-Organizations.
     */
    public function test_a_token_never_opens_the_session_of_the_account_in_the_other_organization(): void
    {
        $existant = $this->compteExistant();
        $invitation = $this->invitationDansOrgB();

        $this->post(route('organization-invitations.accept', $invitation->token));

        $frere = $this->frereDansOrgB();
        $this->assertAuthenticatedAs($frere);
        $this->assertNotSame($existant->id, auth()->id(), 'La session doit porter le FRERE, jamais le compte d\'orgA.');
        $this->assertSame($this->orgB->id, auth()->user()->organization_id);
    }

    // ── critere 7 : aucune liaison A5b automatique ─────────────────────────

    /**
     * Arbitrage MASTER C. La liaison exige la preuve de controle des DEUX
     * comptes (T1731) ; la deduire d'une egalite d'adresse serait exactement
     * l'erreur que A5b a ete ecrite pour eviter.
     */
    public function test_no_account_link_is_created_by_the_invitation_path(): void
    {
        $this->compteExistant();
        $invitation = $this->invitationDansOrgB();

        $this->post(route('organization-invitations.accept', $invitation->token));

        $this->assertSame(0, DB::table('account_links')->count(), 'A6 ne cree aucun lien A5b.');
    }

    // ── critere 8 : aucune fuite, nulle part ──────────────────────────────

    /**
     * Exigence MASTER 3, parcours invite. La page d'atterrissage doit etre
     * IDENTIQUE octet pour octet, que l'adresse existe ailleurs ou non
     * (arbitrage MASTER E) : toute difference serait un oracle d'enumeration
     * utilisable par quiconque detient un lien.
     */
    public function test_the_landing_page_is_identical_whether_the_address_exists_elsewhere_or_not(): void
    {
        $invitationSeule = $this->invitationDansOrgB('inconnue@example.test');
        $invitationFrere = $this->invitationDansOrgB('frere@example.test');
        $this->compteExistant();

        $sansCompteAilleurs = $this->get(route('organization-invitations.show', $invitationSeule->token));
        $avecCompteAilleurs = $this->get(route('organization-invitations.show', $invitationFrere->token));

        $sansCompteAilleurs->assertOk();
        $avecCompteAilleurs->assertOk();

        // On compare les pages a l'adresse et au jeton pres — tout le reste
        // doit coincider. Comparer les corps bruts aurait toujours differe.
        $normaliser = fn (string $html, OrganizationInvitation $i): string => str_replace(
            [$i->token, $i->recipient_email, 'inconnue', 'frere'], 'X', $html
        );

        $this->assertSame(
            $normaliser($sansCompteAilleurs->getContent(), $invitationSeule),
            $normaliser($avecCompteAilleurs->getContent(), $invitationFrere),
            'La page ne doit porter AUCUNE trace de l\'existence d\'un compte ailleurs.'
        );
    }

    /**
     * Exigence MASTER 3, interface administrateur. L'ancien message disait
     * « cette adresse possede deja un compte BouclePro associe a une autre
     * organisation » : c'etait une fuite inter-Organizations, servie a l'ecran.
     */
    public function test_the_admin_report_never_mentions_another_organization(): void
    {
        $this->compteExistant();

        $reponse = $this->actingAs($this->superAdmin())
            ->post(route('admin.users.bulk-create.invitations.store'), [
                'organization_id' => $this->orgB->id,
                'people' => [['first_name' => 'P', 'last_name' => 'N', 'email' => 'frere@example.test']],
            ])->assertRedirect();

        $resultat = session('bulkCreateResult');
        $this->assertSame(1, $resultat['created'], 'L\'invitation part : c\'est le cas nominal d\'A6.');
        $this->assertSame([], $resultat['notInvitable']);

        // La PHRASE qui fuyait a disparu de l'ecran.
        //
        // J'avais d'abord asserte que le nom d'orgA n'y figurait pas du tout.
        // C'est faux, et la mesure l'a montre : la page porte un `<select>` de
        // TOUTES les Organizations — c'est le champ par lequel le SuperAdmin
        // choisit sa cible (T1659). Un SuperAdmin voit toutes les
        // Organizations par conception ; la fuite d'A6 n'etait pas la, elle
        // etait dans la phrase qui RELIAIT une adresse a un autre tenant.
        // C'est elle qu'on mesure.
        $suivi = $this->actingAs($this->superAdmin())->get(route('admin.users.bulk-create'));
        $suivi->assertDontSee('une autre organisation');
        $suivi->assertDontSee('associé à une autre', false);
        $suivi->assertDontSee('multi-Organization n\'est pas encore');
    }

    // ── critere 9 : isolation du compte frere ─────────────────────────────

    /**
     * Le frere est un membre ordinaire d'orgB, et rien de plus. Mesure sur la
     * frontiere HTTP inter-Organizations (T1692).
     *
     * ## Ce qui est mesure, et ce qui ne pouvait pas l'etre
     *
     * J'avais d'abord asserte `assertNotFound()`, puis l'absence du NOM d'orgA
     * sur la reponse. Les deux etaient faux, et la mesure l'a montre : la route
     * rend 403, par un ecran dedie qui dit « cette page est reservee aux
     * membres de <nom> ». Cet ecran est anterieur a A6 et il est deliberé — le
     * visiteur a tape le slug lui-meme, le nom ne lui apprend rien que la page
     * publique de l'Organization ne donne deja.
     *
     * Ce qui compte pour A6 n'est donc pas le code de statut ni le nom affiche,
     * c'est que les DONNEES d'orgA n'arrivent pas. On en met une en place — une
     * Boucle au titre reconnaissable — et on verifie qu'elle reste invisible.
     */
    public function test_the_sibling_sees_nothing_of_the_other_organizations_data(): void
    {
        $this->compteExistant();
        // Une donnee d'orgA, reconnaissable, que le frere ne doit jamais voir.
        Loop::factory()->create([
            'organization_id' => $this->orgA->id,
            'status' => 'active',
            'visibility' => 'public',
            'name' => 'Boucle Secrete Org A',
        ]);
        $invitation = $this->invitationDansOrgB();
        $this->post(route('organization-invitations.accept', $invitation->token));

        $frere = $this->frereDansOrgB();
        // Le mot de passe pose : sans cela `must_set_password` deroute tout.
        $frere->forceFill(['must_set_password' => false])->save();
        $frere = $frere->fresh();

        foreach (['/dashboard', '/loops'] as $chemin) {
            $reponse = $this->actingAs($frere)->get('/org/'.$this->orgA->slug.$chemin);

            $this->assertContains(
                $reponse->getStatusCode(), [403, 404],
                "L'acces a {$chemin} de l'autre tenant doit etre refuse."
            );
            $reponse->assertDontSee('Boucle Secrete Org A');
        }

        // Et son propre tenant, lui, repond.
        $this->actingAs($frere)->get('/org/'.$this->orgB->slug.'/dashboard')->assertOk();
    }

    // ── critere 10 : l'orphelin, aux DEUX temps ───────────────────────────

    /**
     * Pourquoi un orphelin bloque alors qu'un membre d'orgA ne bloque plus.
     *
     * Ce n'est pas une symetrie oubliee. Un compte sans Organization n'a qu'UNE
     * porte — le login GLOBAL — et T1728 la ferme sur une adresse ambigue.
     * Creer le frere rendrait donc cet orphelin definitivement MUET : ni
     * connexion, ni demande de reinitialisation. L'asymetrie est documentee
     * dans `UniqueEmailInOrganization`, et A6 en fait le sixieme site a la
     * tenir.
     */
    public function test_an_orphan_homonym_refuses_the_invitation_at_send_time(): void
    {
        User::factory()->create(['organization_id' => null, 'email' => 'frere@example.test']);

        $resultat = app(OrganizationInvitationService::class)->invite(
            $this->orgB, $this->superAdmin(), 'frere@example.test', 'P', 'N'
        );

        $this->assertSame(OrganizationInvitationService::CASE_EMAIL_NOT_INVITABLE, $resultat['case']);
        $this->assertNull($resultat['invitation']);
        // Arbitrage MASTER B : le service ne rend meme pas le compte en cause.
        $this->assertNull($resultat['user'], 'Rendre l\'orphelin reviendrait a divulguer son existence.');
        $this->assertDatabaseMissing('organization_invitations', ['recipient_email' => 'frere@example.test']);
    }

    /**
     * Exigence MASTER 2 — LE JETON N'EST PAS CONSOMME.
     *
     * L'orphelin peut apparaitre APRES l'envoi : la suppression d'une
     * Organization detache ses membres. Le controle du clic est donc le seul
     * qui compte. Et le refus doit laisser l'invitation CLIQUABLE : la personne
     * n'a rien fait de mal, son lien ne doit pas etre brule par un etat de la
     * plateforme qui ne la concerne pas.
     */
    public function test_an_orphan_appearing_after_the_send_refuses_the_click_without_consuming_the_token(): void
    {
        $invitation = $this->invitationDansOrgB();
        // L'orphelin nait entre l'envoi et le clic.
        User::factory()->create(['organization_id' => null, 'email' => 'frere@example.test']);

        $this->post(route('organization-invitations.accept', $invitation->token))->assertRedirect();

        $this->assertNull($this->frereDansOrgB(), 'Aucun compte ne doit naitre.');
        $this->assertGuest();
        $fraiche = $invitation->fresh();
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $fraiche->status, 'Le jeton reste consommable.');
        $this->assertNull($fraiche->accepted_at);
        $this->assertNull($fraiche->accepted_by_user_id);
        $this->assertDatabaseMissing('point_ledger', ['reason' => 'welcome_bonus', 'organization_id' => $this->orgB->id]);
    }

    /** Et le meme lien redevient utilisable des que l'orphelin disparait. */
    public function test_the_same_link_works_again_once_the_orphan_is_gone(): void
    {
        $invitation = $this->invitationDansOrgB();
        $orphelin = User::factory()->create(['organization_id' => null, 'email' => 'frere@example.test']);
        $this->post(route('organization-invitations.accept', $invitation->token));
        $this->assertNull($this->frereDansOrgB());

        $orphelin->forceFill(['organization_id' => $this->orgA->id])->save();

        $this->post(route('organization-invitations.accept', $invitation->token));
        $this->assertNotNull($this->frereDansOrgB(), 'Le lien n\'avait pas ete brule.');
    }

    /** Arbitrage MASTER B : le refus ne dit pas POURQUOI. */
    public function test_the_refusal_message_says_nothing_about_the_cause(): void
    {
        $invitation = $this->invitationDansOrgB();
        User::factory()->create(['organization_id' => null, 'email' => 'frere@example.test']);

        $this->post(route('organization-invitations.accept', $invitation->token));

        $message = (string) session('error');
        $this->assertNotSame('', $message, 'Un refus muet laisserait la personne sans explication du tout.');
        foreach (['organization', 'Organization', 'compte', 'autre', 'adresse', 'email'] as $indice) {
            $this->assertStringNotContainsString(
                $indice, $message,
                "Le message ne doit rien divulguer : « {$indice} » y apparait."
            );
        }
    }

    /**
     * Exigence MASTER 2 — le verrou est pris AVANT le controle, et c'est
     * l'ordre qui fait la garantie.
     *
     * Sans lui, une suppression d'Organization concurrente — qui DETACHE ses
     * membres, donc fabrique des orphelins — pouvait commiter entre notre
     * controle et notre ecriture. Sa propre garde ne voyait pas notre ligne
     * encore non commitee, et les deux cotes se croyaient saufs. On mesure
     * l'ordre des instructions emises, pas l'intention du code.
     */
    public function test_the_creation_takes_the_user_write_lock_before_looking_for_an_orphan(): void
    {
        $invitation = $this->invitationDansOrgB();

        $journal = [];
        DB::listen(function ($requete) use (&$journal): void {
            $journal[] = strtolower($requete->sql);
        });

        $this->post(route('organization-invitations.accept', $invitation->token));

        $estLeVerrou = fn (string $sql): bool => str_contains($sql, 'lock table "users"')
            || str_contains($sql, 't1729_user_write_lock');
        $chercheLOrphelin = fn (string $sql): bool => str_contains($sql, 'from "users"')
            && str_contains($sql, 'organization_id" is null')
            && str_contains($sql, 'lower(email)');
        $creeLeCompte = fn (string $sql): bool => str_starts_with($sql, 'insert into "users"');

        $iVerrou = $this->premierIndice($journal, $estLeVerrou);
        $iOrphelin = $this->premierIndice($journal, $chercheLOrphelin);
        $iCreation = $this->premierIndice($journal, $creeLeCompte);

        $this->assertNotNull($iVerrou, 'Le verrou doit etre pris : aucune instruction ne le montre.');
        $this->assertNotNull($iOrphelin, 'Le controle de l\'orphelin doit etre emis.');
        $this->assertNotNull($iCreation, 'Le compte doit etre cree.');
        $this->assertLessThan($iOrphelin, $iVerrou, 'Le verrou doit preceder le controle.');
        $this->assertLessThan($iCreation, $iOrphelin, 'Le controle doit preceder l\'ecriture.');
    }

    /** @param  callable(string): bool  $predicat */
    private function premierIndice(array $journal, callable $predicat): ?int
    {
        foreach ($journal as $i => $sql) {
            if ($predicat($sql)) {
                return $i;
            }
        }

        return null;
    }

    // ── Exigence MASTER 1 : la transition de session ──────────────────────

    /**
     * Arbitrage MASTER A : une personne connectee sur son compte d'orgA PEUT
     * accepter l'invitation d'orgB. Ce qui doit disparaitre, c'est sa session.
     *
     * Les deux clefs mesurees ne sont pas prises au hasard, ce sont celles qui
     * NUISENT :
     *
     * - `auth.password_confirmed_at` : une confirmation de mot de passe faite
     *   sur le compte d'orgA deverrouillerait, sur le frere, les ecrans qui
     *   exigent de reconfirmer son mot de passe ;
     * - `url.intended` : une destination visee dans orgA, vers laquelle
     *   `redirect()->intended()` enverrait le frere.
     *
     * `regenerate()` n'aurait rien corrige : il change l'identifiant de session
     * et CONSERVE le contenu (lecon payee en T1730). C'est `invalidate()` qu'il
     * faut quand l'identite change.
     */
    public function test_accepting_while_logged_into_the_other_account_leaves_nothing_of_its_session(): void
    {
        $existant = $this->compteExistant();
        $invitation = $this->invitationDansOrgB();

        $reponse = $this->actingAs($existant)
            ->withSession([
                'auth.password_confirmed_at' => now()->timestamp,
                'url.intended' => '/org/'.$this->orgA->slug.'/dashboard',
            ])
            ->post(route('organization-invitations.accept', $invitation->token));

        $frere = $this->frereDansOrgB();
        $this->assertNotNull($frere);
        $this->assertAuthenticatedAs($frere);

        $this->assertNull(session('auth.password_confirmed_at'), 'La confirmation de mot de passe d\'orgA ne doit pas survivre.');
        $this->assertNull(session('url.intended'), 'La destination visee dans orgA ne doit pas survivre.');

        // Et la consequence concrete : on n'atterrit pas dans orgA.
        $reponse->assertRedirect(route('invitation.password.create'));
    }

    /**
     * Le jeton de session change — c'est ce qui distingue une vraie transition
     * d'identite d'un simple `Auth::login()` pose sur la session en cours.
     */
    public function test_the_session_identifier_changes_when_the_sibling_is_created(): void
    {
        $invitation = $this->invitationDansOrgB();

        $this->get(route('organization-invitations.show', $invitation->token));
        $avant = session()->getId();

        $this->post(route('organization-invitations.accept', $invitation->token));

        $this->assertNotSame($avant, session()->getId(), 'La session doit etre regeneree a la creation du compte.');
    }

    // ── Non-regression : le meme jeton, deux fois ────────────────────────

    /**
     * Le garde-fou historique, re-mesure dans la configuration d'A6 : un jeton
     * deja consomme n'authentifie plus personne. A6 cree un compte de plus, pas
     * un mot de passe permanent de plus.
     */
    public function test_a_consumed_token_no_longer_authenticates_even_with_a_sibling_elsewhere(): void
    {
        $this->compteExistant();
        $invitation = $this->invitationDansOrgB();

        $this->post(route('organization-invitations.accept', $invitation->token));
        $frere = $this->frereDansOrgB();
        auth()->logout();

        $this->post(route('organization-invitations.accept', $invitation->token));

        // `assertGuest()` attend un nom de GUARD, pas un message : on verifie
        // l'absence de session explicitement (meme piege qu'en T1659).
        $this->assertFalse(auth()->check(), 'Un jeton consomme ne doit jamais rouvrir la session du frere.');
        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['frere@example.test'])
            ->where('organization_id', $this->orgB->id)->count(), 'Ni creer un second compte.');
        $this->assertSame($frere->id, $invitation->fresh()->accepted_by_user_id);
    }
}
