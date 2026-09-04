<?php

namespace App\Support\ScenarioPacks\Packs;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiDoctrine;
use App\Models\OrganizationAiSetting;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopDecisionService;
use App\Services\Loops\LoopEventService;
use App\Services\Loops\LoopPollService;
use App\Services\Loops\LoopRootDocumentService;
use App\Services\LoopService;
use App\Support\ScenarioPacks\Contracts\ProvisionsItsOrganization;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOrganizationNotAdoptableException;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

/**
 * TASK-1351 — dogfooding ANGLAIS pour la demonstration Roger / ArtSciLab /
 * UT Dallas, dans l'Organization dediee `artscilab-en`.
 *
 * ## Ce que ce pack n'est pas
 *
 * Ce n'est PAS une traduction de `test20260822` (dataset FR de
 * Cyril, dont le corpus vit hors git dans `_temp/`), ni une evolution de
 * `artscilab-demo-test` (laboratoire europeen, monde narratif different).
 * Les deux restent strictement intacts : ce pack est une TROISIEME definition
 * sur le meme moteur T1240/T1245, additive.
 *
 * ## Le monde raconte
 *
 * Un laboratoire art-science universitaire fictif : une directrice qui
 * arbitre, un renouvellement de subvention a preparer, une chercheuse en
 * sonification de donnees climatiques, un charge d'engagement public et
 * d'ethique — et une nouvelle arrivante qui ne connait encore rien. Wen Zhao
 * est le personnage de recette le plus important du pack : son profil
 * incomplet, son unique Boucle et son absence totale de messages ne sont pas
 * un dataset pauvre, ce SONT la donnee. On ne peut pas prouver que BouclePro
 * aide quelqu'un qui arrive sans contexte avec un membre qui a deja tout.
 *
 * ## Provenance de l'Organization
 *
 * Le pack implemente {@see ProvisionsItsOrganization} : il cree son
 * Organization quand elle n'existe pas, pour que l'etat de demonstration soit
 * reproductible sans geste manuel prealable. L'Organization elle-meme n'est
 * jamais inscrite au registre — le registrar exige un `organization_id` sur
 * chaque entite, et une Organization n'en porte pas. Sa provenance est portee
 * par `scenario_pack_loads.organization_created_by_pack`, ecrite par la
 * commande de chargement.
 *
 * Une Organization preexistante qui porte la moindre donnee metier est
 * refusee : jamais d'adoption silencieuse, jamais d'ecrasement.
 *
 * ## Aucune personne reelle
 *
 * Les cinq personas sont fictifs et leurs adresses sont en `.test`. Aucun
 * document ne reprend un contenu reel d'ArtSciLab ou d'UT Dallas : tout le
 * corpus est ecrit pour ce pack, en anglais natif, et vit dans ce fichier —
 * donc tracke par git, portable, sans dependance a un chemin hors depot.
 */
class ArtSciLabEnglishPack implements ProvisionsItsOrganization, ScenarioPackDefinition
{
    public const PACK_ID = 'artscilab-en-tests';

    public const ORGANIZATION_SLUG = 'artscilab-en';

    public const DISK = 'dossier_files';

    /**
     * Le nom AFFICHE du tenant (TASK-1396).
     *
     * Il valait `ArtSciLab — Test anglais` : un libelle FRANCAIS pour
     * l'Organization anglaise de la demonstration, visible dans l'en-tete, le
     * selecteur de tenant et sous les cartes du Shell — donc sur le chemin que
     * Roger verra.
     */
    public const ORGANIZATION_NAME = 'ArtSciLab — English test';

    /**
     * Les noms que ce pack a lui-meme ecrits par le passe.
     *
     * `provisionOrganization()` n'est appele QUE si l'Organization n'existe
     * pas : sur un tenant deja provisionne, corriger la constante ne change
     * RIEN. Le pack doit donc reconcilier a chaque chargement — c'est la lecon
     * de TASK-1395, ou retirer une valeur du code n'avait rien efface en base.
     *
     * Mais reconcilier sans condition ecraserait un nom qu'une personne aurait
     * choisi. Seules ces variantes, que le pack reconnait comme siennes, sont
     * reprises. Meme garde que la migration de convergence TASK-1354, et pour
     * la meme raison.
     *
     * @var list<string>
     */
    public const HISTORICAL_ORGANIZATION_NAMES = [
        'ArtSciLab — Test anglais',
        'ArtSciLab — English dogfooding',
    ];

