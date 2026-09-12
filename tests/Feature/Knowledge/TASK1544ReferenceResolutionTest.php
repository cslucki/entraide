<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Context\ReferenceQuestionShape;
use App\Models\AiShellMessage;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Knowledge\LoopReferenceResolver;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1544 — « Le projet dont Marin parlait mardi, ça avance ? »
 *
 * ## La reference se resout par la PROVENANCE, pas par le texte
 *
 * Le banc de T1541 a mesure le probleme de face : sur quatre enonces compiles
 * par un vrai modele, UN SEUL nommait le projet. Une consigne d'ancrage
 * explicite a ete mesuree puis RETIREE — elle ne deplacait rien.
 *
 * Et elle n'aurait de toute facon pas resolu « dont Marin parlait » : le nom de
 * l'auteur n'est pas dans le texte de l'enonce, il est dans sa provenance.
 * `source_loop_message_ids` pointe des messages, un message a un `sender_id`,
 * un enonce porte son `observed_at`. Les trois signaux sont deja structures.
 *
 * ## Les trois regles que ces tests protegent
 *
 *  1. **l'univers est celui du DEMANDEUR.** Nommer quelqu'un ne donne aucun
 *     droit sur ce qu'il voit ;
 *  2. **l'ambiguite ne se tranche pas.** Deux projets plausibles restent deux
 *     projets. Ce n'est pas une consigne qu'un modele pourrait mal suivre : il
 *     n'existe aucune branche qui choisisse ;
 *  3. **une reference DIRECTE n'est pas interceptee.** « Comment avance ARIA ? »
 *     nomme son sujet, la recherche documentaire y repond deja, et
 *     l'intercepter degraderait une question qui marche.
 */
