<?php

namespace Tests\Feature;

use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use App\Support\Notifications\NotificationInvariants;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1372 — le socle des notifications IN_APP.
 *
 * Ces tests portent sur les proprietes qui font qu'un module de notifications ne
 * devient pas une fuite de donnees :
 *
 * 1. **Le catalogue fait autorite** — une cle non declaree n'existe pas.
 * 2. **La frontiere de tenant tient sur la DONNEE**, pas sur le contexte, et
 *    **quelle que soit la porte d'ecriture**.
 * 3. **Le destinataire est proprietaire** — en lecture comme en mutation.
 * 4. **Le stockage ne retient AUCUN contenu** — colonnes enumerees exactement.
 * 5. **L'idempotence ne rend jamais une ligne differente en silence.**
 */
class TASK1372NotificationCoreTest extends TestCase
{
    use RefreshDatabase;

    private const OBJET = 'loop_invitation';

    private Organization $orgA;

    private Organization $orgB;

    private User $alice;

    private User $bob;

    private User $carol;

    private NotificationEmitter $emetteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'T1372 Organization A']);
        $this->orgB = Organization::factory()->create(['name' => 'T1372 Organization B']);

        $this->alice = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->bob = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->carol = User::factory()->create(['organization_id' => $this->orgB->id]);

        $this->emetteur = new NotificationEmitter;
    }

    // =====================================================================
    // 1. Le catalogue fait autorite
    // =====================================================================

    /** Une cle que le catalogue ne declare pas ne peut rien ecrire. */
    public function test_an_undeclared_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not declared in NotificationCatalogue');

        $this->emettre(cle: 'loop.something_invented');
    }

    /**
     * Le catalogue lie une cle a UN type d'objet, et l'ecriture le verifie.
     *
     * Sans cette garde, `object_type` serait un champ libre : une notification
     * d'invitation pourrait pointer vers un document, et le rendu ne saurait
     * plus quoi resoudre.
     */
    public function test_the_object_type_declared_by_the_catalogue_is_enforced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('references object type [loop_invitation], got [dossier]');

        $this->emettre(objet: 'dossier');
    }

    /**
     * Le catalogue n'annonce que des canaux qui existent vraiment.
     *
     * **Ce test mesure le CONTENU du catalogue, pas une garde** — il n'y a pas
     * de sabotage qui le fasse rougir, et il faut le dire plutot que de le faire
     * passer pour une preuve de comportement. Son role est de rougir le jour ou
     * quelqu'un ajoutera `email` a une cle avant que l'adaptateur EMAIL
     * n'existe. La garde correspondante, elle, vit dans
     * `NotificationInvariants::assert()` et refuse d'ecrire une ligne IN_APP
     * pour une cle qui n'autorise pas ce canal.
     */
    public function test_the_catalogue_only_declares_channels_that_exist(): void
    {
        foreach (NotificationCatalogue::keys() as $cle) {
            $this->assertSame(
                [NotificationCatalogue::CHANNEL_IN_APP],
                NotificationCatalogue::definition($cle)['channels'],
                "La cle [{$cle}] annonce un canal sans adaptateur : V1-A n'emet que IN_APP."
            );
        }

        $this->assertFalse(
            NotificationCatalogue::allowsChannel(NotificationCatalogue::LOOP_INVITATION, 'email'),
            'Aucun adaptateur EMAIL n\'existe encore ; le catalogue ne doit pas le pretendre.'
        );
    }

    // =====================================================================
    // 2. La frontiere de tenant — par TOUTES les portes
    // =====================================================================

    /**
     * Tenant A / B : Carol est membre de B, on ne lui ecrit rien dans A.
     *
     * La verification porte sur l'appartenance STOCKEE de la personne, jamais
     * sur `app('current_organization')`.
     */
    public function test_a_recipient_from_another_organization_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to the target Organization');

        $this->emettre(organisation: $this->orgA, destinataire: $this->carol);
    }

    /**
     * Et rien n'a ete ecrit — pour LA BONNE RAISON.
     *
     * On epingle la cause : sans cela, le test resterait vert si l'emission
     * echouait pour une cle inconnue ou un `object_type` incoherent, et il
     * cesserait de parler du tenant.
     */
    public function test_a_cross_tenant_attempt_writes_nothing_for_the_tenant_reason(): void
    {
        $cause = null;

        try {
            $this->emettre(organisation: $this->orgA, destinataire: $this->carol);
        } catch (InvalidArgumentException $e) {
            $cause = $e->getMessage();
        }

        $this->assertNotNull($cause, 'L\'emission cross-tenant devait etre refusee.');
        $this->assertStringContainsString('does not belong to the target Organization', $cause);
        $this->assertSame(0, MemberNotification::query()->count());
    }

    /** Le contexte de requete courant ne peut pas faire franchir la frontiere. */
    public function test_the_current_request_context_cannot_override_the_boundary(): void
    {
        // On installe A comme Organization courante : si la garde lisait le
        // contexte au lieu de la donnee, Carol deviendrait un destinataire
        // legitime. Elle ne doit pas l'etre.
        app()->instance('current_organization', $this->orgA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to the target Organization');

        $this->emettre(organisation: $this->orgA, destinataire: $this->carol);
    }

    /**
     * **La garde tient meme sans passer par l'emetteur.**
     *
     * C'est le test qui manquait. `$fillable` porte les colonnes de tenant :
     * si la protection n'etait qu'une convention d'appeler `NotificationEmitter`,
     * ce `create()` direct creerait une ligne cross-tenant sans rien declencher.
     * Les deux FK sont satisfaites — l'Organization A existe, Carol existe, et
     * rien au schema ne les relie — donc seule une garde applicative peut
     * refuser.
     */
    public function test_a_direct_model_write_cannot_cross_the_tenant_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to the target Organization');

        MemberNotification::create([
            'organization_id' => $this->orgA->id,
            'recipient_id' => $this->carol->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => (string) Str::uuid(),
            'object_type' => self::OBJET,
        ]);
    }

    /** Un `create()` direct ne peut pas davantage inventer une cle. */
    public function test_a_direct_model_write_cannot_invent_a_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not declared in NotificationCatalogue');

        MemberNotification::create([
            'organization_id' => $this->orgA->id,
            'recipient_id' => $this->alice->id,
            'notification_key' => 'loop.invented_by_a_careless_service',
            'event_id' => (string) Str::uuid(),
            'object_type' => self::OBJET,
        ]);
    }

    /**
     * **Une ligne creee ne peut plus changer de tenant ni de destinataire.**
     *
     * `creating` seul ne suffisait pas : `organization_id` et `recipient_id`
     * sont affectables en masse, donc un `update()` — celui qu'un futur
     * controleur du Centre ecrirait sans y penser — deplacait la ligne d'un
     * tenant a l'autre sans declencher aucune garde.
     */
    public function test_a_notification_cannot_be_moved_to_another_tenant_by_update(): void
    {
        $notification = $this->emettre(destinataire: $this->alice);

        // `$this->fail()` leve une AssertionFailedError, qui DESCEND de
        // RuntimeException : un `catch (RuntimeException)` l'avalerait et le
        // test rougirait pour la mauvaise raison. On capture le type exact.
        $refus = $this->refusDeMutation(fn () => $notification->update(['organization_id' => $this->orgB->id]));

        $this->assertStringContainsString('immutable fact', $refus);
        $this->assertSame($this->orgA->id, $notification->fresh()->organization_id);
    }

    /** Ni de destinataire — connaitre la ligne ne permet pas de la re-adresser. */
    public function test_a_notification_cannot_be_readdressed_by_update(): void
    {
        $notification = $this->emettre(destinataire: $this->alice);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable fact');

        $notification->update(['recipient_id' => $this->bob->id]);
    }

    /**
     * **`event_id` est fige, et c'est le plus important des sept.**
     *
     * Il est la seule base de `UNIQUE(event_id, recipient_id)`. Le laisser
     * mutable permettrait de re-pointer une notification deja delivree vers un
     * autre evenement — apres quoi l'emission legitime de l'evenement d'origine
     * creerait une SECONDE ligne pour la meme personne, en contournant la
     * contrainte sans jamais la violer.
     */
    public function test_the_event_identity_is_frozen(): void
    {
        $notification = $this->emettre(destinataire: $this->alice);
        $origine = $notification->event_id;

        foreach (['event_id' => (string) Str::uuid(), 'notification_key' => 'autre.cle', 'object_type' => 'dossier', 'object_id' => (string) Str::uuid(), 'actor_id' => $this->bob->id] as $colonne => $valeur) {
            $refus = $this->refusDeMutation(fn () => $notification->fresh()->update([$colonne => $valeur]));

            $this->assertStringContainsString($colonne, $refus, "[{$colonne}] doit etre fige.");
        }

        $this->assertSame($origine, $notification->fresh()->event_id);
    }

    /**
     * `collapse_key` reste mutable — c'est la seule — donc il reste verifie.
     *
     * Le hook `updating` ne controlait d'abord que l'immutabilite. Or
     * `collapse_key` est justement la colonne qui porte un contrat de longueur :
     * un `update()` la faisait deborder en silence sur SQLite et lever `22001`
     * sur PostgreSQL.
     */
    public function test_an_oversized_collapse_key_is_also_refused_on_update(): void
    {
        $notification = $this->emettre();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('collapse_key exceeds');

        $notification->update(['collapse_key' => str_repeat('x', NotificationInvariants::MAX_COLLAPSE_KEY + 1)]);
    }

    /**
     * **L'acteur aussi porte la frontiere de tenant.**
     *
     * La garde ne valait que pour le destinataire. Une ligne d'Organization A
     * pouvait donc porter l'identifiant d'un membre de B — et un rendu qui
     * resout l'acteur aurait divulgue un nom d'un autre tenant. Une notification
     * sans acteur est parfaitement legitime ; une notification avec un acteur
     * etranger ne l'est pas.
     */
    public function test_an_actor_from_another_organization_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('actor does not belong to the target Organization');

        $this->emettre(destinataire: $this->alice, acteur: $this->carol);
    }

    /**
     * **Un `recipient_id` malforme est refuse POUR CETTE RAISON.**
     *
     * C'etait la seule partie obligatoire qu'aucune validation ne couvrait : la
     * valeur filait droit dans le `where('id')` de la verification
     * d'appartenance. SQLite n'y trouvait rien et rendait une erreur de tenant
     * trompeuse ; PostgreSQL levait `22P02`, une classe d'exception entierement
     * differente, non rattrapee.
     *
     * Le message attendu est donc precis : sans la garde, SQLite repondrait
     * encore « does not belong to the target Organization » et ce test resterait
     * vert pour la mauvaise raison.
     */
    public function test_a_malformed_recipient_id_is_refused_as_such(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient_id must be a UUID');

        MemberNotification::create([
            'organization_id' => $this->orgA->id,
            'recipient_id' => 'nobody',
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => (string) Str::uuid(),
            'object_type' => self::OBJET,
        ]);
    }

    /**
     * Les trois colonnes obligatoires le sont VRAIMENT.
     *
     * Le drapeau `required: true` n'etait mesure sur aucune des trois. Le
     * basculer a `false` laissait la suite verte : les identifiants de personne
     * et d'Organization retombaient sur la garde d'appartenance — autre message,
     * non asserte — et un `event_id` nul filait jusqu'a l'INSERT, ou il devenait
     * une erreur NOT NULL de la base, d'une classe qu'aucun `catch` de
     * l'emetteur ne traite.
     */
    public function test_the_three_mandatory_columns_are_really_mandatory(): void
    {
        $complet = [
            'organization_id' => $this->orgA->id,
            'recipient_id' => $this->alice->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => (string) Str::uuid(),
            'object_type' => self::OBJET,
        ];

        foreach (['organization_id', 'recipient_id', 'event_id'] as $obligatoire) {
            $refus = $this->refusDeMutation(
                fn () => MemberNotification::create(array_diff_key($complet, [$obligatoire => null]))
            );

            $this->assertStringContainsString(
                "{$obligatoire} must be a UUID",
                $refus,
                "[{$obligatoire}] absent doit etre refuse pour ce qu'il est."
            );
        }

        $this->assertSame(0, MemberNotification::query()->count());
    }

    /** Et un `organization_id` malforme aussi, pour la meme raison. */
    public function test_a_malformed_organization_id_is_refused_as_such(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('organization_id must be a UUID');

        MemberNotification::create([
            'organization_id' => 'nowhere',
            'recipient_id' => $this->alice->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => (string) Str::uuid(),
            'object_type' => self::OBJET,
        ]);
    }

    /** Un destinataire inexistant est refuse : l'absence de preuve n'est pas une preuve. */
    public function test_an_unknown_recipient_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to the target Organization');

        MemberNotification::create([
            'organization_id' => $this->orgA->id,
            'recipient_id' => (string) Str::uuid(),
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => (string) Str::uuid(),
            'object_type' => self::OBJET,
        ]);
    }

    // =====================================================================
    // 3. Le destinataire est proprietaire — lecture ET mutation
    // =====================================================================

    /** La boite d'Alice ne montre pas les notifications de Bob. */
    public function test_a_member_only_reads_their_own_notifications(): void
    {
        $this->emettre(destinataire: $this->alice);
        $this->emettre(destinataire: $this->bob);

        $lues = MemberNotification::forRecipient($this->orgA->id, $this->alice->id)->get();

        $this->assertCount(1, $lues);
        $this->assertSame($this->alice->id, $lues->first()->recipient_id);
    }

    /** Et la lecture porte AUSSI le tenant, pas seulement l'identite. */
    public function test_the_read_gate_carries_the_tenant_too(): void
    {
        $this->emettre(destinataire: $this->alice);

        $this->assertCount(
            0,
            MemberNotification::forRecipient($this->orgB->id, $this->alice->id)->get(),
            'Le couple (organization_id, recipient_id) est indissociable.'
        );
    }

    /**
     * **Connaitre l'UUID d'une notification n'est pas un droit sur elle.**
     *
     * Bob charge la ligne d'Alice — un futur controleur avec route model
     * binding ferait exactement cela — et tente de la marquer lue en son propre
     * nom. Le refus doit etre bruyant, et la notification d'Alice doit rester
     * non lue.
     */
    public function test_a_member_cannot_mark_another_members_notification_as_read(): void
    {
        $celle_d_alice = $this->emettre(destinataire: $this->alice);

        $chargee_par_bob = MemberNotification::findOrFail($celle_d_alice->id);

        $refus = $this->refusDeMutation(fn () => $chargee_par_bob->markAsReadFor($this->orgA->id, $this->bob->id));

        $this->assertStringContainsString('on behalf of another member', $refus);
        $this->assertNull($celle_d_alice->fresh()->read_at, 'La notification d\'Alice reste non lue.');
    }

    /** Le bon tenant avec le mauvais destinataire ne suffit pas non plus. */
    public function test_marking_read_requires_the_matching_tenant(): void
    {
        $notification = $this->emettre(destinataire: $this->alice);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('on behalf of another member');

        $notification->markAsReadFor($this->orgB->id, $this->alice->id);
    }

    // =====================================================================
    // 4. Le stockage ne retient AUCUN contenu
    // =====================================================================

    /**
     * Les colonnes sont ENUMEREES, pas filtrees par une liste de noms interdits.
     *
     * Une blocklist ne peut pas exprimer « aucun contenu » : `target_url`,
     * `preview`, `summary`, `snippet`, `label` la traverseraient sans bruit.
     * L'allowlist exacte, elle, rougit des qu'une colonne apparait — et c'est
     * precisement le moment ou quelqu'un ajouterait un champ libre.
     */
    public function test_the_table_carries_exactly_the_declared_reference_columns(): void
    {
        $attendues = [
            'actor_id',
            'collapse_key',
            'created_at',
            'event_id',
            'id',
            'notification_key',
            'object_id',
            'object_type',
            'organization_id',
            'read_at',
            'recipient_id',
            'updated_at',
        ];

        $reelles = Schema::getColumnListing('member_notifications');
        sort($reelles);

        $this->assertSame(
            $attendues,
            $reelles,
            'Toute colonne nouvelle doit etre justifiee : le stockage est refs-only.'
        );
    }

    /**
     * Les bornes du CODE doivent correspondre aux bornes de la COLONNE.
     *
     * `MAX_COLLAPSE_KEY` se declare « longueur de la colonne au schema », et le
     * test de depassement construit son entree depuis cette constante : jusqu'ici
     * le code se mesurait donc contre lui-meme. Reduire la colonne a 80 sans
     * toucher a la constante laissait la suite verte pendant que PostgreSQL
     * refusait une cle de 81 caracteres en production.
     *
     * On confronte ici les trois colonnes bornees a ce qui peut y entrer.
     *
     * La mesure porte sur la MIGRATION et non sur le schema vivant : SQLite
     * declare ses `varchar` sans longueur, donc la base ne saurait pas repondre.
     * La declaration est de toute facon la source de verite commune aux deux
     * moteurs — c'est elle qu'il faut garder alignee avec la constante.
     */
    public function test_the_declared_limits_match_the_real_columns(): void
    {
        $migration = file_get_contents(
            database_path('migrations/2026_09_02_230000_create_member_notifications_table.php')
        );

        $this->assertStringContainsString(
            "string('collapse_key', ".NotificationInvariants::MAX_COLLAPSE_KEY.')',
            $migration,
            'MAX_COLLAPSE_KEY doit refleter la largeur declaree pour la colonne.'
        );

        // Et ce que le catalogue peut produire doit tenir dans ses colonnes.
        // Les largeurs sont LUES dans la migration, pas recopiees ici : des
        // litteraux se seraient mesures contre eux-memes, ce que ce test existe
        // precisement pour empecher.
        $largeur = function (string $colonne) use ($migration): int {
            $this->assertSame(
                1,
                preg_match("/string\\('{$colonne}', (\\d+)\\)/", $migration, $m),
                "La migration doit declarer une largeur pour [{$colonne}]."
            );

            return (int) $m[1];
        };

        $largeurCle = $largeur('notification_key');
        $largeurType = $largeur('object_type');

        foreach (NotificationCatalogue::keys() as $cle) {
            $this->assertLessThanOrEqual($largeurCle, mb_strlen($cle), "La cle [{$cle}] deborde de notification_key({$largeurCle}).");
            $this->assertLessThanOrEqual(
                $largeurType,
                mb_strlen(NotificationCatalogue::objectTypeFor($cle)),
                "L'object_type de [{$cle}] deborde de object_type({$largeurType})."
            );
        }
    }

    /** Ce qui est stocke se limite a des references resolubles. */
    public function test_what_is_stored_is_a_reference_only(): void
    {
        $invitation = (string) Str::uuid();

        $notification = $this->emettre(objetId: $invitation, acteur: $this->bob);

        $this->assertSame(self::OBJET, $notification->object_type);
        $this->assertSame($invitation, $notification->object_id);
        $this->assertSame($this->bob->id, $notification->actor_id);
    }

    /** `collapse_key` est prevu au schema des V1, meme s'il n'est pas exploite. */
    public function test_collapse_key_exists_in_the_schema(): void
    {
        $notification = $this->emettre(regroupement: 'loop.invitation:'.$this->orgA->id);

        $this->assertSame('loop.invitation:'.$this->orgA->id, $notification->fresh()->collapse_key);
    }

    /**
     * Un `collapse_key` trop long est refuse par le CODE, pas par le moteur.
     *
     * PostgreSQL leve `22001` sur depassement ; **SQLite ignore les longueurs de
     * varchar**. Sans garde explicite, une cle de regroupement bavarde passerait
     * en local et casserait en CI.
     */
    public function test_an_oversized_collapse_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('collapse_key exceeds');

        $this->emettre(regroupement: str_repeat('x', NotificationInvariants::MAX_COLLAPSE_KEY + 1));
    }

    // =====================================================================
    // 5. UUID — le meme comportement sur les deux moteurs
    // =====================================================================

    /** Un `event_id` qui n'est pas un UUID ne peut pas fonder une idempotence. */
    public function test_a_non_uuid_event_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_id must be a UUID');

        $this->emettre(evenement: 'invitation-42');
    }

    /**
     * Un `object_id` non-UUID est refuse — sinon le moteur trancherait a notre
     * place : SQLite l'accepterait comme texte, PostgreSQL leverait `22P02`.
     */
    public function test_a_non_uuid_object_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('object_id must be a UUID');

        $this->emettre(objetId: 'invitation-42');
    }

    /**
     * **Le meme UUID en casses differentes est le meme evenement.**
     *
     * PostgreSQL a un type `uuid` natif qui replie la casse et deduplique donc
     * tout seul ; SQLite stocke deux chaines distinctes et creerait DEUX lignes.
     * Sans normalisation, l'idempotence dependrait du moteur — et le moteur le
     * plus permissif serait celui des tests.
     */
    public function test_the_same_uuid_in_mixed_case_is_a_single_event(): void
    {
        $evenement = (string) Str::uuid();

        $premiere = $this->emettre(evenement: Str::upper($evenement));
        $seconde = $this->emettre(evenement: Str::lower($evenement));

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, MemberNotification::query()->count());
        $this->assertSame(Str::lower($evenement), $premiere->fresh()->event_id);
    }

    /**
     * **Et la normalisation vaut aussi pour une ecriture DIRECTE.**
     *
     * C'est la correction que la premiere remediation avait manquee : elle
     * normalisait dans l'emetteur seulement. Un `create()` direct stockait donc
     * encore un UUID en majuscules, et sur SQLite — dont la colonne est un
     * `varchar` a collation binaire — l'emission canonique suivante ne
     * declenchait aucune violation d'unicite. Deux lignes pour un evenement,
     * en local uniquement.
     */
    public function test_a_direct_write_also_stores_a_canonical_uuid(): void
    {
        $evenement = (string) Str::uuid();

        $directe = MemberNotification::create([
            'organization_id' => $this->orgA->id,
            'recipient_id' => $this->alice->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => Str::upper($evenement),
            'object_type' => self::OBJET,
        ]);

        $this->assertSame(Str::lower($evenement), $directe->fresh()->event_id);

        // Et la deduplication fonctionne donc reellement derriere.
        $this->assertSame($directe->id, $this->emettre(evenement: $evenement)->id);
        $this->assertSame(1, MemberNotification::query()->count());
    }

    /**
     * **La normalisation couvre les CINQ colonnes `uuid`, pas seulement deux.**
     *
     * Les identifiants de personnes et d'Organization sont eux aussi des `uuid`
     * natifs sur PostgreSQL et de simples `varchar` sur SQLite. Un
     * `recipient_id` en majuscules trouvait donc son membre d'un cote et pas de
     * l'autre : PostgreSQL inserait la ligne, SQLite levait une erreur de
     * frontiere de tenant qui n'en etait pas une. Une difference de casse ne
     * doit pas se muer en verdict de securite.
     */
    public function test_every_uuid_column_is_stored_canonically(): void
    {
        $notification = MemberNotification::create([
            'organization_id' => Str::upper($this->orgA->id),
            'recipient_id' => Str::upper($this->alice->id),
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => Str::upper((string) Str::uuid()),
            'object_type' => self::OBJET,
            'object_id' => Str::upper((string) Str::uuid()),
            'actor_id' => Str::upper($this->bob->id),
        ])->fresh();

        foreach (['organization_id', 'recipient_id', 'event_id', 'object_id', 'actor_id'] as $colonne) {
            $this->assertSame(
                Str::lower($notification->{$colonne}),
                $notification->{$colonne},
                "[{$colonne}] doit etre stocke sous sa forme canonique."
            );
        }

        // Et la ligne est bien rattachee, pas seulement bien ecrite.
        $this->assertSame(1, MemberNotification::forRecipient($this->orgA->id, $this->alice->id)->count());
    }

    /**
     * La chaine vide n'est pas un UUID, et ne doit pas etre ecrite comme valeur.
     *
     * Elle se glissait entre les mailles : acceptee comme « absente » a la
     * validation, puis inseree telle quelle. SQLite l'aurait stockee,
     * PostgreSQL aurait leve `22P02`. Un producteur ecrivant
     * `objectId: $invitation->id ?? ''` suffisait a declencher cela.
     */
    public function test_an_empty_object_id_becomes_null_instead_of_being_stored(): void
    {
        $notification = $this->emettre(objetId: '');

        $this->assertNull($notification->fresh()->object_id);
    }

    // =====================================================================
    // 6. Idempotence
    // =====================================================================

    /** Le meme fait generateur rejoue ne notifie pas deux fois. */
    public function test_replaying_the_same_event_creates_a_single_notification(): void
    {
        $evenement = (string) Str::uuid();

        $premiere = $this->emettre(evenement: $evenement);
        $seconde = $this->emettre(evenement: $evenement);

        $this->assertSame($premiere->id, $seconde->id, 'Le rejeu doit rendre la ligne existante.');
        $this->assertSame(1, MemberNotification::query()->count());
    }

    /** Mais un meme evenement notifie bien CHAQUE destinataire. */
    public function test_one_event_still_reaches_every_recipient(): void
    {
        $evenement = (string) Str::uuid();

        $this->emettre(evenement: $evenement, destinataire: $this->alice);
        $this->emettre(evenement: $evenement, destinataire: $this->bob);

        $this->assertSame(2, MemberNotification::query()->count());
        $this->assertSame(1, MemberNotification::forRecipient($this->orgA->id, $this->alice->id)->count());
        $this->assertSame(1, MemberNotification::forRecipient($this->orgA->id, $this->bob->id)->count());
    }

    /**
     * **Un `event_id` reutilise pour AUTRE CHOSE leve, il ne rend pas l'ancienne
     * ligne.**
     *
     * `event_id` identifie l'EVENEMENT DE NOTIFICATION, pas l'objet metier — et
     * `loop_invitations.id` etant deja un UUID, la confusion est tentante. Si le
     * rattrapage rendait la ligne existante sans la comparer, l'appelant
     * croirait avoir emis un rappel et recevrait l'invitation d'origine :
     * mauvaise cle, mauvais objet, aucun bruit.
     */
    public function test_reusing_an_event_id_for_a_different_emission_throws(): void
    {
        $evenement = (string) Str::uuid();
        $this->emettre(evenement: $evenement, objetId: (string) Str::uuid());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('event_id identifies the notification event, not the business object');

        $this->emettre(evenement: $evenement, objetId: (string) Str::uuid());
    }

    /**
     * **Un fait deja delivre reste rejouable, meme si l'acteur a quitte
     * l'Organization depuis.**
     *
     * C'est le defaut que la garde d'appartenance sur l'acteur avait introduit :
     * elle s'applique dans `creating`, donc AVANT que la contrainte d'unicite
     * puisse trancher. Un producteur qui rejoue — une file d'attente qui reprend
     * un job, un redeploiement — echouait alors definitivement sur un evenement
     * DEJA delivre, pour une raison qui n'existait pas au moment de l'emission.
     *
     * Le rejeu ne cree rien : il constate. Rien n'est assoupli pour autant — sans
     * ligne existante a l'identique, le refus repart tel quel.
     */
    public function test_a_replay_still_works_after_the_actor_left_the_organization(): void
    {
        $evenement = (string) Str::uuid();
        $premiere = $this->emettre(evenement: $evenement, acteur: $this->bob);

        // Bob quitte l'Organization — exactement ce que fait la suppression
        // d'une Organization, qui detache ses membres.
        $this->bob->forceFill(['organization_id' => null])->save();

        $rejeu = $this->emettre(evenement: $evenement, acteur: $this->bob);

        $this->assertSame($premiere->id, $rejeu->id, 'Le rejeu d\'un fait acquis doit rendre la ligne existante.');
        $this->assertSame(1, MemberNotification::query()->count());
    }

    /**
     * Le rattrapage de rejeu ne relance jamais une recherche avec un UUID
     * malforme.
     *
     * Le refus qu'il intercepte peut justement venir d'un UUID invalide :
     * relancer un `SELECT` avec cette valeur rouvrirait la `22P02` PostgreSQL
     * que la garde vient de fermer, pendant que SQLite rendrait tranquillement
     * `null`. Le refus d'origine doit ressortir intact.
     */
    public function test_a_malformed_event_id_is_still_refused_as_such(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_id must be a UUID');

        $this->emettre(evenement: 'pas-un-uuid', acteur: $this->bob);
    }

    /**
     * **Le rattrapage de rejeu ne couvre QUE le rejeu a l'identique.**
     *
     * C'est la branche qui doit refuser, et elle n'etait pas mesuree : un refus
     * d'appartenance dont l'`event_id` correspond a une ligne existante mais
     * dont la charge differe ne doit pas etre transforme en succes. Sans la
     * comparaison champ a champ, ce cas rendrait l'ancienne notification comme
     * si l'emission avait eu lieu.
     */
    public function test_the_replay_recovery_refuses_a_different_payload(): void
    {
        $evenement = (string) Str::uuid();
        $this->emettre(evenement: $evenement, objetId: (string) Str::uuid(), acteur: $this->bob);

        $this->bob->forceFill(['organization_id' => null])->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('actor does not belong to the target Organization');

        $this->emettre(evenement: $evenement, objetId: (string) Str::uuid(), acteur: $this->bob);
    }

    /**
     * La relecture de rattrapage porte le TENANT, pas seulement le destinataire.
     *
     * `users.organization_id` est mutable — la suppression d'une Organization
     * detache ses membres. Un `event_id` reutilise a travers un changement
     * d'appartenance rendrait donc, sans cette portee, une ligne estampillee
     * d'un autre tenant a un appelant qui interroge le sien.
     */
    public function test_the_recovery_lookup_is_tenant_scoped(): void
    {
        $evenement = (string) Str::uuid();
        $this->emettre(evenement: $evenement, destinataire: $this->alice);

        // Alice change d'Organization ; l'ancienne notification reste dans A.
        $this->alice->forceFill(['organization_id' => $this->orgB->id])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the target Organization');

        $this->emettre(evenement: $evenement, organisation: $this->orgB, destinataire: $this->alice);
    }

    /** Mais un acteur parti ne peut pas fonder une NOUVELLE notification. */
    public function test_a_departed_actor_still_cannot_found_a_new_notification(): void
    {
        $this->bob->forceFill(['organization_id' => null])->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('actor does not belong to the target Organization');

        $this->emettre(evenement: (string) Str::uuid(), acteur: $this->bob);
    }

    /**
     * L'idempotence est une CONTRAINTE de base, pas une lecture prealable.
     *
     * La violation est provoquee DANS un savepoint : sur PostgreSQL, une
     * violation de contrainte abandonne la transaction courante, et
     * `RefreshDatabase` en tient une ouverte. Sans cette enveloppe, toute
     * assertion suivante tomberait en `25P02` — le test ne passerait que parce
     * que rien ne le suit. L'assertion finale existe precisement pour prouver
     * que ce n'est pas le cas.
     */
    public function test_the_uniqueness_is_enforced_by_the_database(): void
    {
        $evenement = (string) Str::uuid();
        $premiere = $this->emettre(evenement: $evenement);

        $leve = false;

        try {
            DB::transaction(fn () => MemberNotification::create([
                'organization_id' => $this->orgA->id,
                'recipient_id' => $this->alice->id,
                'notification_key' => NotificationCatalogue::LOOP_INVITATION,
                'event_id' => $evenement,
                'object_type' => self::OBJET,
            ]));
        } catch (UniqueConstraintViolationException) {
            $leve = true;
        }

        $this->assertTrue($leve, 'La base doit refuser le doublon elle-meme.');

        // La transaction a survecu au savepoint : on peut encore interroger.
        $this->assertSame(1, MemberNotification::query()->count());
        $this->assertSame($premiere->id, MemberNotification::query()->first()->id);
    }

    // =====================================================================
    // 7. Lu / non lu
    // =====================================================================

    /** Une notification nait non lue. */
    public function test_a_notification_is_born_unread(): void
    {
        $notification = $this->emettre();

        $this->assertFalse($notification->isRead());
        $this->assertSame(1, MemberNotification::forRecipient($this->orgA->id, $this->alice->id)->unread()->count());
    }

    /**
     * Marquer lu est idempotent : la premiere lecture fait foi.
     *
     * Le decalage de temps n'est pas cosmetique. `read_at` est un `timestamp`
     * a la seconde : sans lui, les deux appels tombent dans la meme seconde et
     * une reecriture serait INVISIBLE — le test resterait vert meme si la garde
     * disparaissait. Mesure verifiee par sabotage.
     */
    public function test_marking_as_read_is_idempotent(): void
    {
        $notification = $this->emettre();

        $notification->markAsReadFor($this->orgA->id, $this->alice->id);
        $premiere = $notification->fresh()->read_at;

        $this->travel(2)->seconds();
        $notification->fresh()->markAsReadFor($this->orgA->id, $this->alice->id);

        $this->assertNotNull($premiere);
        $this->assertEquals($premiere, $notification->fresh()->read_at, 'La date de premiere lecture ne doit pas glisser.');
        $this->assertSame(0, MemberNotification::forRecipient($this->orgA->id, $this->alice->id)->unread()->count());
    }

    /** `read_at` n'est pas affectable en masse : c'est un geste, pas une donnee. */
    public function test_read_at_is_not_mass_assignable(): void
    {
        $notification = $this->emettre();

        $notification->fill(['read_at' => now()]);

        $this->assertNull($notification->read_at, 'read_at doit rester hors de $fillable.');
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * Execute une mutation attendue en echec et rend son message.
     *
     * `$this->fail()` leve une `AssertionFailedError`, qui DESCEND de
     * `RuntimeException`. Un `try { … $this->fail(); } catch (RuntimeException)`
     * avale donc sa propre assertion : le test rougit toujours, mais pour la
     * mauvaise raison, et les assertions suivantes ne s'executent jamais.
     */
    private function refusDeMutation(callable $mutation): string
    {
        try {
            $mutation();
        } catch (RuntimeException|InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $this->fail('La mutation aurait du etre refusee.');
    }

    private function emettre(
        ?string $cle = null,
        ?Organization $organisation = null,
        ?User $destinataire = null,
        ?string $evenement = null,
        ?string $objet = null,
        ?string $objetId = null,
        ?User $acteur = null,
        ?string $regroupement = null,
    ): MemberNotification {
        return $this->emetteur->emit(
            notificationKey: $cle ?? NotificationCatalogue::LOOP_INVITATION,
            organization: $organisation ?? $this->orgA,
            recipient: $destinataire ?? $this->alice,
            eventId: $evenement ?? (string) Str::uuid(),
            objectType: $objet ?? self::OBJET,
            objectId: $objetId,
            actor: $acteur,
            collapseKey: $regroupement,
        );
    }
}