    /**
     * Les cinq personas, dans l'ordre d'inscription au registre.
     *
     * `new_member` marque Wen Zhao : le pack lui refuse deliberement profil
     * complet, activite et appartenances multiples.
     *
     * @var array<string, array{name: string, first_name: string, city: string, country_code: string, bio: ?string, new_member: bool}>
     */
    public const PERSONAS = [
        'elena' => [
            'name' => 'Elena Cho',
            'first_name' => 'Elena',
            'city' => 'Dallas',
            'country_code' => 'US',
            'bio' => 'Lab director. Decides what the lab commits to, and what it declines.',
            'new_member' => false,
        ],
        'marcus' => [
            'name' => 'Marcus Whitfield',
            'first_name' => 'Marcus',
            'city' => 'Dallas',
            'country_code' => 'US',
            'bio' => 'Leads the grant renewal. Writes the narrative, chases the evidence.',
            'new_member' => false,
        ],
        'priya' => [
            'name' => 'Priya Nandakumar',
            'first_name' => 'Priya',
            'city' => 'Austin',
            'country_code' => 'US',
            'bio' => 'Turns climate datasets into sound. Careful about what the data cannot say.',
            'new_member' => false,
        ],
        'sam' => [
            'name' => 'Sam Okafor',
            'first_name' => 'Sam',
            'city' => 'Fort Worth',
            'country_code' => 'US',
            'bio' => 'Public engagement and ethics. Asks who is affected before anything ships.',
            'new_member' => false,
        ],
        // NEW MEMBER / LOW CONTEXT — profil volontairement incomplet.
        'wen' => [
            'name' => 'Wen Zhao',
            'first_name' => 'Wen',
            'city' => 'Dallas',
            'country_code' => 'US',
            'bio' => null,
            'new_member' => true,
        ],
    ];

    /**
     * Les profils IA publies du tenant (TASK-1393).
     *
     * ## La regle, et ce qu'elle interdit
     *
     * > Ne pas inventer de talents pour satisfaire une phrase de demonstration.
     *
     * Chaque competence ci-dessous est ETAYEE par un texte que ce pack
     * contenait DEJA : la bio du persona, une decision qu'il a signee, une
     * demande ou une offre dont il est l'auteur. La colonne `provenance` le
     * dit pour chacune, et
     * `TASK1393ArtSciLabMatchingReadinessTest::test_every_declared_skill_is_backed_by_pack_material`
     * le MESURE — une competence ajoutee demain sans source ferait rougir la
     * suite.
     *
     * Appartenir a une Boucle ne vaut PAS preuve : « Priya est dans Sonic
     * Terrain » ne dit rien de ce qu'elle sait faire. Seul ce qu'une personne a
     * ecrit, decide, demande ou offert compte.
     *
     * ## Pourquoi QUATRE profils et non cinq
     *
     * `wen` n'est l'auteur de rien — zero message, zero decision, zero demande,
     * zero offre — et sa bio est `null` A DESSEIN (`new_member: true`). Lui
     * fabriquer des competences trahirait la regle et detruirait le seul cas de
     * demonstration ou le produit doit dire « je ne sais pas encore ». Son
     * absence est un RESULTAT, pas un oubli.
     *
     * `elena` est le cas limite, et il est assume : elle n'est autrice
     * d'aucune action, sa seule preuve est sa bio. Elle recoit donc UNE
     * competence, tiree litteralement de ce role, et pas davantage.
     *
     * @var array<string, array{skills: list<string>, summary: string, provenance: array<string, string>}>
     */
    public const AI_PROFILES = [
        'priya' => [
            'skills' => ['Data sonification', 'Data visualisation', 'Uncertainty mapping'],
            'summary' => 'Turns climate datasets into sound, and says plainly where a representation claims more precision than the data supports.',
            'provenance' => [
                'Data sonification' => 'bio : « Turns climate datasets into sound. »',
                'Data visualisation' => 'offre `data-visualisation-review` : « how your data becomes an image or a sound ».',
                'Uncertainty mapping' => 'demande `second-opinion-on-mapping` : « Our roughness mapping saturates on wide confidence intervals. I wrote it. »',
            ],
        ],
        'sam' => [
            'skills' => ['Public engagement', 'Session facilitation', 'Ethics review'],
            'summary' => 'Prepares and hosts public sessions, then writes up what was actually heard — disagreement included.',
            'provenance' => [
                'Public engagement' => 'bio : « Public engagement and ethics. »',
                'Session facilitation' => 'offre `session-facilitation` : « Preparing and hosting a session ».',
                'Ethics review' => 'decision `named-reviewer` : « Nothing generated leaves the lab without a named human reviewer. »',
            ],
        ],
        'marcus' => [
            'skills' => ['Grant writing', 'Impact evidence'],
            'summary' => 'Leads the grant renewal: writes the narrative, and chases the evidence that supports it.',
            'provenance' => [
                'Grant writing' => 'bio : « Leads the grant renewal. Writes the narrative. »',
                'Impact evidence' => 'bio : « chases the evidence » ; decision `lead-with-listening` : « what we can evidence ».',
            ],
        ],
        'elena' => [
            'skills' => ['Commitment decisions'],
            'summary' => 'Lab director: decides what the lab commits to, and what it declines.',
            'provenance' => [
                // Premiere redaction : « Research direction ». Le test de
                // provenance l'a REFUSEE, et il avait raison — la bio dit
                // « director », jamais « direction ». Une lettre d'ecart, mais
                // le glissement etait reel : je reformulais au lieu de citer.
                // Le libelle reprend donc les mots du pack : « Decides what the
                // lab COMMITS to ».
                'Commitment decisions' => 'bio : « Lab director. Decides what the lab commits to, and what it declines. » — SEULE preuve disponible pour ce persona, d\'ou une competence unique.',
            ],
        ],
    ];

