<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\User;
use App\Support\ScenarioManager\Capture\ScenarioCaptureService;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManager\ScenarioVersionRefused;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * TASK-1652 — le Capture Engine.
 *
 * Le critere canonique est un ALLER-RETOUR :
 * `Load -> activite declarable -> Capture -> Validator -> re-Load`.
 *
 * Ce fichier ne verifie donc pas que « la capture rend un JSON ». Il verifie
 * qu'elle rend le MEME MONDE, et qu'elle refuse plutot que de mentir.
 */
class ScenarioCaptureEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        $this->superAdmin = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);
    }

    public function test_capturer_un_monde_NON_MODIFIE_rend_un_Manifest_VALIDE(): void
    {
        $version = $this->versionChargee();

        $capturee = app(ScenarioCaptureService::class)->capturer($version, $this->superAdmin);

        // Le Validator COMPLET, sur le texte exact persiste.
        $verdict = app(ScenarioManifestValidator::class)->validate((string) $capturee->json_source);

        $this->assertTrue($verdict->isValid(), 'Manifest capture invalide : '.json_encode($verdict->toArray()['errors'] ?? []));
    }

    public function test_la_version_capturee_est_un_DRAFT_sans_approbation_ni_chargement(): void
    {
        $version = $this->versionChargee();
        $capturee = app(ScenarioCaptureService::class)->capturer($version, $this->superAdmin);

        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $capturee->state);
        $this->assertSame(ScenarioManifestVersion::ORIGIN_CAPTURE, $capturee->origin);
        $this->assertSame($version->id, $capturee->parent_id);
        // `assertNotNull` etait TAUTOLOGIQUE : poser l'Organization du
        // SuperAdmin — une Organization CLIENTE — l'aurait satisfaite.
        $this->assertSame($this->sandboxDe($version)->id, $capturee->captured_from_organization_id);

        // Et le document DIT son propre numero : une capture qui garderait
        // celui de sa source porterait deux verites, dont une fausse.
        $this->assertSame(
            $capturee->version,
            json_decode((string) $capturee->json_source, true)['version']
        );

        // Une Capture est une MESURE, jamais une approbation humaine. Recopier
        // l'approbation rendrait chargeable un monde que personne n'a relu.
        $this->assertNull($capturee->approved_digest);
        $this->assertNull($capturee->approved_by);
        $this->assertNull($capturee->approved_at);

        // Et le chargement appartient a la version SOURCE.
        $this->assertNull($capturee->scenario_pack_load_id);
        $this->assertFalse($capturee->isLoaded());
    }

    public function test_la_version_SOURCE_est_strictement_inchangee(): void
    {
        $version = $this->versionChargee();

        // On compare des SCALAIRES : deux instances Carbon egales ne sont pas
        // identiques, et `assertSame` sur des objets compare l'identite.
        $empreinte = static fn (ScenarioManifestVersion $v): array => [
            'json_source' => (string) $v->json_source,
            'state' => (string) $v->state,
            'version' => (string) $v->version,
            'digest' => (string) $v->digest,
            'approved_digest' => (string) $v->approved_digest,
            'approved_by' => (string) $v->approved_by,
            'approved_at' => (string) $v->approved_at,
            'scenario_pack_load_id' => (string) $v->scenario_pack_load_id,
        ];

        $avant = $empreinte($version->fresh());

        app(ScenarioCaptureService::class)->capturer($version, $this->superAdmin);

        $this->assertSame($avant, $empreinte($version->fresh()));
        // Et la source reste CHARGEE : la Capture ne lui prend pas son monde.
        $this->assertTrue($version->fresh()->isLoaded());
    }

    public function test_aucun_UUID_ni_donnee_systeme_dans_le_Manifest_capture(): void
    {
        $version = $this->versionChargee();
        $capturee = app(ScenarioCaptureService::class)->capturer($version, $this->superAdmin);
        $json = (string) $capturee->json_source;

        // Aucun UUID : les identites du Manifest sont des stable keys lisibles.
        $this->assertSame(0, preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $json),
            'Un UUID de base figure dans le Manifest capture.');

        // Aucun rouage interne.
        foreach (['scenario_pack', 'embedding', 'wizard_state', 'generated_summary', 'password', 'checksum', 'disk', 'is_admin'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $json, "« {$interdit} » n a rien a faire dans un Manifest.");
        }
    }

    // =====================================================================
    // Fidelite : le MEME monde, pas seulement un JSON valide
    // =====================================================================

    public function test_une_capture_IMMEDIATE_reproduit_les_offsets_de_la_source(): void
    {
        // Le critere de l'ancre. `CAPTURE_TIME_ANCHOR = source load anchor` :
        // ancrer a l'instant de la Capture preserverait les ecarts mais
        // rendrait ce test faux. C'est ce qui prouve le choix.
        $version = $this->versionChargee();
        $source = json_decode((string) $version->json_source, true);

        // LE TEMPS AVANCE entre le Load et la Capture — sans quoi ce test ne
        // discrimine RIEN.
        //
        // Trouve en sabotant : remplacer l'ancre par `new DateTimeImmutable()`
        // laissait le test VERT, parce que dans un test le chargement et la
        // capture tombent dans la meme seconde et que `now()` valait donc
        // `loaded_at`. Un test qui ne separe pas les deux ancres ne prouve pas
        // laquelle est utilisee.
        $this->travel(3)->days();

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        $attendus = array_map(static fn (array $m): int => $m['offset_minutes'], $source['messages']);
        $obtenus = array_map(static fn (array $m): int => $m['offset_minutes'], $document['messages']);

        sort($attendus);
        sort($obtenus);

        $this->assertSame($attendus, $obtenus, 'Une capture immediate doit rendre EXACTEMENT les offsets du Manifest source.');
    }

    public function test_le_monde_declarable_est_capture_EN_ENTIER(): void
    {
        // Chaque famille de la fixture doit se retrouver. Une famille tombee a
        // zero serait une perte SILENCIEUSE — c'est exactement ce que le scope
        // tenant produit sur `services` et `service_requests` si on le laisse
        // faire.
        $version = $this->versionChargee();
        $source = json_decode((string) $version->json_source, true);
        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        foreach (['users', 'loops', 'memberships', 'dossiers', 'articles', 'files', 'messages',
            'categories', 'skills', 'service_requests', 'services', 'polls', 'events',
            'decisions', 'roadmap_items'] as $famille) {
            $this->assertCount(
                count($source[$famille]),
                $document[$famille],
                "La famille « {$famille} » n est pas capturee en entier."
            );
        }

        foreach (['modules', 'sequences', 'progress', 'assignments', 'submissions'] as $sous) {
            $this->assertCount(
                count($source['training'][$sous]),
                $document['training'][$sous],
                "La famille « training.{$sous} » n est pas capturee en entier."
            );
        }
    }

    public function test_les_stable_keys_SOURCE_sont_conservees(): void
    {
        $version = $this->versionChargee();
        $source = json_decode((string) $version->json_source, true);
        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        foreach (['users', 'loops', 'dossiers', 'messages', 'polls', 'events', 'decisions'] as $famille) {
            $attendues = array_column($source[$famille], 'key');
            $obtenues = array_column($document[$famille], 'key');
            sort($attendues);
            sort($obtenues);

            $this->assertSame($attendues, $obtenues, "Les clefs de « {$famille} » ont change a la Capture.");
        }
    }

    public function test_le_contenu_textuel_est_STRICTEMENT_preserve(): void
    {
        $version = $this->versionChargee();
        $source = json_decode((string) $version->json_source, true);
        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        $corpsSource = array_column($source['messages'], 'body', 'key');
        $corpsCapture = array_column($document['messages'], 'body', 'key');

        foreach ($corpsSource as $clef => $corps) {
            $this->assertSame($corps, $corpsCapture[$clef] ?? null, "Le corps du message « {$clef} » a change.");
        }

        // Et le format SOURCE est conserve, jamais normalise.
        $formatsSource = array_column($source['messages'], 'format', 'key');
        $formatsCapture = array_column($document['messages'], 'format', 'key');

        foreach ($formatsSource as $clef => $format) {
            $this->assertSame($format, $formatsCapture[$clef] ?? null, "Le format du message « {$clef} » a ete normalise.");
        }
    }

    // =====================================================================
    // Les conventions ARBITREES
    // =====================================================================

    public function test_un_message_humain_NOUVEAU_est_capture_en_plain(): void
    {
        // `NEW_CHATLOOP_MESSAGE_FORMAT = plain`, convention NORMATIVE. Le corps
        // contient des marqueurs Markdown A DESSEIN : la regle ne doit PAS les
        // regarder.
        $version = $this->versionChargee();
        [$loop, $auteur] = $this->uneBoucleEtUnMembre($version);

        $corps = "# Un titre\n\n*une emphase* et [un lien](https://exemple.test)";

        \App\Models\LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $auteur->id,
            'organization_id' => $loop->organization_id,
            'body' => $corps,
            'type' => 'user',
        ]);

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;
        $nouveau = collect($document['messages'])->firstWhere('body', $corps);

        $this->assertNotNull($nouveau, 'Le message humain nouveau doit etre capture.');
        $this->assertSame('plain', $nouveau['format']);
        $this->assertSame($corps, $nouveau['body'], 'Le contenu doit rester STRICTEMENT identique.');
    }

    public function test_un_message_IA_ou_SUPPRIME_n_est_jamais_capture(): void
    {
        $version = $this->versionChargee();
        [$loop, $auteur] = $this->uneBoucleEtUnMembre($version);

        foreach ([['ai', null], ['ai_agent', null], ['user', now()]] as [$type, $supprime]) {
            \App\Models\LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $auteur->id,
                'organization_id' => $loop->organization_id,
                'body' => 'sortie '.$type.($supprime ? ' supprimee' : ''),
                'type' => $type,
                'deleted_at' => $supprime,
            ]);
        }

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;
        $corps = array_column($document['messages'], 'body');

        foreach ($corps as $texte) {
            $this->assertStringNotContainsString('sortie ai', $texte);
            $this->assertStringNotContainsString('supprimee', $texte);
        }

        // L'autre sens : la fixture a bien 4 messages humains, donc le filtre
        // ne rejette pas tout.
        $this->assertCount(4, $document['messages']);
    }

    public function test_un_article_NOUVEAU_est_capture_en_html(): void
    {
        // `NEW_BLOGPOST_FORMAT = html`. Ce n'est pas une convenance : les trois
        // voies produit qui creent un BlogPost de Dossier ecrivent du HTML
        // (`'<p></p>'`, `LoopRootDocumentService::initialContent()`), et la
        // surface d'edition est `<x-blog-editor>` qui emet `getHTML()`. Le
        // contenu n'est jamais inspecte pour trancher — celui-ci porte
        // d'ailleurs des marqueurs Markdown a dessein.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);
        [$loop, $auteur] = $this->uneBoucleEtUnMembre($version);

        $dossier = \App\Models\Dossier::query()->withoutGlobalScopes()
            ->where('organization_id', $sandbox->id)->whereNull('deleted_at')
            ->where('loop_id', $loop->id)->firstOrFail();

        $contenu = "# Pas un titre HTML\n\n*emphase Markdown*";

        $post = \App\Models\BlogPost::query()->create([
            'organization_id' => $sandbox->id,
            'user_id' => $auteur->id,
            'title' => 'Article ne de l activite',
            'slug' => 'article-activite-'.uniqid(),
            'content' => $contenu,
            'status' => 'published',
            'audience' => \App\Models\BlogPost::AUDIENCE_LOOP,
            'published_at' => now(),
        ]);

        \App\Models\DossierBlogPost::query()->create([
            'organization_id' => $sandbox->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
        ]);

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;
        $nouveau = collect($document['articles'])->firstWhere('title', 'Article ne de l activite');

        $this->assertNotNull($nouveau, 'L article nouveau doit etre capture.');
        $this->assertSame('html', $nouveau['format']);
        $this->assertSame($contenu, $nouveau['content'], 'Le contenu doit rester STRICTEMENT identique.');

        // Et l'article SOURCE garde SON format declare, jamais normalise.
        $source = json_decode((string) $version->json_source, true);
        $formatSource = $source['articles'][0]['format'];
        $articleSource = collect($document['articles'])->firstWhere('key', $source['articles'][0]['key']);
        $this->assertSame($formatSource, $articleSource['format']);
    }

    // =====================================================================
    // Tenant : la frontiere
    // =====================================================================

    public function test_aucune_donnee_d_une_AUTRE_Organization_n_entre_dans_la_Capture(): void
    {
        $version = $this->versionChargee();

        // Une Organization VOISINE, avec du contenu des memes familles — dont
        // celles que `BelongsToOrganizationScope` gouverne.
        $voisine = Organization::factory()->create(['name' => 'Voisine reelle']);
        $intrus = User::factory()->create(['organization_id' => $voisine->id, 'name' => 'INTRUS Voisin']);
        $categorie = \App\Models\Category::query()->create([
            'organization_id' => $voisine->id, 'name_b2c' => 'INTRUS categorie',
            'name_b2b' => 'INTRUS categorie', 'slug' => 'intrus-cat-'.uniqid(), 'color' => '#ff0000',
        ]);
        \App\Models\Service::query()->create([
            'organization_id' => $voisine->id, 'user_id' => $intrus->id, 'title' => 'INTRUS service',
            'description' => 'Ne doit jamais sortir.', 'category_id' => $categorie->id,
            'delivery_mode' => 'remote', 'points_cost' => 10, 'status' => 'active',
        ]);
        \App\Models\ServiceRequest::query()->create([
            'organization_id' => $voisine->id, 'user_id' => $intrus->id, 'title' => 'INTRUS demande',
            'description' => 'Ne doit jamais sortir.', 'category_id' => $categorie->id,
            'delivery_mode' => 'remote', 'budget_min' => 1, 'status' => 'open',
        ]);

        // On lie CETTE Organization au conteneur : c'est exactement ce que
        // `ResolveUrlOrganization` fait sur une requete HTTP, et ce qui ferait
        // exporter ses lignes si le scope tenant n'etait pas neutralise.
        app()->instance('current_organization', $voisine);

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;
        $json = json_encode($document, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('INTRUS', (string) $json, 'Une ligne d une autre Organization est entree dans la Capture.');

        // Et l'autre sens, celui qui compte autant : le contenu de la sandbox
        // est bien la, malgre l'Organization courante etrangere. Un snapshot
        // VIDE serait une troncature silencieuse.
        $this->assertNotSame([], $document['services']);
        $this->assertNotSame([], $document['service_requests']);
        $this->assertCount(22, $document['users']);
    }

    public function test_un_objet_SOFT_DELETED_n_est_pas_capture(): void
    {
        $version = $this->versionChargee();
        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;
        $avant = count($document['services']);

        $this->assertGreaterThan(0, $avant, 'Le cas ne vaut que si la sandbox a des services.');

        \App\Models\Service::query()->withoutGlobalScopes()
            ->where('organization_id', $this->sandboxDe($version)->id)->first()->delete();

        $apres = app(ScenarioCaptureService::class)->inspecter($version)->document;

        $this->assertCount($avant - 1, $apres['services'], 'Un service en corbeille ne doit pas etre capture.');
    }

    public function test_une_personne_REELLE_bloque_la_Capture(): void
    {
        // Pas d'anonymisation silencieuse : une personne dont l'email n'est pas
        // `.test` et qui ne vient pas du Manifest source ne peut pas etre
        // exportee — la Capture le DIT.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        User::factory()->create([
            'organization_id' => $sandbox->id,
            'email' => 'personne.reelle@gmail.com',
            'name' => 'Personne Reelle',
        ]);

        $resultat = app(ScenarioCaptureService::class)->inspecter($version);

        $this->assertTrue($resultat->estBloquee());
        $this->assertStringContainsString('real_user', $resultat->rapport());

        // Et aucune version n'est creee.
        $avant = ScenarioManifestVersion::query()->count();

        try {
            app(ScenarioCaptureService::class)->capturer($version, $this->superAdmin);
            $this->fail('La Capture aurait du etre refusee.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::CAPTURE_BLOCKED, $refus->reason);
        }

        $this->assertSame($avant, ScenarioManifestVersion::query()->count());
        $this->assertSame(0, \App\Models\ScenarioCaptureKey::query()->count(), 'Aucun registre partiel.');
    }

    // =====================================================================
    // Provenance : la porte
    // =====================================================================

    public function test_capturer_une_version_NON_CHARGEE_est_refuse(): void
    {
        $version = $this->versionChargee();
        $version->forceFill(['scenario_pack_load_id' => null])->save();

        $this->attendreRefus(ScenarioVersionRefused::NOT_LOADED, fn () => app(ScenarioCaptureService::class)->capturer($version->fresh(), $this->superAdmin));
    }

    public function test_capturer_une_Organization_CLIENTE_est_impossible(): void
    {
        // Il n'existe aucun chemin « Organization cliente -> l'adopter ->
        // capturer ». On force la ligne de chargement a designer une
        // Organization reelle : la garde doit tenir.
        $version = $this->versionChargee();
        $load = \App\Models\ScenarioPackLoad::query()->findOrFail($version->scenario_pack_load_id);
        $load->forceFill(['organization_id' => $this->superAdmin->organization_id])->save();

        $this->attendreRefus(ScenarioVersionRefused::NOT_A_SANDBOX, fn () => app(ScenarioCaptureService::class)->capturer($version->fresh(), $this->superAdmin));
    }

    // =====================================================================
    // Les defauts trouves par les deux relectures adverses
    // =====================================================================

    public function test_deux_fils_ENTRELACES_gardent_un_ordre_coherent_avec_le_temps(): void
    {
        // L'invariant `assertMessageTimeline` exige que trier par `order` rende
        // des offsets NON DECROISSANTS. Un parcours d'arbre le violait des que
        // deux fils s'entrelacaient — le comportement normal de ChatLoop.
        $version = $this->versionChargee();
        [$loop, $auteur] = $this->uneBoucleEtUnMembre($version);

        $racineA = $this->message($loop, $auteur, 'Racine A', null, 10);
        $this->message($loop, $auteur, 'Racine B', null, 20);
        $this->message($loop, $auteur, 'Reponse a A', $racineA, 30);

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        $parBoucle = [];

        foreach ($document['messages'] as $message) {
            $parBoucle[$message['loop']][] = $message;
        }

        foreach ($parBoucle as $boucle => $messages) {
            usort($messages, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            $precedent = null;

            foreach ($messages as $message) {
                if ($precedent !== null) {
                    $this->assertGreaterThanOrEqual(
                        $precedent, $message['offset_minutes'],
                        "Dans « {$boucle} », trier par order doit rendre des offsets non decroissants."
                    );
                }
                $precedent = $message['offset_minutes'];
            }
        }

        // Et le fil de discussion n'est pas perdu au passage.
        $reponse = collect($document['messages'])->firstWhere('body', 'Reponse a A');
        $racine = collect($document['messages'])->firstWhere('body', 'Racine A');
        $this->assertSame($racine['key'], $reponse['reply_to']);
    }

    public function test_une_sequence_ARCHIVEE_dont_la_progression_survit_rend_un_blocker_NOMME(): void
    {
        // `CourseMaterialService::deleteSequence()` ARCHIVE la sequence et
        // CONSERVE les progressions quand quelqu un a commence — c est la
        // regle explicite du produit. La progression designait alors une
        // sequence absente du document : reference pendante, et toute la
        // sandbox devenait non capturable avec un message opaque.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $sequence = \App\Models\CourseSequence::query()->withoutGlobalScopes()
            ->where('organization_id', $sandbox->id)->firstOrFail();
        $sequence->forceFill(['archived_at' => now()])->save();

        $resultat = app(ScenarioCaptureService::class)->inspecter($version);

        $this->assertTrue($resultat->estBloquee());
        $this->assertStringContainsString('reference_non_capturable', $resultat->rapport());
        // Le rapport NOMME la famille, il ne rend pas un blob d erreurs.
        $this->assertStringContainsString('training.sequences', $resultat->rapport());
    }

    public function test_un_domaine_HORS_V1_rend_un_blocker_NOMME_et_non_un_blob(): void
    {
        // Le domaine produit est plus large que Manifest V1 : `loop_types`
        // declare sept types dont `writing`, absent de l enum V1. Creer une
        // Boucle d ecriture dans la sandbox est UN CLIC, et cela rendait la
        // Capture impossible avec un message que personne ne pouvait
        // actionner.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);
        [, $auteur] = $this->uneBoucleEtUnMembre($version);

        $boucle = \App\Models\Loop::query()->create([
            'organization_id' => $sandbox->id,
            'name' => 'Atelier d ecriture',
            'slug' => 'ecriture-'.uniqid(),
            'description' => 'Un type que le produit connait et que V1 ignore.',
            'type' => 'writing',
            'visibility' => 'private', 'access_mode' => 'invitation',
            'created_by' => $auteur->id,
        ]);
        \App\Models\LoopMember::query()->create([
            'organization_id' => $sandbox->id, 'loop_id' => $boucle->id,
            'user_id' => $auteur->id, 'role' => 'owner', 'status' => 'active',
        ]);
        $racine = app(\App\Services\Loops\LoopRootDocumentService::class);
        $racine->ensureRootDossier($boucle);
        $racine->ensureRootDocument($boucle, $auteur);

        $resultat = app(ScenarioCaptureService::class)->inspecter($version);

        $this->assertTrue($resultat->estBloquee());
        // La famille est NOMMEE, et le chemin exact est donne.
        $this->assertStringContainsString('[loops]', $resultat->rapport());
        $this->assertStringContainsString('/loops/', $resultat->rapport());
    }

    public function test_un_Dossier_SANS_Boucle_est_capture(): void
    {
        // `dossiers[].loop` est NULLABLE en V1 et `applyDossiers()` materialise
        // bien des Dossiers sans Boucle. Les ecarter les faisait disparaitre du
        // monde declarable, en silence.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);
        [, $auteur] = $this->uneBoucleEtUnMembre($version);

        \App\Models\Dossier::query()->create([
            'organization_id' => $sandbox->id,
            'owner_id' => $auteur->id,
            'name' => 'Dossier d Organization',
            'visibility' => 'organization',
        ]);

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;
        $sansBoucle = collect($document['dossiers'])->firstWhere('name', 'Dossier d Organization');

        $this->assertNotNull($sansBoucle, 'Un Dossier sans Boucle doit etre capture.');
        $this->assertNull($sansBoucle['loop']);
        // Sa visibilite DECLAREE est conservee : seule une vraie racine est
        // forcee a `loop`.
        $this->assertSame('organization', $sansBoucle['visibility']);
        $this->assertNull($sansBoucle['root_document']);
    }

    public function test_une_personne_qui_a_QUITTE_une_Boucle_n_y_revient_pas(): void
    {
        // `removeMember()` et `leave()` ne suppriment pas la ligne : ils posent
        // `status = 'left'`. Sans filtre, la personne etait re-declaree membre,
        // et `applyMemberships()` la reinscrivait `active` au re-Load.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $membre = \App\Models\LoopMember::query()
            ->where('organization_id', $sandbox->id)->where('role', 'member')->firstOrFail();
        $membre->forceFill(['status' => 'left'])->save();

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        // La fixture declare 44 participations ; une seule a ete quittee.
        $this->assertCount(43, $document['memberships'], 'La participation quittee ne doit plus etre declaree.');
    }

    public function test_les_competences_d_une_Offre_viennent_du_PIVOT(): void
    {
        // Elles vivent dans `service_skill`, et l applier y ecrit. Les relire
        // depuis le document source rendait `skills: []` pour toute Offre nee
        // dans la sandbox, et figeait les autres.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $service = \App\Models\Service::query()->withoutGlobalScope(\App\Models\Scopes\BelongsToOrganizationScope::class)
            ->where('organization_id', $sandbox->id)->firstOrFail();

        $avant = collect(app(ScenarioCaptureService::class)->inspecter($version)->document['services'])
            ->firstWhere('title', $service->title)['skills'];
        $this->assertNotSame([], $avant, 'Le cas ne vaut que si l Offre a deja des competences.');

        // On en RETIRE une au runtime.
        \Illuminate\Support\Facades\DB::table('service_skill')
            ->where('service_id', $service->id)->limit(1)->delete();

        $apres = collect(app(ScenarioCaptureService::class)->inspecter($version)->document['services'])
            ->firstWhere('title', $service->title)['skills'];

        $this->assertCount(count($avant) - 1, $apres, 'La Capture doit suivre le pivot, pas le document source.');
    }

    public function test_le_document_racine_est_declare_en_html(): void
    {
        // Aucun applier n ecrit jamais `root_document` : il est INTEGRALEMENT
        // le gabarit produit, pose par `initialContent()`, qui rend du HTML.
        // Lui coller le format declare par la source etiquetait du HTML en
        // « markdown ».
        $version = $this->versionChargee();
        $source = json_decode((string) $version->json_source, true);

        $formatsSource = array_column($source['dossiers'], 'root_document');
        $this->assertSame('markdown', $formatsSource[0]['format'], 'La fixture doit declarer markdown pour que le cas vaille.');

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        foreach ($document['dossiers'] as $dossier) {
            if ($dossier['root_document'] !== null) {
                $this->assertSame('html', $dossier['root_document']['format']);
                $this->assertStringContainsString('<p>', $dossier['root_document']['content']);
            }
        }
    }

    public function test_une_personne_REELLE_bloque_meme_si_sa_clef_vient_de_la_SOURCE(): void
    {
        // Le chemin reel : `AdminController::updateUser()` laisse volontairement
        // modifier un compte de sandbox SUR PLACE tant que son Organization ne
        // change pas. Editer `student-01` en y mettant un vrai email, un vrai
        // nom et une vraie bio, puis capturer, exportait l identite REELLE sous
        // l email fictif de la source — et le Validator passait.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $persona = User::query()->where('organization_id', $sandbox->id)
            ->where('email', 'like', 'student-01@%')->firstOrFail();

        $persona->forceFill([
            'email' => 'cyril.reel@gmail.com',
            'name' => 'Nom Reel',
            'bio' => 'Une biographie reelle.',
        ])->save();

        $resultat = app(ScenarioCaptureService::class)->inspecter($version);

        $this->assertTrue($resultat->estBloquee(), 'Un persona source dont l email est devenu reel doit bloquer.');
        $this->assertStringContainsString('real_user', $resultat->rapport());

        // Et rien de l identite reelle ne sort.
        $json = json_encode($resultat->document, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Nom Reel', (string) $json);
        $this->assertStringNotContainsString('Une biographie reelle.', (string) $json);
    }

    public function test_organization_role_vient_de_admin_id_et_JAMAIS_de_is_admin(): void
    {
        // `is_admin` est le predicat PLATEFORME. Le seul test qui le gardait
        // cherchait la CHAINE « is_admin » dans le JSON : il prouvait l absence
        // d un nom de clef, pas l absence du FAIT. Substituer `$user->is_admin`
        // a `admin_id` laissait toute la suite verte.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;
        $admins = array_values(array_filter($document['users'], static fn (array $u): bool => $u['organization_role'] === 'admin'));

        // Le sens POSITIF : le responsable declare ressort bien `admin`.
        $this->assertCount(1, $admins, 'La sandbox a exactement un responsable.');
        $responsable = User::query()->findOrFail($sandbox->admin_id);
        $this->assertSame($responsable->first_name, $admins[0]['first_name']);

        // Le sens NEGATIF : poser `is_admin` sur un AUTRE compte ne le promeut
        // pas dans le Manifest.
        $autre = User::query()->where('organization_id', $sandbox->id)
            ->whereKeyNot($sandbox->admin_id)->firstOrFail();
        $autre->forceFill(['is_admin' => true])->save();

        $apres = app(ScenarioCaptureService::class)->inspecter($version)->document;
        $adminsApres = array_values(array_filter($apres['users'], static fn (array $u): bool => $u['organization_role'] === 'admin'));

        $this->assertCount(1, $adminsApres);
        $this->assertSame($admins[0]['key'], $adminsApres[0]['key'], 'Un privilege PLATEFORME ne fait pas un responsable d Organization.');
    }

    public function test_apres_un_RESET_la_Capture_refuse_avec_la_VRAIE_raison(): void
    {
        // `reset_at` n ETEINT rien : la migration T1646 le dit, et le resolveur
        // canonique ne le regarde pas. En faire un predicat de vivacite rendait
        // `notLoaded()` — une phrase fausse — apres un geste ordinaire.
        //
        // Mais capturer apres un Reset produirait des offsets decales, car
        // `ScenarioPackResetter` ne met pas `loaded_at` a jour alors que le
        // monde est reconstruit avec un instant frais. On refuse donc, avec la
        // vraie raison et en disant quoi faire.
        $version = $this->versionChargee();
        app(ScenarioLifecycleService::class)->reset($version->fresh(), $this->superAdmin);

        // La version reste CHARGEE : c est bien le point.
        $this->assertTrue($version->fresh()->isLoaded());

        try {
            app(ScenarioCaptureService::class)->capturer($version->fresh(), $this->superAdmin);
            $this->fail('Un refus etait attendu.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::CAPTURE_BLOCKED, $refus->reason);
            $this->assertStringContainsString('ancre_inconnue', $refus->getMessage());
            $this->assertStringNotContainsString('aucun chargement', $refus->getMessage());
        }
    }

    private function message(
        \App\Models\Loop $loop,
        User $auteur,
        string $corps,
        ?\App\Models\LoopMessage $parent = null,
        int $minutes = 0
    ): \App\Models\LoopMessage {
        $message = \App\Models\LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $auteur->id,
            'organization_id' => $loop->organization_id,
            'body' => $corps,
            'type' => 'user',
            'reply_to_id' => $parent?->id,
        ]);

        // L ordre chronologique doit etre celui qu on DECLARE, pas celui de
        // l insertion.
        $message->forceFill(['created_at' => now()->addMinutes($minutes)])->saveQuietly();

        return $message->fresh();
    }

    // =====================================================================
    // Le round-trip : le critere canonique
    // =====================================================================

    public function test_ROUND_TRIP_le_Manifest_capture_se_RECHARGE_sur_le_meme_monde(): void
    {
        // `Load -> activite declarable -> Capture -> Validator -> re-Load`.
        // On ne compare AUCUN UUID : stable keys, relations, contenus, ordres.
        $version = $this->versionChargee();
        [$loop, $auteur] = $this->uneBoucleEtUnMembre($version);

        \App\Models\LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $auteur->id,
            'organization_id' => $loop->organization_id,
            'body' => 'Une contribution ecrite APRES le chargement.',
            'type' => 'user',
        ]);

        $capturee = app(ScenarioCaptureService::class)->capturer($version, $this->superAdmin);

        // Le capture est rechargeable : on l'approuve puis on le charge dans
        // une sandbox NEUVE.
        $service = app(ScenarioLifecycleService::class);
        app(\App\Support\ScenarioManager\ScenarioVersionWriter::class)->validate($capturee);
        $service->approve($capturee->fresh(), $this->superAdmin);
        $second = $service->load($capturee->fresh());

        $this->assertNotSame($this->sandboxDe($version)->id, $second->organization->id, 'Le re-Load doit creer une sandbox NEUVE.');

        // Le monde declarable converge : on recapture depuis la NOUVELLE
        // sandbox et on compare les deux documents.
        $recapture = app(ScenarioCaptureService::class)->inspecter($capturee->fresh())->document;
        $attendu = json_decode((string) $capturee->json_source, true);

        // LES VINGT familles, pas treize.
        //
        // La premiere version omettait `service_requests`, `services` et TOUT
        // le sous-arbre `training` — soit exactement les familles dont la
        // Capture depend d une relecture de la source ou d un filtre
        // `archived_at`. Un test qui s appelle « round-trip » et qui laisse
        // sept familles hors du contrat ne prouve pas ce qu il annonce.
        foreach (['users', 'loops', 'memberships', 'dossiers', 'articles', 'files',
            'messages', 'categories', 'skills', 'service_requests', 'services',
            'polls', 'events', 'decisions', 'roadmap_items'] as $famille) {
            $this->assertSame(
                $this->empreinte($attendu[$famille]),
                $this->empreinte($recapture[$famille]),
                "La famille « {$famille} » ne converge pas apres re-Load."
            );
        }

        foreach (['modules', 'sequences', 'progress', 'assignments', 'submissions'] as $sous) {
            $this->assertSame(
                $this->empreinte($attendu['training'][$sous]),
                $this->empreinte($recapture['training'][$sous]),
                "La famille « training.{$sous} » ne converge pas apres re-Load."
            );
        }

        // Et le message ne de l'activite a bien fait le voyage.
        $corps = array_column($recapture['messages'], 'body');
        $this->assertContains('Une contribution ecrite APRES le chargement.', $corps);
    }

    public function test_une_SECONDE_Capture_rend_les_MEMES_stable_keys(): void
    {
        $version = $this->versionChargee();
        [$loop, $auteur] = $this->uneBoucleEtUnMembre($version);

        $nouvelle = \App\Models\Loop::query()->create([
            'organization_id' => $loop->organization_id,
            'name' => 'Boucle nee de l activite',
            'slug' => 'boucle-activite-'.uniqid(),
            'description' => 'Creee apres le chargement.',
            'type' => 'general',
            'visibility' => 'private',
            'access_mode' => 'invitation',
            'created_by' => $auteur->id,
        ]);
        \App\Models\LoopMember::query()->create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $nouvelle->id, 'user_id' => $auteur->id, 'role' => 'owner', 'status' => 'active',
        ]);

        // La primitive CANONIQUE : une Boucle du produit a toujours son espace
        // documents. La creer sans lui fabriquerait un monde que le produit ne
        // sait pas produire, et le test mesurerait alors une fiction.
        $racine = app(\App\Services\Loops\LoopRootDocumentService::class);
        $racine->ensureRootDossier($nouvelle);
        // ET son document racine : l'invariant Manifest exige les DEUX
        // (« A root dossier requires a root document »).
        $racine->ensureRootDocument($nouvelle, $auteur);

        $premiere = app(ScenarioCaptureService::class)->capturer($version, $this->superAdmin);
        $clefsA = array_column(json_decode((string) $premiere->json_source, true)['loops'], 'key');

        // RENOMMAGE, puis seconde Capture.
        $nouvelle->forceFill(['name' => 'Un tout autre nom'])->save();

        $seconde = app(ScenarioCaptureService::class)->capturer($version->fresh(), $this->superAdmin);
        $clefsB = array_column(json_decode((string) $seconde->json_source, true)['loops'], 'key');

        sort($clefsA);
        sort($clefsB);

        $this->assertSame($clefsA, $clefsB, 'Un renommage ne doit PAS changer une stable key deja attribuee.');
    }

    // =====================================================================
    // Bornes V1 : refuser, jamais tronquer
    // =====================================================================

    public function test_depasser_une_borne_V1_REFUSE_la_Capture_sans_rien_tronquer(): void
    {
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);
        [$loop, $auteur] = $this->uneBoucleEtUnMembre($version);

        // `loops` est borne a 20 et la fixture en a 2 : 19 de plus suffisent.
        for ($i = 0; $i < 19; $i++) {
            $boucle = \App\Models\Loop::query()->create([
                'organization_id' => $sandbox->id,
                'name' => 'Boucle de trop '.$i,
                'slug' => 'trop-'.$i.'-'.uniqid(),
                'description' => 'Au-dela de la borne.',
                'type' => 'general', 'visibility' => 'private', 'access_mode' => 'invitation',
                'created_by' => $auteur->id,
            ]);
            \App\Models\LoopMember::query()->create([
                'organization_id' => $sandbox->id, 'loop_id' => $boucle->id,
                'user_id' => $auteur->id, 'role' => 'owner', 'status' => 'active',
            ]);
        }

        $resultat = app(ScenarioCaptureService::class)->inspecter($version);

        $this->assertTrue($resultat->estBloquee());
        $this->assertStringContainsString('limite_v1_depassee', $resultat->rapport());
        // Le rapport est CHIFFRE : la valeur reelle et la limite.
        $this->assertStringContainsString('21 objets', $resultat->rapport());
        $this->assertStringContainsString('limite Manifest V1 de 20', $resultat->rapport());

        $avantVersions = ScenarioManifestVersion::query()->count();
        $avantClefs = \App\Models\ScenarioCaptureKey::query()->count();

        $this->attendreRefus(ScenarioVersionRefused::CAPTURE_BLOCKED, fn () => app(ScenarioCaptureService::class)->capturer($version, $this->superAdmin));

        // Aucune version partielle, aucun registre a moitie ecrit, AUCUNE
        // troncature : le monde reste tel quel.
        $this->assertSame($avantVersions, ScenarioManifestVersion::query()->count());
        $this->assertSame($avantClefs, \App\Models\ScenarioCaptureKey::query()->count());
    }

    /**
     * Une empreinte COMPARABLE : les clefs et les relations, jamais les UUID.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @return list<string>
     */
    private function empreinte(array $lignes): array
    {
        $empreintes = array_map(static function (array $ligne): string {
            ksort($ligne);

            return (string) json_encode($ligne, JSON_UNESCAPED_UNICODE);
        }, $lignes);

        sort($empreintes);

        return $empreintes;
    }

    // =====================================================================
    // Outillage
    // =====================================================================

    private function sandboxDe(ScenarioManifestVersion $version): Organization
    {
        $load = \App\Models\ScenarioPackLoad::query()->findOrFail($version->scenario_pack_load_id);

        return Organization::query()->withTrashed()->findOrFail($load->organization_id);
    }

    /**
     * @return array{0: \App\Models\Loop, 1: User}
     */
    private function uneBoucleEtUnMembre(ScenarioManifestVersion $version): array
    {
        $sandbox = $this->sandboxDe($version);
        $loop = \App\Models\Loop::query()->where('organization_id', $sandbox->id)->firstOrFail();
        $membre = \App\Models\LoopMember::query()->where('loop_id', $loop->id)->firstOrFail();

        return [$loop, User::query()->findOrFail($membre->user_id)];
    }

    private function attendreRefus(string $raison, \Closure $geste): void
    {
        try {
            $geste();
            $this->fail("Un refus « {$raison} » etait attendu.");
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame($raison, $refus->reason);
        }
    }

    private function versionChargee(): ScenarioManifestVersion
    {
        $json = file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
        $resultat = app(ScenarioManifestValidator::class)->validate($json);
        $this->assertTrue($resultat->isValid(), 'La fixture AMT doit etre valide.');

        $version = new ScenarioManifestVersion([
            'scenario_key' => 'amt-formation-ia',
            'name' => 'AMT — Formation IA',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_DOGFOODING,
            'origin' => ScenarioManifestVersion::ORIGIN_IMPORT,
            'json_source' => $json,
            'created_by' => $this->superAdmin->id,
        ]);

        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
        ])->save();

        $service = app(ScenarioLifecycleService::class);
        $service->approve($version, $this->superAdmin);
        $service->load($version->fresh());

        return $version->fresh();
    }
}
