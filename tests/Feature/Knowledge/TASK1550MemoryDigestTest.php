<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Livewire\LoopChat;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\ChatLoop\AiResponseExplanationService;
use App\Services\Knowledge\ClaimMemory;
use App\Services\Knowledge\HumanClaimCorrection;
use App\Services\Knowledge\LoopClaimCompiler;
use App\Services\Knowledge\LoopClaimDelta;
use App\Services\Knowledge\LoopMemoryDigest;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1550 — W1.5-B : « Depuis cet echange, BouclePro a retenu... »
 *
 * Ce que ces tests protegent :
 *
 *  - la carte ne montre QUE des ecritures reellement persistees par le
 *    COMPILER, dans la fenetre qui suit le dernier message de cette personne ;
 *  - avant la consolidation il n'y a PAS de carte — pas de spinner, pas de
 *    delai promis, pas de « en cours d'apprentissage ». Le silence est la
 *    formulation honnete de l'asynchrone (CDC CORE V3 §3.3) ;
 *  - une correction HUMAINE n'est jamais renvoyee comme un apprentissage
 *    automatique : elle a deja eu son accuse de reception synchrone (T1549) ;
 *  - `Pourquoi ?` et `Corriger` de la carte sont LE chemin de T1549 —
 *    `HumanClaimCorrection` seul moteur, memes gardes, memes trois issues ;
 *  - l'adresse publique d'un enonce est un jeton opaque LIE AU SUJET : ni
 *    `subject_key`, ni identifiant de ligne, et un jeton forge ou emprunte a
 *    une autre Boucle n'ecrit RIEN ;
 *  - le resultat d'un geste s'affiche LA OU le geste a ete fait, jamais dans
 *    les deux surfaces a la fois ;
 *  - le renvoi de la carte ne persiste rien et ne pretend pas etre une
 *    position de lecture — le produit n'en a aucune, et cette TASK n'en cree
 *    pas.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1550MemoryDigestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    /** Membre de la Boucle, mais qui n'y a jamais ecrit. */
    private User $bruno;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);
        $this->bruno = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bruno Lefevre']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Belleville',
            'visibility' => 'private',
        ]);

        $this->membre($this->loop, $this->alice);
        $this->membre($this->loop, $this->bruno);

        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1550',
        ]);

        config([
            'ai.chatloop.enabled' => true,
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
        ]);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (): array => array_fill(0, 1536, 0.01),
            $prompt->inputs,
        ))->preventStrayEmbeddings();
    }

    // ───────────────────────────────────────────────── la fenetre

    public function test_la_carte_montre_ce_que_le_compiler_a_ecrit_depuis_le_dernier_message(): void
    {
        $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');

        Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-memory-digest')
            ->assertSee('Vaucanson realise la charpente')
            ->assertSee(__('loops.digest_title'))
            ->assertSeeHtml('data-memory-kind="'.LoopClaimDelta::ADDED.'"');
    }

    public function test_aucune_carte_avant_la_consolidation(): void
    {
        // La personne parle. Rien n'a encore ete compile : le balayeur tourne
        // toutes les dix minutes, et cette fenetre est un comportement produit
        // ACCEPTE. La surface se TAIT — elle ne promet aucun delai.
        $this->messageDe($this->alice, 'Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');

        $rendu = Livewire::actingAs($this->alice)->test(LoopChat::class, ['loop' => $this->loop]);

        $rendu->assertDontSeeHtml('data-memory-digest')
            ->assertDontSee(__('loops.digest_title'))
            ->assertSet('digestPanel', null);

        $html = $rendu->html();

        foreach (['en cours d\'apprentissage', 'apprentissage en cours', 'BouclePro apprend'] as $promesse) {
            $this->assertStringNotContainsString($promesse, $html,
                'aucune promesse de delai ne doit apparaitre : le CDC interdit de laisser croire a un apprentissage en cours');
        }
    }

    public function test_aucune_carte_pour_qui_n_a_jamais_parle_dans_cette_boucle(): void
    {
        $this->unAjout();

        // Bruno est membre et peut TOUT lire. Mais il n'a pas contribue : il n'y
        // a pas « cet echange » de son cote, et la carte ne doit pas devenir la
        // notification passive d'un lecteur.
        Livewire::actingAs($this->bruno)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-memory-digest')
            ->assertSet('digestPanel', null);
    }

    public function test_une_ecriture_anterieure_au_dernier_message_reste_hors_de_la_fenetre(): void
    {
        // Un enonce appris d'un echange VIEUX de trois jours.
        $this->unAjout('Le permis de construire est depose depuis le 2 juin.', quand: now()->subDays(3));

        $this->assertNotNull($this->claimActif(), 'PREMISSE : l enonce doit exister');

        // Puis la personne reparle aujourd'hui. « Depuis cet echange » designe
        // CE message-ci : le vieil enonce n'a pas ete retenu depuis.
        $this->messageDe($this->alice, 'Je reprends le dossier, ou en est-on exactement sur le planning ?');

        Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-memory-digest')
            ->assertDontSee('Le permis de construire est depose');
    }

    public function test_la_carte_est_bornee_a_trois_elements(): void
    {
        $source = $this->messageDe($this->alice, 'Recapitulatif complet du chantier, point par point, pour la reunion.');

        $this->compileAvec(array_map(
            fn (int $n): array => [
                'op' => 'ADD',
                'text' => "Enonce numero {$n} du recapitulatif de chantier.",
                'evidence' => [(string) $source->id],
            ],
            [1, 2, 3, 4, 5],
        ));

        $this->assertSame(5, DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)->claims()->active()->count(),
            'PREMISSE : cinq enonces doivent etre ecrits');

        $rendu = Livewire::actingAs($this->alice)->test(LoopChat::class, ['loop' => $this->loop]);

        $this->assertSame(LoopMemoryDigest::MAX_ENTREES,
            substr_count($rendu->html(), 'data-digest-memory-entry'),
            'la carte montre deux a trois elements utiles, jamais un inventaire de memoire');
    }

    public function test_une_compilation_qui_n_ecrit_rien_ne_produit_aucune_carte(): void
    {
        $source = $this->messageDe($this->alice, 'Merci beaucoup, c est note, je regarde cela demain matin tranquillement.');

        // Le compiler tourne, la source a bouge, et il ne retient RIEN : le
        // conteneur est rafraichi mais aucun enonce n'est ecrit. Pas de carte.
        $bilan = $this->compileAvec([['op' => 'KEEP', 'claim_id' => 'inexistant']]);

        $this->assertSame(0, $bilan['ajoutes'] + $bilan['modifies'] + $bilan['retractes'],
            'PREMISSE : cette compilation ne doit rien ecrire');
        $this->assertNotNull(app(ClaimMemory::class)->conteneur($this->organization, $this->loop->fresh()),
            'PREMISSE : le conteneur doit avoir ete rafraichi');
        $this->assertNotNull($source->id);

        Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-memory-digest');
    }

    public function test_le_conteneur_de_boucle_n_entre_jamais_dans_la_carte(): void
    {
        $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');

        $conteneur = app(ClaimMemory::class)->conteneur($this->organization, $this->loop->fresh());
        $this->assertNotNull($conteneur, 'PREMISSE : le conteneur existe');
        $this->assertSame(DerivedKnowledgeNote::SUBJECT_DIGEST, (string) $conteneur->subject_key);

        $digest = app(LoopMemoryDigest::class);
        $panel = $digest->pour($this->organization, $this->loop->fresh(), $this->alice);

        $this->assertNotNull($panel);

        // Mesure par IDENTITE, jamais par contenu : avec un seul enonce, le
        // conteneur porte exactement le meme texte que lui, et comparer les
        // textes n aurait rien prouve.
        $interdit = $digest->jeton($this->loop, DerivedKnowledgeNote::SUBJECT_DIGEST);
        $autorises = DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)->claims()->active()
            ->pluck('subject_key')
            ->map(fn ($cle): string => $digest->jeton($this->loop, (string) $cle))
            ->all();

        foreach ($panel['entries'] as $entry) {
            $this->assertNotSame($interdit, $entry['ref'],
                'le conteneur n est pas un enonce adressable et n a rien a faire dans la carte');
            $this->assertContains($entry['ref'], $autorises,
                'toute entree de la carte designe un enonce reel de cette Boucle');
        }
    }

    // ─────────────────────────────────── honnetete de la surface

    public function test_le_rendu_ne_montre_ni_subject_key_ni_identifiant_ni_exhaustivite(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');

        $html = Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->html();

        $this->assertStringNotContainsString((string) $claim->subject_key, $html,
            'subject_key est une identite choisie par le modele : elle reste cote serveur');
        $this->assertStringNotContainsString((string) $claim->id, $html,
            'aucun identifiant technique de ligne ne circule dans le rendu');
        $this->assertStringNotContainsString('subject_key', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html,
            'aucune case a cocher : rien n attend de validation, tout est deja enregistre');

        // La portee se dit en langage humain — UNE fois pour la carte, et non
        // sur chaque ligne : la carte vit dans la Boucle qu elle decrit, et la
        // repetition ajoutait une rangee par element sans ajouter d information
        // (mesure navigateur : trois entrees remplissaient l ecran entier).
        //
        // `e()` : Blade echappe l apostrophe en `&#039;`, et comparer la chaine
        // brute ferait echouer une assertion pourtant vraie.
        $this->assertStringContainsString(e(__('loops.digest_note')), $html);
        $this->assertStringContainsString('Boucle', __('loops.digest_note'),
            'la portee affichee nomme la Boucle (BP-35)');
    }

    public function test_une_correction_humaine_n_est_jamais_renvoyee_comme_un_apprentissage(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');

        $resultat = app(HumanClaimCorrection::class)->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'En fait c est Charpentier Bernard qui prend le lot, pas Vaucanson.',
            'Charpentier Bernard realise la charpente, avec une hausse de 12%.',
        );

        $this->assertTrue($resultat['ok'], 'PREMISSE : la correction humaine doit passer');

        Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            // L'enonce corrige est bien en memoire — mais ce n'est pas un
            // apprentissage automatique, et la personne a deja eu son accuse de
            // reception. Le lui renvoyer en « BouclePro a retenu » brouillerait
            // la distinction que le CDC demande de tenir.
            ->assertDontSee('Charpentier Bernard realise la charpente')
            ->assertDontSeeHtml('data-memory-digest');
    }

    public function test_un_retrait_par_le_compiler_se_dit_sans_offrir_de_geste(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');

        $nouveau = $this->messageDe($this->alice, 'Finalement le lot charpente est annule, on ne le passe pas cette annee.');

        $this->compileAvec([[
            'op' => 'RETRACT',
            'claim_id' => (string) $claim->subject_key,
            'reason' => 'Le lot est annule.',
            'evidence' => [(string) $nouveau->id],
        ]]);

        $this->assertSame(0, DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)->claims()->active()->count(),
            'PREMISSE : le retrait ne laisse aucun enonce actif');

        Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-memory-digest')
            ->assertSeeHtml('data-memory-kind="'.LoopClaimDelta::RETRACTED.'"')
            // Un sujet retracte n'a plus de version a viser : aucun geste.
            ->assertDontSeeHtml('data-digest-correct-open-update')
            ->assertDontSeeHtml('data-digest-correct-open-retract');
    }

    public function test_le_renvoi_de_la_carte_ne_persiste_rien(): void
    {
        $this->unAjout();

        Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-memory-digest')
            ->call('dismissDigest')
            ->assertSet('digestPanel', null)
            ->assertDontSeeHtml('data-memory-digest');

        // Un nouveau montage la ramene : ce n'etait PAS un accuse de lecture,
        // et rien n'a ete ecrit nulle part.
        Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-memory-digest');
    }

    // ───────────────────────────── le chemin Corriger, reutilise

    public function test_corriger_depuis_la_carte_passe_par_le_moteur_standard(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');

        $jeton = app(LoopMemoryDigest::class)->jeton($this->loop, (string) $claim->subject_key);

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $composant = Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-digest-correct-open-update')
            ->call('startCorrection', $jeton, 'update', LoopChat::ANCRE_DIGEST)
            ->assertSet('correctingAnchor', LoopChat::ANCRE_DIGEST)
            ->assertSet('correctingRef', $jeton)
            ->assertSet('correctingVersion', (int) $claim->version)
            ->set('correctionText', 'En fait c est Charpentier Bernard qui prend le lot.')
            ->set('correctionNewText', 'Charpentier Bernard realise la charpente, avec une hausse de 12%.')
            ->call('submitCorrection');

        $composant->assertSet('correctionFlash', __('loops.correct_ack'))
            ->assertSet('correctionConflict', null)
            ->assertSet('correctionFeedbackAnchor', LoopChat::ANCRE_DIGEST)
            ->assertSet('correctingRef', null);

        // La memoire a REELLEMENT bouge, par le moteur standard.
        $actif = $this->claimActif();
        $this->assertNotNull($actif);
        $this->assertSame('Charpentier Bernard realise la charpente, avec une hausse de 12%.', (string) $actif->content);
        $this->assertSame((int) $claim->version + 1, (int) $actif->version);
        $this->assertSame((string) $claim->subject_key, (string) $actif->subject_key);

        // Et la preuve humaine est un message visible de la Boucle.
        $this->assertSame($messagesAvant + 1, LoopMessage::query()->where('loop_id', $this->loop->id)->count());
        $preuve = LoopMessage::query()->where('loop_id', $this->loop->id)->latest('created_at')->first();
        $this->assertSame((string) $this->alice->id, (string) $preuve->sender_id);
        $this->assertSame('En fait c est Charpentier Bernard qui prend le lot.', (string) $preuve->body);
    }

    public function test_retracter_depuis_la_carte_archive_sans_successeur(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');

        $jeton = app(LoopMemoryDigest::class)->jeton($this->loop, (string) $claim->subject_key);

        Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCorrection', $jeton, 'retract', LoopChat::ANCRE_DIGEST)
            ->set('correctionText', 'Ce lot n existe plus, il a ete retire du programme.')
            ->call('submitCorrection')
            ->assertSet('correctionFlash', __('loops.correct_ack'));

        $this->assertNull($this->claimActif(), 'un RETRACT ne fabrique aucun successeur actif');
        $this->assertSame(1, DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)->claims()->count(),
            'la ligne archivee reste : l histoire humaine ne se reecrit pas');
    }

    public function test_un_jeton_forge_n_ecrit_rien(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');
        $contenuAvant = (string) $claim->content;
        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $composant = Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop]);

        // Un jeton qui n'est dans AUCUNE carte figee n'ouvre rien.
        $composant->call('startCorrection', str_repeat('a', 16), 'retract', LoopChat::ANCRE_DIGEST)
            ->assertSet('correctingRef', null)
            ->assertSet('correctingAnchor', null);

        // Et forcer l'etat cote serveur, en contournant le verrou, n'ecrit rien
        // non plus : le quadruplet se revalide contre la carte figee.
        $instance = $composant->instance();
        $instance->correctingAnchor = LoopChat::ANCRE_DIGEST;
        $instance->correctingRef = str_repeat('a', 16);
        $instance->correctingVersion = (int) $claim->version;
        $instance->correctingMode = 'retract';
        $instance->correctionText = 'Je retire cet enonce que je ne devrais pas pouvoir viser.';
        $instance->submitCorrection(
            app(HumanClaimCorrection::class),
            app(AiResponseExplanationService::class),
            app(LoopMemoryDigest::class),
        );

        $this->assertSame(__('loops.correct_conflict_before'), $instance->correctionConflict);
        $this->assertSame('', $instance->correctionFlash);
        $this->assertSame($contenuAvant, (string) $this->claimActif()?->content,
            'la memoire ne bouge pas');
        $this->assertSame($messagesAvant, LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'rien n a ete ecrit, pas meme un message');
    }

    public function test_un_jeton_calcule_pour_une_autre_boucle_n_ecrit_rien(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');

        $autre = $this->autreBoucle('Chantier Montreuil');

        // Le MEME sujet, adresse avec le jeton d'une AUTRE Boucle : un jeton est
        // borne a sa Boucle, donc il ne designe rien ici.
        $jetonAilleurs = app(LoopMemoryDigest::class)->jeton($autre, (string) $claim->subject_key);

        $this->assertNull(
            app(LoopMemoryDigest::class)->sujetCorrigeable($this->organization, $this->loop->fresh(), $this->alice, $jetonAilleurs),
            'un jeton d une autre Boucle ne resout aucun sujet ici',
        );

        Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCorrection', $jetonAilleurs, 'retract', LoopChat::ANCRE_DIGEST)
            ->assertSet('correctingRef', null);
    }

    public function test_une_version_perimee_depuis_la_carte_n_ecrit_rien_pas_meme_un_message(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');
        $jeton = app(LoopMemoryDigest::class)->jeton($this->loop, (string) $claim->subject_key);

        $composant = Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCorrection', $jeton, 'retract', LoopChat::ANCRE_DIGEST)
            ->set('correctionText', 'Cet enonce n est plus a jour du tout.');

        // Quelqu'un d'autre corrige le meme sujet entre-temps : la version que
        // la personne a LUE n'existe plus.
        app(HumanClaimCorrection::class)->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->bruno,
            (string) $claim->subject_key, (int) $claim->version,
            'Je corrige avant Alice : le lot passe a Charpentier Bernard.',
            'Charpentier Bernard realise la charpente, avec une hausse de 12%.',
        );

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $composant->call('submitCorrection')
            ->assertSet('correctionConflict', __('loops.correct_conflict_before'))
            ->assertSet('correctionFlash', '');

        $this->assertSame($messagesAvant, LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'une version perimee ne laisse AUCUNE trace, pas meme un message public');
        $this->assertSame('Charpentier Bernard realise la charpente, avec une hausse de 12%.',
            (string) $this->claimActif()?->content, 'la correction de Bruno tient');
    }

    public function test_un_droit_revoque_entre_l_affichage_et_le_clic_n_ecrit_rien(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');
        $jeton = app(LoopMemoryDigest::class)->jeton($this->loop, (string) $claim->subject_key);

        $composant = Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCorrection', $jeton, 'update', LoopChat::ANCRE_DIGEST)
            ->set('correctionText', 'Je corrige alors que mon adhesion vient de tomber.')
            ->set('correctionNewText', 'Charpentier Bernard realise la charpente, avec une hausse de 12%.');

        LoopMember::query()
            ->where('loop_id', $this->loop->id)
            ->where('user_id', $this->alice->id)
            ->update(['status' => 'removed']);

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $composant->call('submitCorrection')
            ->assertSet('correctionConflict', __('loops.correct_refused_right'))
            ->assertSet('digestCanCorrect', false);

        $this->assertSame('Vaucanson realise la charpente, avec une hausse de 12%.',
            (string) $this->claimActif()?->content, 'la memoire ne bouge pas');
        $this->assertSame($messagesAvant, LoopMessage::query()->where('loop_id', $this->loop->id)->count());
    }

    public function test_une_boucle_non_autorisee_ne_rend_aucune_carte(): void
    {
        $this->unAjout();

        LoopMember::query()
            ->where('loop_id', $this->loop->id)
            ->where('user_id', $this->alice->id)
            ->update(['status' => 'removed']);

        // Ferme par defaut : le lecteur ne rend rien, et ne revele meme pas
        // qu'un changement a eu lieu.
        $this->assertNull(
            app(LoopMemoryDigest::class)->pour($this->organization, $this->loop->fresh(), $this->alice),
        );
    }

    public function test_la_carte_gelee_pendant_une_correction_ne_disparait_pas_sous_la_main(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');
        $jeton = app(LoopMemoryDigest::class)->jeton($this->loop, (string) $claim->subject_key);

        $composant = Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCorrection', $jeton, 'update', LoopChat::ANCRE_DIGEST)
            ->set('correctionText', 'Je suis en train d ecrire ma correction.');

        // La personne reparle : sa nouvelle contribution deplacerait la fenetre
        // et donc la carte. Le formulaire ouvert la GELE — sans quoi l'entree
        // disparaitrait, avec le texte en cours de saisie.
        $this->messageDe($this->alice, 'Question sans rapport que je pose pendant que je corrige la memoire.');

        $composant->call('$refresh')
            ->assertSeeHtml('data-digest-correct-form')
            ->assertSet('correctingRef', $jeton)
            ->assertSet('correctionText', 'Je suis en train d ecrire ma correction.');
    }

    // ──────────────────────── le resultat s'affiche ou le geste a eu lieu

    public function test_l_ack_de_la_carte_ne_s_affiche_pas_dans_le_panneau_pourquoi(): void
    {
        [$claim] = $this->unAjout('Vaucanson realise la charpente, avec une hausse de 12%.');
        $jeton = app(LoopMemoryDigest::class)->jeton($this->loop, (string) $claim->subject_key);

        $html = Livewire::actingAs($this->alice)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('startCorrection', $jeton, 'retract', LoopChat::ANCRE_DIGEST)
            ->set('correctionText', 'Ce lot n existe plus, il a ete retire du programme.')
            ->call('submitCorrection')
            ->assertSet('correctionFeedbackAnchor', LoopChat::ANCRE_DIGEST)
            ->html();

        $this->assertStringContainsString('data-digest-correction-flash', $html,
            'l accuse de reception s affiche dans la carte, la ou le geste a ete fait');
        $this->assertStringNotContainsString('data-correction-flash', $html,
            'et nulle part ailleurs — sans quoi le panneau Pourquoi ? annoncerait un geste qu il n a pas recu');
    }

    // ───────────────────────────────────────────────────── fixtures

    /**
     * Un ajout REEL, compile par le vrai pipeline — jamais insere a la main :
     * sa provenance, son `observed_at` et son chunk viennent du compiler
     * (motif T1548 / T1549).
     *
     * @return array{0: DerivedKnowledgeNote, 1: LoopMessage}
     */
    private function unAjout(
        string $texte = 'Vaucanson realise la charpente, avec une hausse de 12%.',
        ?\DateTimeInterface $quand = null,
    ): array {
        $source = $this->messageDe(
            $this->alice,
            'Pour la charpente on part sur Vaucanson, malgre les 12% de hausse annoncee.',
            $quand,
        );

        $this->compileAvec([[
            'op' => 'ADD', 'text' => $texte, 'evidence' => [(string) $source->id],
        ]]);

        $claim = DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)
            ->claims()->active()
            ->where('content', $texte)
            ->firstOrFail();

        return [$claim, $source];
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array<string, mixed>
     */
    private function compileAvec(array $operations): array
    {
        LoopClaimPatchAgent::fake(fn (): TextResponse => new TextResponse(
            (string) json_encode(['operations' => $operations], JSON_UNESCAPED_UNICODE),
            new Usage(60, 40), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertTrue($bilan['applique'], 'PREMISSE : la compilation doit aboutir');

        return $bilan;
    }

    private function messageDe(User $user, string $corps, ?\DateTimeInterface $quand = null): LoopMessage
    {
        $message = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $user->id,
            'body' => $corps,
            'type' => 'user',
        ]);

        if ($quand !== null) {
            // Une fixture se DATE explicitement quand la date est le sujet du
            // test : `travelTo()` figerait l horloge, et l ecart mesure
            // retrecirait au lieu de grandir (piege deja paye, T1544).
            $message->forceFill(['created_at' => $quand, 'updated_at' => $quand])->save();
        }

        return $message->fresh();
    }

    private function claimActif(): ?DerivedKnowledgeNote
    {
        return DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)
            ->claims()->active()
            ->first();
    }

    private function membre(Loop $loop, User $user): void
    {
        LoopMember::create([
            'organization_id' => (string) $loop->organization_id,
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function autreBoucle(string $nom): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => $nom,
            'visibility' => 'private',
        ]);

        $this->membre($loop, $this->alice);
        app(LoopRootDocumentService::class)->ensureRootDossier($loop->fresh());

        return $loop;
    }
}