    /** Les six Boucles, dans l'ordre. La cle est stable, le nom est affiche. */
    public const LOOPS = [
        'sonic_terrain' => [
            'name' => 'Sonic Terrain — Climate Data Sonification',
            'tagline' => 'Turning climate records into pieces people can hear.',
            'description' => 'Where the sonification work happens: datasets, method, listening tests and what the sound is allowed to claim.',
            'owner' => 'priya',
            'members' => ['elena', 'sam'],
        ],
        'circle_orientation' => [
            'name' => 'Circle Orientation',
            'tagline' => 'Making the lab legible to someone who just arrived.',
            'description' => 'The landing place for new members: who does what, how decisions are made, and where to ask a first question.',
            'owner' => 'elena',
            // La seule Boucle de Wen : c'est la surface de l'ACTE 1.
            'members' => ['sam', 'wen'],
        ],
        'nsf_steam_bridge' => [
            'name' => 'NSF STEAM Bridge — Grant Renewal',
            'tagline' => 'Preparing the renewal, and the evidence behind it.',
            'description' => 'Renewal narrative, broader impacts evidence, budget justification and reviewer feedback.',
            'owner' => 'marcus',
            'members' => ['elena', 'priya'],
        ],
        'visiting_fellows' => [
            'name' => 'Visiting Fellows 2026',
            'tagline' => 'Hosting fellows so their stay produces something.',
            'description' => 'Onboarding, mentoring pairs, deliverables and the practical side of a visit.',
            'owner' => 'elena',
            'members' => ['marcus'],
        ],
        'consent_ethics' => [
            'name' => 'Consent & Ethics Review',
            'tagline' => 'Deciding what may be recorded, reused and published.',
            'description' => 'Consent language, the human review checkpoint, and the boundaries of reuse for generated material.',
            'owner' => 'sam',
            'members' => ['elena', 'priya'],
        ],
        'public_engagement' => [
            'name' => 'Public Engagement & Listening Sessions',
            'tagline' => 'Meeting people, and reporting what was actually heard.',
            'description' => 'Preparing public sessions, facilitating them, and writing down what came back.',
            'owner' => 'sam',
            'members' => ['elena', 'marcus'],
        ],
    ];

    /**
     * Doctrine IA de l'Organization, en anglais. Elle appartient a CETTE
     * Organization : la Constitution plateforme reste unique et intouchee.
     */
    public const DOCTRINE = <<<'EN'
        How this lab works with AI

        1. The AI drafts; a person decides and publishes.
        2. Name what is uncertain rather than smoothing it over.
        3. Consent comes before recording, reuse or generation.
        4. Credit the people whose work is being described.
        5. Prefer a short, sourced answer to a confident one.
        6. Nothing leaves this organisation's own material.
        7. When context is missing, ask instead of assuming.
        EN;

    public function __construct(
        private readonly LoopService $loops,
        private readonly LoopRootDocumentService $rootDocuments,
        // Les primitives canoniques de l'activite : le pack n'ecrit jamais un
        // message, un sondage, une decision ou un evenement a la main.
        private readonly LoopMessageService $messages,
        private readonly LoopPollService $polls,
        private readonly LoopDecisionService $decisions,
        private readonly LoopEventService $events,
        // Seul ecrivain legitime de `loop_cards.enabled`.
        private readonly LoopCardCompositionService $composition,
    ) {}

    public function packId(): string
    {
        return self::PACK_ID;
    }

    public function packVersion(): string
    {
        return '1.0.0';
    }

    public function packName(): string
    {
        return self::ORGANIZATION_NAME;
    }

    public function purpose(): string
    {
        return 'Prove that an English-speaking newcomer can arrive in BouclePro, understand where they are, consult authorised knowledge, express a real need, and stay in charge of the action.';
    }

    public function organizationSlug(): string
    {
        return self::ORGANIZATION_SLUG;
    }

    public function provisionOrganization(): Organization
    {
        return Organization::create([
            'name' => self::ORGANIZATION_NAME,
            'slug' => self::ORGANIZATION_SLUG,
            'description' => 'A fictional art-science lab used to demonstrate BouclePro in English.',
            'is_active' => true,
            'is_public' => false,
            'is_default' => false,
            'welcome_points' => 500,
            'loops_enabled' => true,
            'members_can_create_loops' => true,
            'ai_profiles_enabled' => true,
            'loop_mode' => 'multi',
            'locale' => 'en',
            'default_country_code' => 'US',
            'show_country' => true,
        ]);
    }

