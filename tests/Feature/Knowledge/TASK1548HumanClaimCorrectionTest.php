<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Events\LoopMessageCreated;
use App\Jobs\GenerateAiAgentResponse;
use App\Jobs\IndexDerivedKnowledgeNote;
use App\Models\AiProviderInvocation;
use App\Models\DerivedKnowledgeNote;
use App\Models\DossierChunk;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DerivedChunkEligibility;
use App\Services\Knowledge\ClaimMemory;
use App\Services\Knowledge\ClaimPatch;
use App\Services\Knowledge\ClaimResurrectionGuard;
use App\Services\Knowledge\ClaimWriteOrigin;
use App\Services\Knowledge\HumanClaimCorrection;
use App\Services\Knowledge\LoopClaimCompiler;
use App\Services\LoopMessageService;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1548 — « c'est faux », et la memoire l'entend tout de suite.
 *
 * Ces tests mesurent le CONTRAT de correction humaine, et surtout ce qui le
 * rend credible : qu'une correction ne soit pas defaite dix minutes plus tard
 * par le compiler relisant le MEME corpus.
 *
 * La regression qui justifie la garde n'est pas theorique. Elle est
 * structurelle : corriger ECRIT un message, un message change
 * `empreinteSource`, et une source changee RELANCE le compiler. Sans garde, la
 * correction durait exactement un balayage.
 */