class TASK1544ReferenceResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    /** Celle qui pose la question. */
    private User $camille;

    /** Celui dont on parle. */
    private User $marin;

    private Loop $aria;

    private Loop $revive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->organization);

        $this->camille = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Camille Dubreuil']);
        $this->marin = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Marin Delcourt']);

        $this->aria = $this->boucle('ARIA');
        $this->revive = $this->boucle('REVIVE');

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1544',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    // ──────────────────────────────── la forme de la question

    public function test_une_reference_directe_n_est_jamais_interceptee(): void
    {
        // Ces questions NOMMENT leur sujet : la recherche documentaire y repond
        // deja, et les intercepter degraderait ce qui marche.
        foreach ([
            'Comment avance ARIA ?',
            'Quel est le budget de REVIVE ?',
            'Quels fichiers sont disponibles ?',
            'Que dit le compte rendu sur la toiture ?',
        ] as $question) {
            $this->assertFalse(ReferenceQuestionShape::isIndirect($question), $question);
        }

        foreach ([
            'Le projet dont Marin parlait mardi, ça avance ?',
            'Le projet de Marin, où en est-il ?',
            'Celui dont Marin parlait la semaine dernière ?',
            'How is the project Marin mentioned going?',
        ] as $question) {
            $this->assertTrue(ReferenceQuestionShape::isIndirect($question), $question);
        }
    }

    public function test_une_question_qui_nomme_une_personne_sans_designer_de_projet_n_est_pas_interceptee(): void
    {
        // LE cas que le sabotage a montre non mesure : sans la garde de
        // reference INDIRECTE, cette question serait detournee vers la
        // resolution — alors qu'elle ne designe aucun projet et que la
        // recherche documentaire, elle, sait y repondre.
        //
        // Le filtre de personne ne suffit pas a proteger ce cas : le nom EST
        // dans la phrase.
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(2));

        $this->assertFalse(ReferenceQuestionShape::isIndirect('Marin a-t-il validé le budget ?'));

        $resolution = app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille, 'Marin a-t-il validé le budget ?',
        );

        $this->assertSame([], $resolution['candidats'],
            'une question qui nomme quelqu un sans designer de projet n est pas une reference indirecte');

        $tour = $this->demander('Marin a-t-il validé le budget ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_REFERENCE_RESOLUTION, $tour->metadata['producer'] ?? null,
            'la branche ne doit pas prendre la main sur une question qu elle ne resout pas');
    }

    // ──────────────────────────────── la resolution

    public function test_le_projet_dont_marin_parlait_se_resout_par_l_auteur_de_la_preuve(): void
    {
        // Marin a etabli un fait dans ARIA. Camille a etabli un fait dans
        // REVIVE. Les deux enonces sont dans l'univers de Camille, et AUCUN des
        // deux ne nomme son projet — c'est le cas mesure en T1541.
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(2));
        $this->enonce($this->revive, $this->camille, 'La charpente est confiee a Vaucanson.', now()->subDays(2));

        $resolution = app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille, 'Le projet dont Marin parlait, ça avance ?',
        );

        $this->assertSame((string) $this->marin->id, (string) $resolution['personne']?->id);
        $this->assertCount(1, $resolution['candidats']);
        $this->assertSame('ARIA', $resolution['candidats'][0]['loop_name']);
        $this->assertStringContainsString('486 000', $resolution['candidats'][0]['enonces'][0]['texte']);
    }

    public function test_le_reperage_temporel_ecarte_ce_qui_a_ete_dit_avant(): void
    {
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subMonths(4));
        $this->enonce($this->revive, $this->marin, 'La charpente est confiee a Vaucanson.', now()->subDay());

        // « depuis hier » : un seul des deux projets peut repondre.
        $resolution = app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille,
            'Le projet dont Marin parlait depuis hier, ça avance ?',
        );

        $this->assertCount(1, $resolution['candidats']);
        $this->assertSame('REVIVE', $resolution['candidats'][0]['loop_name']);
    }

    public function test_une_personne_inconnue_ne_resout_rien_et_n_en_invente_aucune(): void
    {
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(2));

        $resolution = app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille, 'Le projet dont Gwendoline parlait ?',
        );

        $this->assertNull($resolution['personne'],
            'l univers des personnes est une donnee du serveur, jamais une deduction sur le texte');
        $this->assertSame([], $resolution['candidats']);
    }

    public function test_deux_homonymes_rendent_la_reference_irresolvable(): void
    {
        // Resoudre sur l'un des deux au hasard serait pire que ne rien
        // resoudre : la personne ne saurait pas qu'on a choisi.
        User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Marin Aubertin']);

        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(2));

        $resolution = app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille, 'Le projet dont Marin parlait ?',
        );

        $this->assertNull($resolution['personne']);
        $this->assertSame([], $resolution['candidats']);
    }

    public function test_un_nom_complet_reste_lisible_quand_un_jeton_est_partage(): void
    {
        // Mesure sur un tenant reel : trois membres partageaient le jeton
        // « SENTINEL » de leur nom d'organisation. Une garde d'homonymie qui
        // se contente de COMPTER les correspondances declarait alors ambigu un
        // nom complet et unique, que personne n'aurait hesite a lire.
        //
        // C'est le score qui tranche, et l'ambiguite ne subsiste que sur une
        // EGALITE a ce score.
        $organisation = $this->organization->id;
        User::factory()->create(['organization_id' => $organisation, 'name' => 'SENTINEL Membre Un']);
        User::factory()->create(['organization_id' => $organisation, 'name' => 'SENTINEL Membre Deux']);
        $admin = User::factory()->create(['organization_id' => $organisation, 'name' => 'SENTINEL Admin']);

        LoopMember::create([
            'organization_id' => $organisation, 'loop_id' => $this->aria->id,
            'user_id' => $admin->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->enonce($this->aria, $admin, 'Le budget travaux est de 486 000 euros.', now()->subDays(2));

        $resolution = app(LoopReferenceResolver::class)->resoudre(
            (string) $organisation, $this->camille, 'Le projet dont SENTINEL Admin parlait ?',
        );

        $this->assertSame((string) $admin->id, (string) $resolution['personne']?->id,
            'deux jetons partages ne rendent pas un nom complet illisible');
        $this->assertCount(1, $resolution['candidats']);

        // Et la garde d'homonymie tient toujours : le jeton SEUL reste ambigu.
        $this->assertNull(app(LoopReferenceResolver::class)->resoudre(
            (string) $organisation, $this->camille, 'Le projet dont SENTINEL parlait ?')['personne']);
    }

    // ──────────────────────────────── l'univers est celui du DEMANDEUR

    public function test_nommer_quelqu_un_ne_donne_aucun_acces_a_ses_boucles(): void
    {
        // Marin parle dans une Boucle ou Camille n'est PAS membre.
        $privee = $this->boucle('Commission confidentielle', avecCamille: false);
        $this->enonce($privee, $this->marin, 'Le montant negocie est de 900 000 euros.', now()->subDay());

        $resolution = app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille, 'Le projet dont Marin parlait ?',
        );

        $this->assertSame([], $resolution['candidats'],
            'l univers autorise est celui du DEMANDEUR, jamais celui de la personne citee');
    }

    public function test_un_membre_qui_quitte_la_boucle_perd_la_reference_au_tour_suivant(): void
    {
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(2));

        $this->assertCount(1, app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille, 'Le projet dont Marin parlait ?')['candidats'],
            'PREMISSE : membre active, la reference se resout');

        LoopMember::where('loop_id', $this->aria->id)->where('user_id', $this->camille->id)
            ->update(['status' => 'left']);

        $this->assertSame([], app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille->fresh(), 'Le projet dont Marin parlait ?')['candidats']);
    }

    public function test_aucune_reference_ne_franchit_la_frontiere_du_tenant(): void
    {
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(2));

        $autre = Organization::factory()->create(['is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autre->id, 'name' => 'Marin Etranger']);

        $this->assertSame([], app(LoopReferenceResolver::class)->resoudre(
            (string) $autre->id, $etranger, 'Le projet dont Marin parlait ?')['candidats']);
        $this->assertSame([], app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $etranger, 'Le projet dont Marin parlait ?')['candidats']);
    }

    // ──────────────────────────────── l'ambiguite ne se tranche pas

    public function test_deux_projets_plausibles_restent_deux_projets(): void
    {
        // LE cas critique du mandat : ARIA et REVIVE tous deux plausibles.
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(3));
        $this->enonce($this->revive, $this->marin, 'La charpente est confiee a Vaucanson.', now()->subDays(2));

        $resolution = app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille, 'Le projet dont Marin parlait ?',
        );

        $this->assertCount(2, $resolution['candidats'],
            'le systeme NE DOIT PAS choisir arbitrairement entre deux references plausibles');

        $noms = array_column($resolution['candidats'], 'loop_name');
        sort($noms);
        $this->assertSame(['ARIA', 'REVIVE'], $noms);
    }

    // ──────────────────────────────── le vrai Shell

    public function test_le_shell_nomme_le_projet_resolu_sans_appeler_le_moindre_modele(): void
    {
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(2));

        $tour = $this->demander('Le projet dont Marin parlait, ça avance ?');

        $this->assertSame(AiShellResponder::PRODUCER_REFERENCE_RESOLUTION, $tour->metadata['producer'] ?? null);
        $this->assertStringContainsString('ARIA', $tour->content);
        $this->assertStringContainsString('486 000', $tour->content);
        $this->assertFalse($tour->metadata['reference']['ambiguous'] ?? true);

        // Aucun appel : la reponse n'est pas generee, elle est LUE.
        $this->assertSame(0, DB::table('ai_provider_invocations')->count());
    }

    public function test_le_shell_rend_la_question_quand_deux_projets_repondent(): void
    {
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(3));
        $this->enonce($this->revive, $this->marin, 'La charpente est confiee a Vaucanson.', now()->subDays(2));

        $tour = $this->demander('Le projet dont Marin parlait, ça avance ?');

        $this->assertTrue($tour->metadata['reference']['ambiguous'] ?? false);
        $this->assertStringContainsString('ARIA', $tour->content);
        $this->assertStringContainsString('REVIVE', $tour->content);
        $this->assertStringContainsString('Duquel', $tour->content,
            'la question est RENDUE a la personne : aucune branche ne choisit');

        $this->assertSame(0, DB::table('ai_provider_invocations')->count());
    }

    public function test_la_correction_change_le_referent_et_conserve_le_contexte(): void
    {
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(3));
        $this->enonce($this->revive, $this->marin, 'La charpente est confiee a Vaucanson.', now()->subDays(2));

        $ambigu = $this->demander('Le projet dont Marin parlait, ça avance ?');
        $this->assertTrue($ambigu->metadata['reference']['ambiguous'] ?? false, 'PREMISSE : le tour precedent a demande de choisir');

        $corrige = $this->demander('Non, je parlais de REVIVE.');

        $this->assertSame(AiShellResponder::PRODUCER_REFERENCE_RESOLUTION, $corrige->metadata['producer'] ?? null);
        $this->assertTrue($corrige->metadata['reference']['corrected'] ?? false);
        $this->assertFalse($corrige->metadata['reference']['ambiguous'] ?? true);
        $this->assertStringContainsString('REVIVE', $corrige->content);
        $this->assertSame([(string) $this->revive->id], $corrige->metadata['reference']['candidate_loop_ids'] ?? []);
    }

    public function test_une_correction_qui_nomme_une_boucle_non_offerte_ne_corrige_rien(): void
    {
        $this->enonce($this->aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(3));
        $this->enonce($this->revive, $this->marin, 'La charpente est confiee a Vaucanson.', now()->subDays(2));

        $this->demander('Le projet dont Marin parlait, ça avance ?');

        // « Atlas » n'a jamais ete propose : rien ne doit se resoudre dessus.
        $autre = $this->boucle('Atlas');
        $this->enonce($autre, $this->marin, 'Un tout autre sujet.', now()->subDay());

        $tour = $this->demander('Non, je parlais de Atlas.');

        $this->assertNotSame(AiShellResponder::PRODUCER_REFERENCE_RESOLUTION, $tour->metadata['producer'] ?? null,
            'on ne resout que sur un candidat REELLEMENT offert au tour precedent');
    }

    // ────────────────────────────────────────────────── helpers

    private function boucle(string $nom, bool $avecCamille = true): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->marin->id,
            'name' => $nom,
            'visibility' => 'private',
        ]);

        $membres = $avecCamille ? [$this->marin, $this->camille] : [$this->marin];

        foreach ($membres as $membre) {
            LoopMember::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $loop->id,
                'user_id' => $membre->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        app(LoopRootDocumentService::class)->ensureRootDossier($loop->fresh());

        return $loop;
    }

    /**
     * Un enonce et le message humain qui l'etablit — c'est CE lien, et lui
     * seul, qui rend « dont Marin parlait » resolvable. Le texte de l'enonce
     * ne nomme jamais son projet : c'est exactement ce que T1541 a mesure.
     */
    private function enonce(Loop $loop, User $auteur, string $texte, \DateTimeInterface $quand): DerivedKnowledgeNote
    {
        $message = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $loop->id,
            'sender_id' => $auteur->id,
            'body' => $texte.' (dit en reunion)',
            'type' => 'user',
        ]);

        $message->forceFill(['created_at' => $quand, 'updated_at' => $quand])->save();

        return DerivedKnowledgeNote::create([
            'organization_id' => $this->organization->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'source_loop_id' => $loop->id,
            'dossier_id' => Dossier::where('loop_id', $loop->id)->value('id'),
            'subject_key' => (string) Str::uuid(),
            'content' => $texte,
            'source_fingerprint' => hash('sha256', $texte.$loop->id),
            'provenance' => [
                'source_loop_message_ids' => [(string) $message->id],
                'derived_by' => 'loop_conversation_knowledge',
            ],
            'observed_at' => $quand,
            'derived_at' => now(),
            'version' => 1,
            'status' => DerivedKnowledgeNote::STATUS_ACTIVE,
        ]);
    }

    private function demander(string $prompt): AiShellMessage
    {
        $this->actingAs($this->camille);

        return app(AiShellResponder::class)->respond(
            $this->organization, $this->camille, $prompt,
            ['route' => 'dashboard', 'kind' => AiShellPageContext::KIND_OTHER],
        )['answer'];
    }
}