    public function assertOrganizationAdoptable(Organization $organization): void
    {
        // Compte SANS scope global : une Organization « vide » ne doit pas le
        // paraitre seulement parce que l'appelant n'a pas le droit de voir ce
        // qu'elle contient.
        $contents = array_filter([
            'users' => User::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'loops' => Loop::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'dossiers' => Dossier::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'blog_posts' => BlogPost::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'service_requests' => ServiceRequest::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'services' => Service::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
        ], static fn (int $count): bool => $count > 0);

        if ($contents !== []) {
            throw ScenarioPackOrganizationNotAdoptableException::forOrganization($organization->slug, $contents);
        }
    }

    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        // Hard-bound : le pack n'ecrit que dans SON Organization, jamais dans
        // une cible devinee ou passee par erreur.
        if ($organization->slug !== self::ORGANIZATION_SLUG) {
            throw new LogicException(
                "ArtSciLabEnglishPack ne peut cibler que l'Organization '".self::ORGANIZATION_SLUG."', reçu '{$organization->slug}'."
            );
        }

        $base = CarbonImmutable::parse('2026-06-02 09:00:00', 'UTC');

        $this->reconcileOrganizationName($organization);

        $personas = $this->personas($organization, $registrar, $base);
        $this->aiProfiles($organization, $personas, $registrar, $base);
        [$loops, $dossiers] = $this->loops($organization, $personas, $registrar);
        $categories = $this->categories($organization, $registrar);

        $this->corpus($organization, $dossiers, $personas, $registrar);
        $this->showTheDataThePackWrites($loops);
        $this->conversation($loops, $personas, $registrar, $base);
        $this->poll($loops, $personas, $registrar);
        $this->decisions($loops, $personas, $registrar, $base);
        $this->roadmap($loops, $personas, $registrar);
        $this->futureEvent($loops, $personas, $registrar);
        $this->marketplace($organization, $personas, $categories, $registrar);