class TASK1548HumanClaimCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Belleville',
            'visibility' => 'private',
        ]);

        $this->membre($this->loop, $this->alice);

        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1548',
        ]);

        config([
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

    // ────────────────────────────────────────────── mutation deterministe

    public function test_un_retract_humain_archive_le_claim_actif(): void
    {
        $claim = $this->unClaim();

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertTrue($resultat['ok'], 'la correction doit etre acceptee');

        $apres = DerivedKnowledgeNote::findOrFail($claim->id);
        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED, $apres->status);
        $this->assertNotNull($apres->superseded_at);

        // Un RETRACT ne fabrique AUCUN remplacant.
        $this->assertNull($apres->superseded_by_id);
        $this->assertSame(0, DerivedKnowledgeNote::query()->claims()->active()->count());
    }

    public function test_un_update_humain_cree_une_version_et_chaine_la_supersession(): void
    {
        $claim = $this->unClaim();

        $resultat = $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Ce n est plus Vaucanson, la charpente est confiee a Lemercier.',
            'Lemercier realise la charpente du chantier Belleville.',
        );

        $this->assertTrue($resultat['ok']);

        $ancien = DerivedKnowledgeNote::findOrFail($claim->id);
        $nouveau = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();

        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED, $ancien->status);
        $this->assertSame((string) $nouveau->id, (string) $ancien->superseded_by_id,
            'le lignage doit chainer l ancien vers le nouveau');

        // L'identite du SUJET survit ; c'est la version qui avance.
        $this->assertSame((string) $claim->subject_key, (string) $nouveau->subject_key);
        $this->assertSame(2, $nouveau->version);
        $this->assertSame(1, $ancien->version);
        $this->assertStringContainsString('Lemercier', (string) $nouveau->content);
    }

    public function test_la_provenance_nomme_la_personne_et_l_instant(): void
    {
        $claim = $this->unClaim();

        $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Ce n est plus Vaucanson, la charpente est confiee a Lemercier.',
            'Lemercier realise la charpente du chantier Belleville.',
        );

        $provenance = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail()->provenance;

        $this->assertSame(ClaimWriteOrigin::HUMAN_CORRECTION, $provenance['derived_by'] ?? null,
            'une correction humaine ne doit PAS porter la signature du deriver');
        $this->assertSame((string) $this->alice->id, $provenance['corrected_by_user_id'] ?? null);
        $this->assertNotNull($provenance['corrected_at'] ?? null);

        // La preuve pointe le message humain de correction.
        $message = LoopMessage::findOrFail($provenance['correction_message_id']);
        $this->assertContains((string) $message->id, $provenance['source_loop_message_ids'] ?? []);
    }

    // ────────────────────────────────────────────── histoire humaine

    public function test_le_message_humain_d_origine_reste_intact_et_la_correction_est_visible(): void
    {
        $origine = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($origine);

        $avant = $origine->fresh();

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $apres = $origine->fresh();
        $this->assertSame((string) $avant->body, (string) $apres->body, 'le message humain d origine n est jamais reecrit');
        $this->assertNull($apres->deleted_at, 'le message humain d origine n est jamais supprime');

        // Le message de correction EXISTE, il est humain, et il est visible.
        $correction = LoopMessage::findOrFail($resultat['message_id']);
        $this->assertSame('user', (string) $correction->type);
        $this->assertSame((string) $this->alice->id, (string) $correction->sender_id);
        $this->assertNull($correction->deleted_at);
        $this->assertStringContainsString('annule', (string) $correction->body);
    }

    // ────────────────────────────────────────────── ACL

    public function test_un_etranger_a_l_organization_est_refuse(): void
    {
        $claim = $this->unClaim();

        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $intrus = User::factory()->create(['organization_id' => $autreOrg->id]);

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $intrus,
            (string) $claim->subject_key, (int) $claim->version, 'Je retracte cet enonce qui ne me regarde pas.',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertSame('utilisateur_hors_tenant', $resultat['raison']);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE,
            DerivedKnowledgeNote::findOrFail($claim->id)->status);
    }

    public function test_un_membre_de_l_organization_non_membre_de_la_boucle_est_refuse(): void
    {
        $claim = $this->unClaim();
        $collegue = User::factory()->create(['organization_id' => $this->organization->id]);

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $collegue,
            (string) $claim->subject_key, (int) $claim->version, 'Je retracte un enonce d une Boucle dont je ne suis pas membre.',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertSame('loop_non_autorisee', $resultat['raison']);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE,
            DerivedKnowledgeNote::findOrFail($claim->id)->status);
    }

    public function test_une_boucle_archivee_refuse_la_correction(): void
    {
        $claim = $this->unClaim();
        $this->loop->forceFill(['status' => 'archived'])->save();

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version, 'Je retracte cet enonce sur une Boucle archivee.',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertSame('loop_non_ecrivable', $resultat['raison']);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE,
            DerivedKnowledgeNote::findOrFail($claim->id)->status);
    }

    public function test_un_claim_d_une_autre_boucle_est_refuse(): void
    {
        $claim = $this->unClaim();

        $autre = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Convention',
            'visibility' => 'private',
        ]);
        $this->membre($autre, $this->alice);
        app(LoopRootDocumentService::class)->ensureRootDossier($autre->fresh());

        // Le `subject_key` est REEL, l'utilisateur est membre des deux : seule
        // l'appartenance du claim a CETTE Boucle refuse.
        $resultat = $this->correction()->retracter(
            $this->organization, $autre->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version, 'Je retracte depuis une Boucle qui ne porte pas cet enonce.',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertSame('claim_introuvable', $resultat['raison']);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE,
            DerivedKnowledgeNote::findOrFail($claim->id)->status);
        $this->assertNull($resultat['message_id'], 'un refus de garde n ecrit AUCUN message');
    }

    // ─────────────────────────────────────── garde anti-resurrection

    public function test_l_ancien_corpus_ne_ressuscite_pas_un_claim_retracte(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);
        $texte = (string) $claim->content;

        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        // Le compiler repasse et re-propose EXACTEMENT le meme enonce, depuis
        // la meme vieille preuve. C'est le scenario reel : la source a bouge
        // (le message de correction), donc le balayage n'est pas court-circuite.
        $this->fakePatch([
            ['op' => 'ADD', 'text' => $texte, 'evidence' => [(string) $source->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['ajoutes'], 'un enonce retracte ne revient pas sur une preuve ANTERIEURE');
        $this->assertSame(1, $bilan['resurrections']);
        $this->assertSame(0, DerivedKnowledgeNote::query()->claims()->active()->count());
    }

    public function test_une_reformulation_depuis_les_memes_preuves_ne_ressuscite_pas_davantage(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);

        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        // Le texte change — l'empreinte ne mord donc pas. Ce qui mord, c'est
        // que la preuve est celle que la correction a deja tranchee.
        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'La charpente du chantier revient finalement a l entreprise Vaucanson.',
                'evidence' => [(string) $source->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['ajoutes'], 'reformuler le meme passe reste le meme passe');
        $this->assertSame(1, $bilan['resurrections']);
    }

    public function test_l_ancien_corpus_ne_retablit_pas_une_version_remplacee(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);
        $ancienTexte = (string) $claim->content;

        $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Ce n est plus Vaucanson, la charpente est confiee a Lemercier.',
            'Lemercier realise la charpente du chantier Belleville.',
        );

        $actif = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();

        // Le compiler tente de RAMENER la version d'avant, depuis la vieille preuve.
        $this->fakePatch([
            ['op' => 'UPDATE', 'claim_id' => (string) $actif->subject_key,
                'text' => $ancienTexte, 'evidence' => [(string) $source->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['modifies'], 'la version remplacee ne revient pas');
        $this->assertSame(1, $bilan['resurrections']);

        $encore = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();
        $this->assertStringContainsString('Lemercier', (string) $encore->content);
    }

    public function test_une_preuve_humaine_vraiment_posterieure_permet_d_evoluer_encore(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);
        $texte = (string) $claim->content;

        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        // Quelqu'un ecrit APRES la correction. La Boucle doit pouvoir
        // reapprendre — y compris contredire la personne qui a corrige.
        //
        // L'horloge avance d'une seconde PLEINE : `created_at` est stocke a la
        // seconde, et un message de la meme seconde n'est pas posterieur.
        $this->travel(2)->seconds();
        $nouveau = $this->message('Revirement : le marche charpente repart finalement chez Vaucanson.');

        $this->fakePatch([
            ['op' => 'ADD', 'text' => $texte, 'evidence' => [(string) $nouveau->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(1, $bilan['ajoutes'], 'une preuve POSTERIEURE rouvre l apprentissage');
        $this->assertSame(0, $bilan['resurrections']);
        $this->assertSame(1, DerivedKnowledgeNote::query()->claims()->active()->count());
    }

    public function test_la_garde_ne_gele_pas_les_sujets_voisins(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);

        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->travel(2)->seconds();
        $autre = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');

        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Le budget travaux de Belleville est de 486 000 euros.',
                'evidence' => [(string) $autre->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(1, $bilan['ajoutes'], 'corriger UN enonce ne ferme pas l apprentissage des autres');
        $this->assertSame(0, $bilan['resurrections']);
    }

    /**
     * ADDENDUM — CORPUS MIXTE. L'operation qui cite un message neuf A COTE des
     * anciens est le moyen le plus simple de rejouer le passe en le maquillant
     * de neuf. Une seule preuve antérieure suffit a la refuser.
     */
    public function test_une_preuve_neuve_melee_a_une_ancienne_ne_suffit_pas(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);
        $texte = (string) $claim->content;

        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->travel(2)->seconds();
        $neuf = $this->message('On refera le point sur la charpente a la reunion de jeudi.');

        // Le message neuf ne dit rien du marche ; il sert de caution a la
        // vieille preuve, qui elle seule porte le fait retracte.
        $this->fakePatch([
            ['op' => 'ADD', 'text' => $texte,
                'evidence' => [(string) $neuf->id, (string) $source->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['ajoutes'], 'une seule preuve ANTERIEURE suffit a refuser');
        $this->assertSame(1, $bilan['resurrections']);
    }

    /**
     * ADDENDUM — « Ne jamais faire echouer tout le run ». La garde ecarte
     * l'operation fautive, pas le patch : ce qui est legitime dans le meme
     * tour s'applique.
     */
    public function test_une_operation_refusee_ne_fait_pas_tomber_le_reste_du_run(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);
        $texte = (string) $claim->content;

        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->travel(2)->seconds();
        $budget = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');

        $this->fakePatch([
            // Refusee : rejoue le passe corrige.
            ['op' => 'ADD', 'text' => $texte, 'evidence' => [(string) $source->id]],
            // Legitime : autre sujet, preuve posterieure.
            ['op' => 'ADD', 'text' => 'Le budget travaux de Belleville est de 486 000 euros.',
                'evidence' => [(string) $budget->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertTrue($bilan['applique'], 'le run ne tombe pas');
        $this->assertSame(1, $bilan['resurrections'], 'une seule operation est ecartee');
        $this->assertSame(1, $bilan['ajoutes'], 'l autre operation du MEME run s applique');

        $actifs = DerivedKnowledgeNote::query()->claims()->active()->get();
        $this->assertCount(1, $actifs);
        $this->assertStringContainsString('486 000', (string) $actifs->first()->content);
    }

    // ──────────────────────────── REMEDIATION CODEX — gardes elargies

    /**
     * CODEX #1 — la forme la PLUS destructrice du rejeu etait la seule qui
     * passait : un RETRACT du compiler, fonde sur la vieille preuve, effacait
     * la version corrigee a la main sans jamais rien ecrire.
     */
    public function test_un_retract_automatique_fonde_sur_une_vieille_preuve_est_rejete(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);
        $sujet = (string) $claim->subject_key;

        $this->travel(2)->seconds();
        $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice, $sujet, 1,
            'Ce n est plus Vaucanson, la charpente est confiee a Lemercier.',
            'Lemercier realise la charpente du chantier Belleville.',
        );

        $corrige = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();
        $this->assertSame(2, $corrige->version, 'PREMISSE : la correction humaine a pris');

        // Le compiler veut RETRACTER la version corrigee, en citant le message
        // d'origine — anterieur a la correction.
        $this->fakePatch([
            ['op' => 'RETRACT', 'claim_id' => $sujet,
                'reason' => 'ce marche semble caduc', 'evidence' => [(string) $source->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['retractes'], 'un RETRACT automatique ne defait pas une correction humaine');
        $this->assertSame(1, $bilan['resurrections']);

        $encore = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();
        $this->assertSame((string) $corrige->id, (string) $encore->id);
        $this->assertStringContainsString('Lemercier', (string) $encore->content);
    }

    /**
     * CODEX #4 — un ADD qui REFORMULE depuis une AUTRE vieille preuve echappait
     * aux deux ancres : texte different, preuves disjointes de la frontiere.
     * La regle fail-closed le rattrape par le temps.
     */
    public function test_un_add_reformule_depuis_une_autre_vieille_preuve_est_rejete(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $autreAncien = $this->message('Rappel : le lot charpente avait ete attribue a Vaucanson en juin.');

        $claim = $this->unClaim($source);

        $this->travel(2)->seconds();
        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        // Texte neuf, preuve ANCIENNE et DIFFERENTE de celle de la frontiere.
        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Le lot charpente du chantier appartient a l entreprise Vaucanson.',
                'evidence' => [(string) $autreAncien->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['ajoutes'], 'reformuler depuis une AUTRE vieille preuve reste du passe');
        $this->assertSame(1, $bilan['resurrections']);
    }

    /**
     * CODEX #4 — le cout ASSUME du compromis fail-closed : un sujet voisin,
     * jamais corrige, cesse d'etre apprenable depuis le corpus ANCIEN des
     * qu'une correction existe dans la Boucle.
     *
     * Ce test ne decrit pas un bug : il fige un arbitrage, pour qu'on le voie
     * si quelqu'un le change.
     */
    public function test_un_sujet_voisin_ancien_est_rejete_compromis_assume(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $budgetAncien = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');

        $claim = $this->unClaim($source);

        $this->travel(2)->seconds();
        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Le budget travaux de Belleville est de 486 000 euros.',
                'evidence' => [(string) $budgetAncien->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['ajoutes'],
            'compromis fail-closed assume : le corpus ANTERIEUR ne cree plus de claim automatique');
        $this->assertSame(1, $bilan['resurrections']);
    }

    /**
     * CODEX #2 — une correction ne devient JAMAIS ressuscitable parce qu'elle
     * est ancienne. La lecture des frontieres est exhaustive : 250 corrections
     * (au-dela de l'ex-borne de 200) et la PREMIERE tient toujours.
     */
    public function test_deux_cent_cinquante_corrections_ne_font_pas_expirer_la_premiere(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $premier = $this->unClaim($source);
        $texteProtege = (string) $premier->content;

        $this->travel(2)->seconds();
        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $premier->subject_key, (int) $premier->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        // 249 autres corrections humaines, posterieures.
        $this->fabriquerCorrections(249);

        // La mesure se fait au niveau de la GARDE, et pas via le compiler :
        // 250 corrections, c'est ~500 messages, donc bien au-dela des 60 que
        // `sourceMessages()` relit. Le message d'origine sortirait de la
        // fenetre, `ClaimPatch` rejetterait la preuve AVANT la garde, et le
        // test serait vert sans avoir rien mesure. Un faux vert de plus.
        $corrigees = DerivedKnowledgeNote::query()
            ->where('organization_id', $this->organization->id)
            ->where('source_loop_id', $this->loop->id)
            ->claims()
            ->where('status', DerivedKnowledgeNote::STATUS_SUPERSEDED)
            ->get()
            ->filter(fn (DerivedKnowledgeNote $c): bool => is_array(($c->provenance ?? [])['human_correction'] ?? null))
            ->values()
            ->all();

        $this->assertGreaterThanOrEqual(250, count($corrigees),
            'PREMISSE : bien au-dela de l ancienne borne de 200');

        $frontieres = ClaimResurrectionGuard::frontieresDeVerite($corrigees);

        $this->assertSame(count($corrigees), count($frontieres),
            'AUCUNE frontiere n est ecartee : la lecture est exhaustive');

        // La toute PREMIERE frontiere, la plus ancienne, doit encore mordre.
        $rejouee = ClaimResurrectionGuard::estUneResurrection(
            ['op' => ClaimPatch::OP_UPDATE, 'claim_id' => (string) $premier->subject_key,
                'text' => $texteProtege, 'evidence' => [(string) $source->id]],
            $frontieres,
            [(string) $source->id => $source->fresh()],
        );

        $this->assertTrue($rejouee, 'une frontiere ne s evapore pas avec l age');
    }

    /**
     * CODEX #2 — meme propriete, mais noyee sous des supersessions qui ne sont
     * PAS des corrections humaines : ce sont elles qui remplissaient la borne
     * SQL et poussaient les frontieres dehors.
     */
    public function test_six_cents_supersessions_machine_ne_noient_pas_une_frontiere(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $premier = $this->unClaim($source);
        $texteProtege = (string) $premier->content;

        $this->travel(2)->seconds();
        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $premier->subject_key, (int) $premier->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        // Les supersessions machine doivent etre strictement PLUS RECENTES
        // que la correction : c'est ce qui les place devant elle dans un
        // `orderByDesc('superseded_at')` et la pousse hors d'une limite SQL.
        // Sans cet ecart, elles partagent sa seconde, l'ordre est arbitraire,
        // et le test passerait meme avec la borne — sans rien prouver.
        $this->travel(30)->seconds();
        $this->fabriquerSupersessionsMachine(600);

        $this->assertGreaterThanOrEqual(600, DerivedKnowledgeNote::query()->claims()
            ->where('status', DerivedKnowledgeNote::STATUS_SUPERSEDED)->count(), 'PREMISSE');

        $this->fakePatch([
            ['op' => 'ADD', 'text' => $texteProtege, 'evidence' => [(string) $source->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['ajoutes'],
            'la frontiere se lit meme sous 600 supersessions machine');
        $this->assertGreaterThanOrEqual(1, $bilan['resurrections']);
    }

    // ─────────────────────────────────────── ordre canonique / temporalite

    /**
     * ADDENDUM ORDERING — le tie-break par UUID est TOTAL, pas TEMPOREL.
     *
     * `created_at` est stocke a la seconde. Un message de la MEME seconde que
     * la correction ne fait donc pas autorite contre elle : s'en remettre a
     * l'identifiant reviendrait a laisser un tirage decider, une fois sur deux.
     *
     * L'horloge est GELEE pour que les deux ecritures tombent dans la meme
     * seconde a coup sur — sans quoi le test dependrait du moment ou il tourne.
     */
    public function test_un_message_de_la_meme_seconde_que_la_correction_ne_fait_pas_autorite(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);
        $texte = (string) $claim->content;

        $this->travelTo(Carbon::parse('2026-09-12 14:30:00'));

        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        // Ecrit APRES la correction en horloge murale, mais dans la MEME
        // seconde : la base ne peut pas les distinguer.
        $memeSeconde = $this->message('En fait Vaucanson reste sur le lot charpente.');

        $frontiere = ($claim->fresh()->provenance ?? [])['human_correction'] ?? [];
        $this->assertSame(
            $memeSeconde->created_at?->format('Y-m-d H:i:s'),
            substr((string) ($frontiere['position'][0] ?? ''), 0, 19),
            'PREMISSE : les deux tombent bien dans la meme seconde',
        );

        $this->fakePatch([
            ['op' => 'ADD', 'text' => $texte, 'evidence' => [(string) $memeSeconde->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['ajoutes'], 'a egalite de seconde, la correction tient : fail closed');
        $this->assertSame(1, $bilan['resurrections']);
    }

    /**
     * ADDENDUM ORDERING — c'est la DERNIERE correction qui fait frontiere.
     */
    public function test_la_correction_la_plus_recente_est_la_frontiere_active(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);
        $sujet = (string) $claim->subject_key;

        $this->travel(2)->seconds();
        $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice, $sujet, 1,
            'Ce n est plus Vaucanson, la charpente est confiee a Lemercier.',
            'Lemercier realise la charpente du chantier Belleville.',
        );

        // Un message ecrit ENTRE les deux corrections.
        $this->travel(2)->seconds();
        $entreLesDeux = $this->message('Petit doute sur le lot charpente, a reverifier avec le maitre d oeuvre.');

        $this->travel(2)->seconds();
        $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice, $sujet, 2,
            'Correction : finalement c est Charpentier SA qui reprend le lot.',
            'Charpentier SA realise la charpente du chantier Belleville.',
        );

        $actif = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();
        $this->assertSame(3, $actif->version, 'PREMISSE : deux corrections successives');

        // Posterieur a la PREMIERE frontiere, anterieur a la SECONDE.
        $this->fakePatch([
            ['op' => 'UPDATE', 'claim_id' => $sujet,
                'text' => 'Lemercier realise la charpente du chantier Belleville.',
                'evidence' => [(string) $entreLesDeux->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['modifies'], 'la frontiere ACTIVE est la plus recente');
        $this->assertSame(1, $bilan['resurrections']);
        $this->assertStringContainsString('Charpentier SA',
            (string) DerivedKnowledgeNote::query()->claims()->active()->firstOrFail()->content);
    }

    /**
     * CODEX #3 — une frontiere ANCIENNE n'accorde jamais ce que la plus
     * recente refuserait.
     *
     * Le cas se voit quand une operation touche un sujet par son TEXTE et non
     * par son identite : elle apparie alors la vieille frontiere d'un AUTRE
     * sujet. Si la barre etait celle de la frontiere appariee, une preuve
     * posterieure a T1 mais anterieure a T2 passerait — alors que le sujet
     * qu'elle rejoue a ete corrige DEUX fois.
     *
     * La mesure se fait sur la garde : monter ce cas par le compiler
     * demanderait de lui faire produire une collision de texte entre deux
     * sujets, ce qui testerait le double, pas la regle.
     */
    public function test_une_vieille_frontiere_n_accorde_pas_ce_que_la_recente_refuse(): void
    {
        $ancien = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');

        $this->travel(2)->seconds();
        $entreLesDeux = $this->message('Point d etape : la charpente reste a confirmer avec le maitre d oeuvre.');

        $texteRetracte = 'Vaucanson realise la charpente, avec une hausse de 12%.';

        // Deux frontieres sur le MEME sujet : T1 porte l'empreinte du texte
        // retracte, T2 est plus recente.
        $frontieres = [
            ['subject_key' => 'sujet-A', 'text_hash' => ClaimResurrectionGuard::empreinteTexte($texteRetracte),
                'evidence_ids' => [(string) $ancien->id],
                'position' => ClaimResurrectionGuard::position($ancien->fresh())],
            ['subject_key' => 'sujet-A', 'text_hash' => ClaimResurrectionGuard::empreinteTexte('un autre texte deja ecarte'),
                'evidence_ids' => [], 'position' => ['2999-01-01 00:00:00.000000', (string) $entreLesDeux->id]],
        ];

        // Une operation sur un AUTRE sujet, qui rejoue le texte de sujet-A
        // avec une preuve posterieure a T1 — mais anterieure a T2.
        $rejouee = ClaimResurrectionGuard::estUneResurrection(
            ['op' => ClaimPatch::OP_UPDATE, 'claim_id' => 'sujet-B',
                'text' => $texteRetracte, 'evidence' => [(string) $entreLesDeux->id]],
            $frontieres,
            [(string) $entreLesDeux->id => $entreLesDeux->fresh()],
        );

        $this->assertTrue($rejouee,
            'la barre est celle de la DERNIERE correction du sujet, pas de la frontiere appariee');
    }

    /**
     * ADDENDUM ORDERING — deux corrections humaines tres rapprochees (meme
     * seconde) s'appliquent toutes les deux : la garde ne vise QUE le compiler.
     */
    public function test_deux_corrections_tres_rapprochees_s_appliquent_toutes_les_deux(): void
    {
        $claim = $this->unClaim();
        $sujet = (string) $claim->subject_key;

        $this->travelTo(Carbon::parse('2026-09-12 15:00:00'));

        $un = $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice, $sujet, 1,
            'Ce n est plus Vaucanson, la charpente est confiee a Lemercier.',
            'Lemercier realise la charpente du chantier Belleville.',
        );

        $deux = $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice, $sujet, 2,
            'Correction : finalement c est Charpentier SA qui reprend le lot.',
            'Charpentier SA realise la charpente du chantier Belleville.',
        );

        $this->assertTrue($un['ok']);
        $this->assertTrue($deux['ok'], 'une correction humaine n est jamais bornee par une autre');

        $actif = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();
        $this->assertSame(3, $actif->version);
        $this->assertStringContainsString('Charpentier SA', (string) $actif->content);
    }

    /**
     * ADDENDUM ORDERING — deux corrections CONCURRENTES sur la meme base : le
     * perdant est stale, sans second message et sans ACK.
     */
    public function test_deux_corrections_concurrentes_sur_la_meme_base_le_perdant_est_stale(): void
    {
        $claim = $this->unClaim();
        $sujet = (string) $claim->subject_key;
        $base = (int) $claim->version;

        $bob = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bob Vasseur']);
        $this->membre($this->loop->fresh(), $bob);

        // Les deux ont LU la meme version avant d'agir.
        $gagnant = $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice, $sujet, $base,
            'Ce n est plus Vaucanson, la charpente est confiee a Lemercier.',
            'Lemercier realise la charpente du chantier Belleville.',
        );

        $messages = LoopMessage::where('loop_id', $this->loop->id)->count();

        $perdant = $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $bob, $sujet, $base,
            'Non, c est Charpentier SA qui reprend le lot charpente.',
            'Charpentier SA realise la charpente du chantier Belleville.',
        );

        $this->assertTrue($gagnant['ok']);
        $this->assertFalse($perdant['ok'], 'le perdant n obtient JAMAIS un ACK');
        $this->assertSame('version_perimee', $perdant['raison']);
        $this->assertNull($perdant['message_id'], 'et il n ecrit AUCUN message');
        $this->assertSame($messages, LoopMessage::where('loop_id', $this->loop->id)->count());

        // Une seule mutation a eu lieu.
        $actif = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();
        $this->assertSame(2, $actif->version);
        $this->assertStringContainsString('Lemercier', (string) $actif->content);
    }

    // ────────────────────────────────────────────── idempotence

    /**
     * ADDENDUM — une correction rejouee (double clic, requete relancee) ne doit
     * ecrire NI second message, NI seconde mutation.
     */
    public function test_une_correction_rejouee_n_ecrit_ni_second_message_ni_seconde_mutation(): void
    {
        $claim = $this->unClaim();

        $premier = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertTrue($premier['ok']);

        $messages = LoopMessage::where('loop_id', $this->loop->id)->count();
        $lignes = DerivedKnowledgeNote::query()->claims()->count();

        // LE MEME appel, rejoue a l'identique.
        $second = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertFalse($second['ok'], 'jamais un ACK positif pour une action deja appliquee');
        $this->assertSame('claim_introuvable', $second['raison']);
        $this->assertNull($second['message_id'], 'aucun SECOND message de correction');

        $this->assertSame($messages, LoopMessage::where('loop_id', $this->loop->id)->count());
        $this->assertSame($lignes, DerivedKnowledgeNote::query()->claims()->count());
    }

    public function test_une_correction_visant_une_version_deja_remplacee_est_refusee_sans_trace(): void
    {
        $claim = $this->unClaim();

        $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Ce n est plus Vaucanson, la charpente est confiee a Lemercier.',
            'Lemercier realise la charpente du chantier Belleville.',
        );

        $messages = LoopMessage::where('loop_id', $this->loop->id)->count();

        // Une seconde personne corrigeait la version 1, qu'elle avait lue avant.
        $tardif = $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, 1,
            'Non, c est Charpentier SA qui reprend le lot.',
            'Charpentier SA realise la charpente du chantier Belleville.',
        );

        $this->assertFalse($tardif['ok']);
        $this->assertSame('version_perimee', $tardif['raison']);
        $this->assertNull($tardif['message_id'], 'une action perimee ne laisse AUCUNE trace');
        $this->assertSame($messages, LoopMessage::where('loop_id', $this->loop->id)->count());

        // Et la version en place est intacte.
        $actif = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();
        $this->assertSame(2, $actif->version);
        $this->assertStringContainsString('Lemercier', (string) $actif->content);
    }

    /**
     * ADDENDUM 1 — la frontiere se retrouve depuis le lignage PERSISTE, apres
     * RETRACT comme apres UPDATE, et survit a une recompilation.
     */
    public function test_la_frontiere_se_retrouve_dans_le_lignage_persiste(): void
    {
        $source = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
        $claim = $this->unClaim($source);

        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        // Plus AUCUN enonce actif sur ce sujet : la ligne archivee est le seul
        // endroit qui puisse porter la frontiere.
        $this->assertSame(0, DerivedKnowledgeNote::query()->claims()->active()->count());

        $archive = DerivedKnowledgeNote::findOrFail($claim->id);
        $frontiere = ($archive->provenance ?? [])['human_correction'] ?? null;

        $this->assertIsArray($frontiere);
        $this->assertSame((string) $this->alice->id, $frontiere['by'] ?? null);
        $this->assertNotSame('', (string) ($frontiere['text_hash'] ?? ''));
        $this->assertCount(2, $frontiere['position'] ?? [], 'la position canonique (created_at, id)');
        $this->assertContains((string) $source->id, $frontiere['evidence_ids'] ?? []);

        // Elle survit a une recompilation : la garde mord encore ensuite.
        $this->fakePatch([
            ['op' => 'ADD', 'text' => (string) $claim->content, 'evidence' => [(string) $source->id]],
        ]);
        app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertIsArray((DerivedKnowledgeNote::findOrFail($claim->id)->provenance ?? [])['human_correction'] ?? null,
            'la frontiere n est jamais effacee par une recompilation');
        $this->assertSame(0, DerivedKnowledgeNote::query()->claims()->active()->count());
    }

    // ────────────────────────────────────────────── retrieval

    public function test_le_retrieval_suivant_ne_ressort_pas_un_claim_retracte(): void
    {
        $claim = $this->unClaim();

        $this->assertGreaterThan(0, DossierChunk::where('derived_knowledge_note_id', $claim->id)->count(),
            'PREMISSE : l enonce etait bien indexe avant la correction');

        $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertSame(0, DossierChunk::where('derived_knowledge_note_id', $claim->id)->count(),
            'un enonce retracte sort de l index, sans quoi le retrieval le servirait encore');
    }

    // ────────────────────────────────────────────── aucun modele

    public function test_le_chemin_de_correction_n_appelle_aucun_modele(): void
    {
        $claim = $this->unClaim();

        // Le ledger est la trace REELLE d'une invocation de provider : il est
        // ecrit par `LoopClaimCompiler` a chaque appel, succes comme echec.
        // Le compter avant/apres mesure la depense, pas l'intention.
        $avant = AiProviderInvocation::count();
        $this->assertGreaterThan(0, $avant, 'PREMISSE : compiler une memoire coute bien un appel');

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertTrue($resultat['ok']);
        $this->assertSame($avant, AiProviderInvocation::count(),
            'corriger ne doit RIEN facturer : aucun modele sur ce chemin');
    }

    // ────────────────────────────────────────────── concurrence

    /**
     * `base_perimee` mesure sur la VRAIE primitive, sans double : un patch
     * parti d'un etat qui n'existe plus ne gagne pas, et ne mute rien.
     */
    public function test_une_base_perimee_refuse_le_patch_sans_rien_muter(): void
    {
        $claim = $this->unClaim();
        $message = $this->message('Non, ce marche charpente a ete annule la semaine derniere.');

        $memory = app(ClaimMemory::class);
        $perimee = $memory->empreinte([]); // l'empreinte d'une memoire VIDE : jamais celle d'ici

        $patch = ClaimPatch::valider(
            [['op' => 'RETRACT', 'claim_id' => (string) $claim->subject_key,
                'reason' => 'annule', 'evidence' => [(string) $message->id]]],
            [(string) $claim->subject_key],
            [(string) $message->id],
        );

        $bilan = $memory->appliquer(
            $this->organization, $this->loop->fresh(),
            (string) $claim->dossier_id, $patch, [$message], $perimee, null,
            ClaimWriteOrigin::humanCorrection($this->alice, $message),
        );

        $this->assertFalse($bilan['applique']);
        $this->assertSame('base_perimee', $bilan['raison']);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE,
            DerivedKnowledgeNote::findOrFail($claim->id)->status,
            'un patch perdant ne mute RIEN');
    }

    /**
     * La concurrence reelle : l'enonce disparait pendant que la correction
     * s'ecrit. `LoopMessageCreated` est emis PAR le chemin de correction
     * lui-meme, entre la premiere verification et la relecture — c'est donc
     * une vraie fenetre, pas une mise en scene.
     */
    public function test_un_claim_disparu_pendant_l_ecriture_ne_produit_jamais_un_accuse_mensonger(): void
    {
        $claim = $this->unClaim();

        Event::listen(
            LoopMessageCreated::class,
            function () use ($claim): void {
                DerivedKnowledgeNote::where('id', $claim->id)->update([
                    'status' => DerivedKnowledgeNote::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                ]);
            },
        );

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertFalse($resultat['ok'], 'jamais « correction enregistree » quand elle ne l est pas');
        $this->assertSame('claim_introuvable', $resultat['raison']);

        // Le message humain, lui, EXISTE : la personne l'a dit. L'effacer pour
        // masquer un echec technique reecrirait l'histoire humaine.
        $this->assertNotNull($resultat['message_id']);
        $this->assertNotNull(LoopMessage::find($resultat['message_id']));
    }

    // ────────────────────────── REMEDIATION CODEX — agent, providers, acteur

    /**
     * CODEX #6 — dans une Boucle `ai_agent`, le message de correction reste
     * VISIBLE, mais ne fait pas parler l'agent : il s'adresse a la memoire.
     */
    public function test_dans_une_boucle_ai_agent_la_correction_ne_declenche_pas_l_agent(): void
    {
        $claim = $this->unClaim();

        $this->loop->forceFill(['type' => 'ai_agent'])->save();
        $this->assertTrue($this->loop->fresh()->isAiAgent(), 'PREMISSE : la Boucle est bien une Boucle agent');

        Queue::fake();

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertTrue($resultat['ok']);
        Queue::assertNotPushed(GenerateAiAgentResponse::class);

        // Et il reste un message humain ordinaire, visible.
        $message = LoopMessage::findOrFail($resultat['message_id']);
        $this->assertSame('user', (string) $message->type);
        $this->assertNull($message->deleted_at);
        $this->assertTrue($message->isClaimCorrection());
    }

    /**
     * CODEX #6 — la garde ne doit pas faire taire l'agent pour TOUT message :
     * un message humain ordinaire le declenche toujours.
     */
    public function test_un_message_ordinaire_declenche_toujours_l_agent(): void
    {
        $this->loop->forceFill(['type' => 'ai_agent'])->save();

        Queue::fake();

        app(LoopMessageService::class)->sendUserMessage(
            $this->loop->fresh(), $this->alice, 'Une question tout a fait ordinaire pour l agent.',
        );

        Queue::assertPushed(GenerateAiAgentResponse::class);
    }

    /**
     * CODEX #5 — l'accuse de reception ne depend d'AUCUN fournisseur externe.
     *
     * Mesure separee : zero appel GENERATIF, et zero appel d'EMBEDDING avant
     * l'ACK. L'indexation est mise en file, pas executee.
     */
    public function test_aucun_appel_de_fournisseur_avant_l_accuse_de_reception(): void
    {
        $claim = $this->unClaim();

        $generatifsAvant = AiProviderInvocation::count();
        $this->assertGreaterThan(0, $generatifsAvant, 'PREMISSE : compiler coute bien un appel');

        $embeddings = 0;
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$embeddings): array {
            $embeddings++;

            return array_map(fn (): array => array_fill(0, 1536, 0.01), $prompt->inputs);
        })->preventStrayEmbeddings();

        Queue::fake();

        $resultat = $this->correction()->mettreAJour(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Ce n est plus Vaucanson, la charpente est confiee a Lemercier.',
            'Lemercier realise la charpente du chantier Belleville.',
        );

        $this->assertTrue($resultat['ok'], 'la correction est acquise');

        // GENERATIVE_PROVIDER_CALLS = 0
        $this->assertSame($generatifsAvant, AiProviderInvocation::count());

        // EMBEDDING_PROVIDER_CALLS_BEFORE_ACK = 0
        $this->assertSame(0, $embeddings, 'aucun embedding sur le chemin critique');

        // Le vecteur SUIT : l'indexation est mise en file, pas oubliee.
        Queue::assertPushed(IndexDerivedKnowledgeNote::class);

        // Et la mutation, elle, est bien commitee.
        $this->assertSame(2, DerivedKnowledgeNote::query()->claims()->active()->firstOrFail()->version);
    }

    /**
     * CODEX #5 — un fournisseur d'embeddings en panne ne doit jamais faire
     * croire que la correction a echoue, ni l'annuler.
     */
    public function test_un_fournisseur_d_embeddings_en_panne_n_annule_pas_la_correction(): void
    {
        $claim = $this->unClaim();

        Embeddings::fake(function (): array {
            throw new \RuntimeException('fournisseur injoignable');
        })->preventStrayEmbeddings();

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertTrue($resultat['ok'], 'le commit memoire fait foi, pas le vecteur');
        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED,
            DerivedKnowledgeNote::findOrFail($claim->id)->status);
    }

    /**
     * CODEX #8 — la provenance ne peut pas nommer quelqu'un qui n'a rien ecrit.
     */
    public function test_un_acteur_incoherent_est_refuse(): void
    {
        $claim = $this->unClaim();
        $bob = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bob Vasseur']);
        $this->membre($this->loop->fresh(), $bob);

        // Le service ecrit le message AU NOM de l'utilisateur passe ; on force
        // l'incoherence en faisant signer le message par quelqu'un d'autre.
        Event::listen(LoopMessageCreated::class, function (LoopMessageCreated $event) use ($bob): void {
            LoopMessage::where('id', $event->id)->update(['sender_id' => $bob->id]);
        });

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertSame('acteur_incoherent', $resultat['raison']);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE,
            DerivedKnowledgeNote::findOrFail($claim->id)->status, 'aucune mutation');
    }

    /**
     * CODEX #7 — un droit revoque entre la premiere garde et la mutation ne
     * passe pas : les gardes sont rejouees au plus pres du verrou.
     */
    public function test_un_droit_revoque_pendant_l_ecriture_ne_mute_rien(): void
    {
        $claim = $this->unClaim();

        // L'adhesion est revoquee au moment meme ou le message s'ecrit.
        Event::listen(LoopMessageCreated::class, function (): void {
            LoopMember::where('loop_id', $this->loop->id)
                ->where('user_id', $this->alice->id)
                ->update(['status' => 'revoked']);
        });

        $resultat = $this->correction()->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, la charpente n est pas confiee a Vaucanson, ce marche a ete annule.',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertSame('droit_revoque', $resultat['raison']);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE,
            DerivedKnowledgeNote::findOrFail($claim->id)->status);
    }

    // ────────────────────────────────────────────────────── mise en place

    /**
     * Des corrections humaines REELLES, par le chemin reel — jamais des lignes
     * fabriquees : une frontiere inseree a la main ne prouverait pas que le
     * chemin la pose.
     */
    private function fabriquerCorrections(int $combien): void
    {
        for ($i = 0; $i < $combien; $i++) {
            $this->travel(2)->seconds();
            $source = $this->message("Point numero {$i} : le lot {$i} est confie au prestataire {$i}.");

            $this->fakePatch([
                ['op' => 'ADD', 'text' => "Le lot {$i} est confie au prestataire {$i} sur ce chantier.",
                    'evidence' => [(string) $source->id]],
            ]);
            app(LoopClaimCompiler::class)->compile($this->loop->fresh());

            $claim = DerivedKnowledgeNote::query()->claims()->active()
                ->where('content', 'like', "Le lot {$i} %")->first();

            if ($claim === null) {
                continue;
            }

            $this->travel(2)->seconds();
            $this->correction()->retracter(
                $this->organization, $this->loop->fresh(), $this->alice,
                (string) $claim->subject_key, (int) $claim->version,
                "Non, le lot {$i} n est plus confie a ce prestataire, marche annule.",
            );
        }
    }

    /**
     * Des supersessions qui ne sont PAS des corrections humaines : exactement
     * ce qui remplissait la borne SQL et poussait les frontieres dehors.
     */
    private function fabriquerSupersessionsMachine(int $combien): void
    {
        $dossierId = app(DerivedChunkEligibility::class)
            ->rootDossierIdFor($this->loop->fresh());

        for ($i = 0; $i < $combien; $i++) {
            DerivedKnowledgeNote::create([
                'organization_id' => $this->organization->id,
                'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
                'kind' => DerivedKnowledgeNote::KIND_CLAIM,
                'source_loop_id' => $this->loop->id,
                'dossier_id' => $dossierId,
                'subject_key' => 'machine-'.$i,
                'content' => "Enonce machine numero {$i}, remplace par le compiler.",
                'source_fingerprint' => hash('sha256', 'machine-'.$i),
                'provenance' => ['derived_by' => 'loop_conversation_knowledge', 'source_loop_message_ids' => []],
                'observed_at' => now(),
                'derived_at' => now(),
                'version' => 1,
                'status' => DerivedKnowledgeNote::STATUS_SUPERSEDED,
                'superseded_at' => now(),
            ]);
        }
    }

    private function correction(): HumanClaimCorrection
    {
        return app(HumanClaimCorrection::class);
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

    private function message(string $body): LoopMessage
    {
        return LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => $body,
            'type' => 'user',
        ]);
    }

    /**
     * Un enonce reel, compile par le chemin reel — jamais insere a la main :
     * une ligne fabriquee ne porterait pas la provenance dont la garde depend.
     */
    private function unClaim(?LoopMessage $source = null): DerivedKnowledgeNote
    {
        $source ??= $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');

        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Vaucanson realise la charpente, avec une hausse de 12%.',
                'evidence' => [(string) $source->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertTrue($bilan['applique'], 'PREMISSE : la compilation initiale doit reussir');
        $this->assertSame(1, $bilan['ajoutes'], 'PREMISSE : un enonce doit exister');

        return DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    private function reponse(array $operations): TextResponse
    {
        return new TextResponse(
            (string) json_encode(['operations' => $operations], JSON_UNESCAPED_UNICODE),
            new Usage(60, 40), new Meta('openrouter', 'openai/gpt-4o-mini'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    private function fakePatch(array $operations): void
    {
        LoopClaimPatchAgent::fake(fn (): TextResponse => $this->reponse($operations));
    }
}