        $this->aiSettings($organization, $registrar);
        $this->doctrine($organization, $personas['elena'], $registrar);
    }

    /**
     * Remet le nom du tenant a sa valeur canonique — et seulement si le nom
     * courant est l'un de ceux que ce pack a lui-meme ecrits.
     *
     * Un nom choisi par une personne n'est jamais ecrase : il ne figure pas
     * dans les variantes connues, donc rien ne se produit.
     */
    private function reconcileOrganizationName(Organization $organization): void
    {
        if ($organization->name === self::ORGANIZATION_NAME) {
            return;
        }

        if (! in_array($organization->name, self::HISTORICAL_ORGANIZATION_NAMES, true)) {
            return;
        }

        $organization->update(['name' => self::ORGANIZATION_NAME]);
    }

    /**
     * @return array<string, User>
     */
    private function personas(Organization $organization, ScenarioPackEntityRegistrar $registrar, CarbonImmutable $base): array
    {
        $personas = [];
        $index = 0;

        foreach (self::PERSONAS as $key => $persona) {
            $index++;
            $user = User::updateOrCreate(
                ['email' => $key.'@artscilab-en.test'],
                [
                    'organization_id' => $organization->id,
                    'name' => $persona['name'],
                    'first_name' => $persona['first_name'],
                    'password' => Hash::make('password'),
                    'bio' => $persona['bio'],
                    'city' => $persona['new_member'] ? null : $persona['city'],
                    'location' => $persona['new_member'] ? null : $persona['city'].', United States',
                    'country_code' => $persona['country_code'],
                    'preferred_locale' => 'en',
                    'is_available' => ! $persona['new_member'],
                    'is_admin' => false,
                    'banned_at' => null,
                ],
            );

            // `email_verified_at` n'est pas `$fillable` : le passer a
            // `updateOrCreate` ne l'ecrit PAS (le seeder ArtSciLab croit le
            // faire depuis T1203 — ses personas sont non verifies en base).
            // Un persona de demonstration non verifie peut se faire arreter par
            // une garde de verification avant meme d'atteindre le Shell : c'est
            // exactement le genre de detail qui rend une demo non reproductible.
            // On l'ecrit donc par la primitive prevue pour ca, sans elargir
            // `$fillable`.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => $base])->saveQuietly();
            }

            if ($user->wasRecentlyCreated) {
                $user->update(['points_balance' => 500]);
            }

            $registrar->track('persona', $key, $user);
            $personas[$key] = $user;
        }

        $organization->update(['admin_id' => $personas['elena']->id]);

        return $personas;
    }

    /**
     * Les profils IA publies des personas qui ont de quoi les etayer.
     *
     * Sans profil publie, aucune PersonCard ne peut apparaitre : l'ensemble
     * eligible est vide, et le matching n'a rien a departager. Ce n'est donc
     * pas une donnee decorative — c'est ce qui rend la moitie « humaine » du
     * produit demontrable.
     *
     * `updateOrCreate` sur `(organization_id, user_id)` : rejouer le pack met a
     * jour, ne duplique pas. Un profil duplique ferait apparaitre deux fois la
     * meme personne dans les resultats.
     *
     * @param  array<string, User>  $personas
     */
    private function aiProfiles(Organization $organization, array $personas, ScenarioPackEntityRegistrar $registrar, CarbonImmutable $base): void
    {
        foreach (self::AI_PROFILES as $key => $definition) {
            $persona = $personas[$key] ?? null;

            if (! $persona instanceof User) {
                continue;
            }

            $profile = MemberAiProfile::updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $persona->id],
                [
                    'status' => 'published',
                    // La langue du tenant, pas celle du processus qui charge
                    // le pack (TASK-1388).
                    'locale' => $organization->locale,
                    'member_profile_summary' => $definition['summary'],
                    'generated_summary' => $definition['summary'],
                    'skills' => $definition['skills'],
                    // TASK-1395 : declare VIDE, et non omis.
                    //
                    // T1394 s'etait contentee de retirer la cle. C'etait
                    // suffisant pour une creation et INOPERANT sur un tenant
                    // deja provisionne : `updateOrCreate` ne met a jour que
                    // les champs qu'on lui PASSE, donc l'ancienne valeur
                    // survivait. Mesure apres rechargement reel :
                    // `["advice","review"]` etait toujours la, et la
                    // PersonCard fausse remontait encore.
                    //
                    // Retirer un champ du code n'efface pas la donnee. Le
                    // declarer vide, si.
                    'help_types' => [],
                    // TASK-1394 : pourquoi ce champ n'a AUCUNE valeur.
                    //
                    // Ma premiere version y ecrivait `['advice', 'review']`,
                    // a l'identique pour les quatre profils et sans aucune
                    // source dans le pack. Le matching lit ce champ comme un
                    // signal a part entiere : toute demande contenant
                    // « review » remontait donc les QUATRE personnes, dont
                    // celles dont aucune competence n'avait de rapport.
                    //
                    // Mesure en recette : une demande de relecture ethique
                    // remontait Sam — a juste titre, sur sa competence
                    // « Ethics review » — ET Elena, uniquement sur ce
                    // `help_types` invente.
                    //
                    // Le pack ne dit nulle part quel type d'aide chaque
                    // persona accepte. Un champ sans source ne doit pas
                    // exister : le declarer vide vaut mieux que le remplir
                    // d'une supposition qui pese sur les recommandations.
                ],
            );

            // `published_at` n'est pas `$fillable` — et c'est lui, pas le
            // `status`, qui decide qu'un profil est publie. Le poser par la
            // primitive prevue plutot qu'elargir `$fillable` pour un pack.
            if ($profile->published_at === null) {
                $profile->forceFill(['published_at' => $base, 'validated_at' => $base])->saveQuietly();
            }

            $registrar->track('ai_profile', $key, $profile);
        }
    }

    /**
     * @param  array<string, User>  $personas
     * @return array{0: array<string, Loop>, 1: array<string, Dossier>}
     */
    private function loops(Organization $organization, array $personas, ScenarioPackEntityRegistrar $registrar): array
    {
        $loops = [];
        $dossiers = [];

        foreach (self::LOOPS as $key => $definition) {
            $owner = $personas[$definition['owner']];

            // Idempotence : la primitive canonique cree TOUJOURS une Loop
            // (slug unique genere), donc le rejeu doit retrouver la sienne par
            // (Organization, nom) avant d'appeler le service — meme idiome que
            // le pack moderne existant.
            $loop = Loop::query()
                ->where('organization_id', $organization->id)
                ->where('name', $definition['name'])
                ->first();

            if ($loop === null) {
                $loop = $this->loops->createLoopForOrg(
                    user: $owner,
                    organizationId: $organization->id,
                    name: $definition['name'],
                    description: $definition['description'],
                    visibility: 'public',
                    tagline: $definition['tagline'],
                    accessMode: Loop::ACCESS_REQUEST,
                );
            }

            $registrar->track('loop', $key, $loop);

            // Le membre `owner` est cree par la primitive elle-meme : on
            // l'inscrit au registre pour qu'il soit purge avec le reste, dans
            // l'ordre inverse (membres avant Loop).
            $ownerMember = $loop->members()->where('user_id', $owner->id)->first();
            if ($ownerMember !== null) {
                $registrar->track('loop_member', "{$key}:{$definition['owner']}", $ownerMember);
            }

            foreach ($definition['members'] as $memberKey) {
                $member = $loop->members()->where('user_id', $personas[$memberKey]->id)->first()
                    ?? $this->loops->addMemberByUserId($loop, $personas[$memberKey]->id);

                $registrar->track('loop_member', "{$key}:{$memberKey}", $member);
            }

            // Dossier racine tenu par la Loop et nomme comme elle : c'est lui
            // qui portera le corpus documentaire de cette Boucle.
            $dossier = $this->rootDocuments->ensureRootDossier($loop);
            $registrar->track('folder', $key, $dossier);

            $loops[$key] = $loop;
            $dossiers[$key] = $dossier;
        }

        return [$loops, $dossiers];
    }

    /**
     * @return array<string, Category>
     */
    private function categories(Organization $organization, ScenarioPackEntityRegistrar $registrar): array
    {
        $categories = [];

        foreach (ArtSciLabEnglishDataset::categories() as $definition) {
            $category = Category::updateOrCreate(
                ['organization_id' => $organization->id, 'slug' => $definition['slug']],
                // Une Category porte DEUX libelles (B2C/B2B) selon le
                // `blog_naming` de l'Organization : les deux sont requis en
                // base, et un seul suffirait a faire echouer l'insertion.
                [
                    'name_b2c' => $definition['name'],
                    'name_b2b' => $definition['name'],
                    'color' => $definition['color'],
                ],
            );

            $registrar->track('category', $definition['key'], $category);
            $categories[$definition['key']] = $category;
        }

        return $categories;
    }

    /**
     * Le corpus : un fichier reel sur le disque `dossier_files`, pose dans le
     * Dossier racine de SA Boucle. Le contenu vit dans le code (donc dans git),
     * ce qui rend ce pack portable — contrairement a un corpus lu sur un
     * repertoire local.
     *
     * @param  array<string, Dossier>  $dossiers
     * @param  array<string, User>  $personas
     */
    private function corpus(Organization $organization, array $dossiers, array $personas, ScenarioPackEntityRegistrar $registrar): void
    {
        // L'uploader n'est jamais le proprietaire du Dossier : sur PostgreSQL,
        // une meme personne a la fois `owner_id` (cascade) et `uploaded_by`
        // (set null) de la meme ligne fait echouer la suppression. Les Dossiers
        // racines n'ont pas de proprietaire, mais on garde l'uploader stable et
        // explicite plutot que dependant de l'ordre du tableau.
        $uploader = $personas['elena'];

        foreach (ArtSciLabEnglishDataset::documents() as $document) {
            $dossier = $dossiers[$document['loop']];
            $path = self::ORGANIZATION_SLUG.'/'.$document['loop'].'/'.$document['name'];
            $body = $document['body'];

            $registrar->assertStoragePathAvailable('folder_file', $document['key'], self::DISK, $path);

            if (! Storage::disk(self::DISK)->put($path, $body)) {
                throw new RuntimeException("ArtSciLabEnglishPack : ecriture impossible pour {$path}.");
            }

            $file = DossierFile::withTrashed()->updateOrCreate(
                ['organization_id' => $organization->id, 'path' => $path],
                [
                    'dossier_id' => $dossier->id,
                    'uploaded_by' => $uploader->id,
                    'disk' => self::DISK,
                    'original_name' => $document['name'],
                    'display_name' => self::displayName($document['name']),
                    'mime_type' => str_ends_with($document['name'], '.md') ? 'text/markdown' : 'text/plain',
                    'size_bytes' => strlen($body),
                    'checksum_sha256' => hash('sha256', $body),
                    'source' => 'upload',
                ],
            );

            if ($file->trashed()) {
                $file->restore();
            }

            $registrar->track('folder_file', $document['key'], $file);
        }
    }

    /**
     * Allume les Cards que le dataset alimente.
     *
     * Le preset de type n'active ni les Decisions ni la Roadmap. Sans ce
     * passage, le pack ecrirait trois decisions et quatre elements de roadmap
     * que PERSONNE ne verrait a l'ecran : des donnees invisibles ne demontrent
     * rien, et une demo qui montre une Boucle vide alors que la base est pleine
     * est pire qu'une demo sans donnees.
     *
     * ADDITIF par construction : `enable()` est le seul ecrivain de
     * `loop_cards.enabled`, rien n'est eteint, et une Card deja active n'est
     * pas touchee. Les `loop_cards` ne sont pas inscrites au registre : elles
     * appartiennent a la Loop et disparaissent avec elle.
     *
     * @param  array<string, Loop>  $loops
     */
    private function showTheDataThePackWrites(array $loops): void
    {
        $needed = [
            'sonic_terrain' => ['core.decisions', 'core.roadmap'],
            'consent_ethics' => ['core.decisions'],
            'nsf_steam_bridge' => ['core.decisions', 'core.roadmap'],
        ];

        foreach ($needed as $loopKey => $cards) {
            foreach ($cards as $card) {
                $this->composition->enable($loops[$loopKey], $card);
            }
        }
    }

    /**
     * @param  array<string, Loop>  $loops
     * @param  array<string, User>  $personas
     */
    private function conversation(array $loops, array $personas, ScenarioPackEntityRegistrar $registrar, CarbonImmutable $base): void
    {
        foreach (ArtSciLabEnglishDataset::messages() as $spec) {
            $loop = $loops[$spec['loop']];

            $message = LoopMessage::query()
                ->where('loop_id', $loop->id)
                ->where('body', $spec['body'])
                ->first();

            if ($message === null) {
                $message = $this->messages->sendUserMessage($loop, $personas[$spec['sender']], $spec['body']);

                // Le service date au moment de l'envoi : un fil dont les vingt
                // messages tombent a la meme seconde ne se lit pas. La date
                // n'est pas `$fillable`, on la pose par la primitive prevue.
                $at = $base->addDays($spec['day'])->addMinutes(crc32($spec['key']) % 480);
                $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
            }

            $registrar->track('loop_message', $spec['key'], $message);
        }
    }

    /**
     * @param  array<string, Loop>  $loops
     * @param  array<string, User>  $personas
     */
    private function poll(array $loops, array $personas, ScenarioPackEntityRegistrar $registrar): void
    {
        $spec = ArtSciLabEnglishDataset::poll();
        $loop = $loops[$spec['loop']];

        $poll = LoopPoll::query()
            ->where('loop_id', $loop->id)
            ->where('question', $spec['question'])
            ->first();

        if ($poll === null) {
            $poll = $this->polls->create(
                $personas[$spec['author']],
                $loop,
                $spec['question'],
                $spec['description'],
                $spec['selection_type'],
                $spec['labels'],
            );

            // `create()` rend un `fresh()` : l'instance relue a perdu
            // `wasRecentlyCreated`, et le registre inscrirait ce sondage comme
            // `reused` — donc jamais purge. On sait, dans cette branche, que ce
            // passage vient de le creer.
            $poll->wasRecentlyCreated = true;
        }

        $poll->loadMissing('options');
        $registrar->track('loop_poll', $spec['key'], $poll);

        foreach ($spec['votes'] as $voterKey => $label) {
            $option = $poll->options->firstWhere('label', $label);

            if ($option === null) {
                throw new RuntimeException("ArtSciLabEnglishPack : option '{$label}' introuvable pour le sondage.");
            }

            if ($this->polls->voteOf($personas[$voterKey], $poll) === null) {
                $this->polls->vote($personas[$voterKey], $poll, $loop, [$option->id]);
            }
        }
    }

    /**
     * @param  array<string, Loop>  $loops
     * @param  array<string, User>  $personas
     */
    private function decisions(array $loops, array $personas, ScenarioPackEntityRegistrar $registrar, CarbonImmutable $base): void
    {
        foreach (ArtSciLabEnglishDataset::decisions() as $spec) {
            $loop = $loops[$spec['loop']];

            $decision = LoopDecision::query()
                ->where('loop_id', $loop->id)
                ->where('title', $spec['title'])
                ->first();

            if ($decision === null) {
                $decision = $this->decisions->record(
                    $loop,
                    $personas[$spec['author']],
                    $spec['title'],
                    $spec['rationale'],
                    $base->addDays($spec['day'])->toDateString(),
                );
            }

            $registrar->track('loop_decision', $spec['key'], $decision);
        }
    }

    /**
     * @param  array<string, Loop>  $loops
     * @param  array<string, User>  $personas
     */
    private function roadmap(array $loops, array $personas, ScenarioPackEntityRegistrar $registrar): void
    {
        foreach (ArtSciLabEnglishDataset::roadmapItems() as $spec) {
            $loop = $loops[$spec['loop']];

            $item = LoopRoadmapItem::query()
                ->where('loop_id', $loop->id)
                ->where('title', $spec['title'])
                ->first();

            if ($item === null) {
                $maxPosition = LoopRoadmapItem::query()
                    ->where('organization_id', $loop->organization_id)
                    ->where('loop_id', $loop->id)
                    ->where('status', LoopRoadmapItem::STATUS_TODO)
                    ->max('position');

                $item = LoopRoadmapItem::create([
                    'organization_id' => $loop->organization_id,
                    'loop_id' => $loop->id,
                    'title' => $spec['title'],
                    'status' => LoopRoadmapItem::STATUS_TODO,
                    'position' => $maxPosition === null ? 0 : $maxPosition + 1,
                    'created_by' => $personas[$spec['author']]->id,
                    'completed_at' => null,
                ]);
            }

            $registrar->track('loop_roadmap_item', $spec['key'], $item);
        }
    }

    /**
     * @param  array<string, Loop>  $loops
     * @param  array<string, User>  $personas
     */
    private function futureEvent(array $loops, array $personas, ScenarioPackEntityRegistrar $registrar): void
    {
        $spec = ArtSciLabEnglishDataset::event();
        $loop = $loops[$spec['loop']];

        $event = LoopEvent::query()
            ->where('loop_id', $loop->id)
            ->where('title', $spec['title'])
            ->first();

        if ($event === null) {
            // Date calculee au chargement : un evenement fige dans le code
            // deviendrait passe, et une demo dont le seul evenement est passe
            // se demode toute seule.
            $starts = now()->addDays($spec['in_days'])->setTime(18, 30);

            $event = $this->events->create($personas[$spec['author']], $loop, [
                'title' => $spec['title'],
                'description' => $spec['description'],
                'format' => $spec['format'],
                'location' => $spec['location'],
                'timezone' => 'America/Chicago',
                'starts_at' => $starts->format('Y-m-d H:i'),
                'ends_at' => $starts->copy()->addMinutes(90)->format('Y-m-d H:i'),
            ]);
        }

        $registrar->track('loop_event', $spec['key'], $event);
    }

    /**
     * Deux demandes et deux offres : le minimum pour que les deux parcours
     * existent reellement, sans remplir la marketplace de lignes que personne
     * ne lira.
     *
     * @param  array<string, User>  $personas
     * @param  array<string, Category>  $categories
     */
    private function marketplace(Organization $organization, array $personas, array $categories, ScenarioPackEntityRegistrar $registrar): void
    {
        foreach (ArtSciLabEnglishDataset::requests() as $spec) {
            $request = ServiceRequest::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organization->id, 'title' => $spec['title']],
                [
                    'user_id' => $personas[$spec['author']]->id,
                    'description' => $spec['description'],
                    'category_id' => $categories['research-practice']->id,
                    'delivery_mode' => 'remote',
                    'budget_min' => $spec['budget_min'],
                    'budget_max' => $spec['budget_max'],
                    'deadline' => now()->addDays($spec['in_days'])->toDateString(),
                    'status' => 'open',
                ],
            );

            $registrar->track('marketplace_request', $spec['key'], $request);
        }

        foreach (ArtSciLabEnglishDataset::offers() as $spec) {
            $service = Service::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organization->id, 'title' => $spec['title']],
                [
                    'user_id' => $personas[$spec['author']]->id,
                    'description' => $spec['description'],
                    'category_id' => $categories['public-programmes']->id,
                    'delivery_mode' => 'remote',
                    'points_cost' => $spec['points_cost'],
                    'status' => 'active',
                ],
            );

            if ($service->trashed()) {
                $service->restore();
            }

            $registrar->track('marketplace_service', $spec['key'], $service);
        }
    }

    private static function displayName(string $fileName): string
    {
        $base = preg_replace('/\.(md|txt)$/', '', $fileName) ?? $fileName;

        return ucfirst(str_replace('-', ' ', $base));
    }

    /**
     * La configuration IA NON SECRETE de l'Organization.
     *
     * Arbitrage MASTER du 2026-09-01 : un scenario pack peut reproduire des
     * donnees et une configuration, jamais un credential. La cle API n'est donc
     * PAS ecrite ici — ni lue depuis l'environnement, ni copiee depuis une
     * autre Organization, ni fabriquee. Le pack pose tout ce qui n'est pas
     * secret ; il reste exactement un geste humain, dans l'ecran SuperAdmin :
     * coller la cle.
     *
     * Sans cette ligne, l'ecran n'affiche rien a completer et le manque est
     * invisible : c'est cette invisibilite que le pack supprime. Avec elle et
     * sans cle, le Shell degrade honnetement — comportement deja verifie.
     *
     * `updateOrCreate` sans `api_key` dans la charge utile : une cle deja posee
     * par un humain n'est jamais ecrasee par un rechargement.
     */
    private function aiSettings(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        $settings = OrganizationAiSetting::updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'provider' => 'openrouter',
                'model' => 'openai/gpt-4o-mini',
                'monthly_budget_usd' => 5.00,
                'is_enabled' => true,
                'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_PLATFORM,
            ],
        );

        $registrar->track('organization_ai_setting', 'demo', $settings);
    }

    private function doctrine(Organization $organization, User $author, ScenarioPackEntityRegistrar $registrar): void
    {
        // `activate()` est la SEULE primitive d'ecriture de la doctrine et
        // supersede la precedente : la rejouer a l'identique creerait une
        // nouvelle version a chaque chargement, et le registre pointerait
        // ensuite une version « superseded ». On ne reecrit donc que si le
        // texte actif differe, et on inscrit la version active dans les deux
        // cas — sans quoi le retrait laisserait la doctrine derriere lui dans
        // une Organization que le pack n'a pas creee.
        $active = OrganizationAiDoctrine::activeFor($organization->id);

        $doctrine = ($active !== null && trim($active->body) === trim(self::DOCTRINE))
            ? $active
            : OrganizationAiDoctrine::activate($organization, self::DOCTRINE, $author);

        $registrar->track('organization_doctrine', 'en', $doctrine);
    }
}
