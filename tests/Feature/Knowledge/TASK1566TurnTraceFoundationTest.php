<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Context\DossierRerankOutcome;
use App\Ai\Context\SourceDenied;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnState;
use App\Support\Ai\AiTurnTrace;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1566 / CDC-01 V0-A — les supports canoniques du tour (PHASE 1).
 *
 * Comme `TASK1557TurnStateBoundaryTest`, ces tests n'ont **aucune fixture,
 * aucune base, aucun provider** : `AiTurnTrace`, `AiTurnReason` et
 * `AiTurnState` sont des supports purs. Un transport qui aurait besoin d'un
 * tenant en base pour etre verifie ne serait plus un transport.
 *
 * Ce qu'ils gardent, dans l'ordre de ce qui ferait le plus de degats :
 *
 *  1. **claim-once.** Une trace rendue deux fois, c'est un tour qui herite des
 *     etapes d'un autre — le defaut le plus grave possible pour une trace,
 *     parce qu'il est INVISIBLE et qu'il produit un diagnostic faux et
 *     plausible ;
 *  2. **isolation par tenant.** La cle est composite : un meme `turnId` sous
 *     deux organizations ne se croise jamais ;
 *  3. **aucune reconstruction.** Rien depose = `null`, jamais un tableau vide
 *     qui se lirait comme « mesure a zero » ;
 *  4. **la garde de NON-DEPENDANCE.** Collecte coupee, le produit se comporte
 *     a l'identique — l'observabilite ne doit jamais devenir une dependance
 *     fonctionnelle ;
 *  5. **NULL reste NULL** dans `fromTurnBlock()` : aucun defaut rassurant,
 *     aucune derivation heuristique ;
 *  6. **les alias du registre pointent l'existant** — pas une quatrieme
 *     source de verite qui divergerait en silence.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1566TurnTraceFoundationTest extends TestCase
{
    private const ORG = '00000000-0000-4000-8000-0000000000aa';

    private const ORG_B = '00000000-0000-4000-8000-0000000000bb';

    private const TURN = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── 1. claim-once

    public function test_une_trace_n_est_rendue_qu_une_seule_fois(): void
    {
        AiTurnTrace::step(self::ORG, self::TURN, 'retrieval', 'executed');

        $premier = AiTurnTrace::claim(self::ORG, self::TURN);
        $second = AiTurnTrace::claim(self::ORG, self::TURN);

        $this->assertIsArray($premier);
        $this->assertCount(1, $premier['steps']);

        // Le second appel ne rend PAS un tableau vide : il rend `null`. La
        // difference est tout sauf cosmetique — un tableau vide se lirait comme
        // « ce tour a traverse zero etage », ce qui est une mesure, alors que
        // `null` dit « rien n'a ete depose », ce qui est une absence.
        $this->assertNull($second);
    }

    public function test_un_tour_ne_peut_jamais_reclamer_la_trace_d_un_autre_tenant(): void
    {
        AiTurnTrace::step(self::ORG, self::TURN, 'retrieval', 'executed');

        // Meme `turnId`, autre organization : la cle composite doit tenir.
        $this->assertNull(AiTurnTrace::claim(self::ORG_B, self::TURN));

        // Et la trace legitime est toujours la, intacte.
        $this->assertIsArray(AiTurnTrace::claim(self::ORG, self::TURN));
    }

    public function test_un_tour_sans_depot_rend_null_et_non_un_tableau_vide(): void
    {
        $this->assertNull(AiTurnTrace::claim(self::ORG, 'turn-jamais-vu'));
    }

    // ────────────────────────────── 2. ce que le collecteur enregistre

    public function test_les_etapes_conservent_leur_ordre_d_execution(): void
    {
        AiTurnTrace::step(self::ORG, self::TURN, 'economic_check', 'executed');
        AiTurnTrace::step(self::ORG, self::TURN, 'retrieval', 'executed');
        AiTurnTrace::step(self::ORG, self::TURN, 'generation', 'executed');

        $trace = AiTurnTrace::claim(self::ORG, self::TURN);

        $this->assertSame(
            ['economic_check', 'retrieval', 'generation'],
            array_column($trace['steps'], 'name'),
        );
    }

    public function test_une_meme_etape_traversee_deux_fois_est_tracee_deux_fois(): void
    {
        // Le fallthrough documentaire du Shell traverse reellement deux fois le
        // meme etage. Ecraser par nom mentirait sur la chronologie.
        AiTurnTrace::step(self::ORG, self::TURN, 'retrieval', 'skipped', 'first_pass');
        AiTurnTrace::step(self::ORG, self::TURN, 'retrieval', 'executed');

        $trace = AiTurnTrace::claim(self::ORG, self::TURN);

        $this->assertCount(2, $trace['steps']);
        $this->assertSame('skipped', $trace['steps'][0]['status']);
        $this->assertSame('executed', $trace['steps'][1]['status']);
    }

    public function test_un_compteur_a_zero_survit_et_n_est_pas_filtre(): void
    {
        // « zero candidat trouve » est precisement la mesure qui interesse la
        // campagne : un `array_filter` la ferait disparaitre.
        AiTurnTrace::step(self::ORG, self::TURN, 'retrieval', 'executed', null, ['candidates' => 0, 'after_filter' => 0]);

        $trace = AiTurnTrace::claim(self::ORG, self::TURN);

        $this->assertSame(['candidates' => 0, 'after_filter' => 0], $trace['steps'][0]['metrics']);
    }

    public function test_les_cles_optionnelles_sont_absentes_plutot_que_nulles(): void
    {
        AiTurnTrace::step(self::ORG, self::TURN, 'generation', 'executed');

        $etape = AiTurnTrace::claim(self::ORG, self::TURN)['steps'][0];

        $this->assertArrayNotHasKey('reason_code', $etape);
        $this->assertArrayNotHasKey('metrics', $etape);
    }

    public function test_l_identite_fusionne_sans_qu_un_null_efface_une_mesure(): void
    {
        AiTurnTrace::identity(self::ORG, self::TURN, ['surface' => 'loop_chat', 'provider_effective' => 'openai']);

        // Un composant qui ne SAIT pas ne doit pas pouvoir effacer ce qu'un
        // autre a mesure.
        AiTurnTrace::identity(self::ORG, self::TURN, ['provider_effective' => null, 'mode' => 'dossiers']);

        $identity = AiTurnTrace::claim(self::ORG, self::TURN)['identity'];

        $this->assertSame('openai', $identity['provider_effective']);
        $this->assertSame('loop_chat', $identity['surface']);
        $this->assertSame('dossiers', $identity['mode']);
    }

    // ────────────────────────────── 3. garde de NON-DEPENDANCE

    public function test_collecte_coupee_le_collecteur_n_enregistre_rien(): void
    {
        AiTurnTrace::pauseCollectionForTesting();

        AiTurnTrace::identity(self::ORG, self::TURN, ['surface' => 'loop_chat']);
        AiTurnTrace::step(self::ORG, self::TURN, 'retrieval', 'executed');

        // Rien n'est depose — et surtout, rien n'a leve d'exception : un
        // produit dont la reponse dependrait de la collecte ne serait plus
        // observable, il serait couple.
        $this->assertNull(AiTurnTrace::claim(self::ORG, self::TURN));
    }

    public function test_une_identite_incomplete_ne_fait_jamais_echouer_le_depot(): void
    {
        AiTurnTrace::step('', self::TURN, 'retrieval', 'executed');
        AiTurnTrace::step(self::ORG, '', 'retrieval', 'executed');
        AiTurnTrace::step(self::ORG, self::TURN, '', 'executed');

        $this->assertNull(AiTurnTrace::claim(self::ORG, self::TURN));
    }

    // ────────────────────────────── 4. AiTurnState::fromTurnBlock

    public function test_from_turn_block_lit_les_trois_axes_ecrits(): void
    {
        $etat = AiTurnState::fromTurnBlock([
            'schema' => 1,
            'status' => AiTurnState::TURN_ANSWERED,
            'state' => [
                'verification_status' => AiTurnState::VERIFICATION_SUPPORTED,
                'degraded_reason' => AiTurnState::DEGRADED_PARTIAL_FAILURE,
            ],
        ]);

        $this->assertSame(AiTurnState::TURN_ANSWERED, $etat->turnStatus);
        $this->assertSame(AiTurnState::VERIFICATION_SUPPORTED, $etat->verificationStatus);
        $this->assertSame(AiTurnState::DEGRADED_PARTIAL_FAILURE, $etat->degradedReason);
    }

    public function test_from_turn_block_null_reste_null(): void
    {
        // Un bloc muet ne doit produire AUCUNE valeur rassurante. C'est
        // l'invariant I12 : le V0 ne rend pas le lecteur « plus intelligent
        // pour deviner ».
        $etat = AiTurnState::fromTurnBlock(['schema' => 1]);

        $this->assertNull($etat->turnStatus);
        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $etat->verificationStatus);
        $this->assertNull($etat->degradedReason);
        $this->assertFalse($etat->awaitingClarification);
    }

    public function test_from_turn_block_rejette_un_axe_2_hors_vocabulaire(): void
    {
        $etat = AiTurnState::fromTurnBlock([
            'state' => ['verification_status' => 'plutot_bien'],
        ]);

        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $etat->verificationStatus);
    }

    public function test_from_turn_block_ne_derive_rien_de_grounded(): void
    {
        // `grounded` est le discriminateur de l'ANCIEN format, lu par
        // `fromTurnMetadata()`. Le bloc `turn`, lui, ecrit ce qu'il a mesure ou
        // n'ecrit rien : le lire depuis `grounded` reintroduirait exactement
        // l'heuristique que V0-A supprime.
        $etat = AiTurnState::fromTurnBlock(['grounded' => true]);

        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $etat->verificationStatus);
    }

    public function test_les_trois_statuts_v0a_sont_declares_et_distincts(): void
    {
        $statuts = [
            AiTurnState::TURN_ABSTAINED,
            AiTurnState::TURN_REFUSED,
            AiTurnState::TURN_FAILED,
            AiTurnState::TURN_ANSWERED,
            AiTurnState::TURN_NON_INTERACTION,
            AiTurnState::TURN_BLOCKED,
            AiTurnState::TURN_UNAVAILABLE,
        ];

        $this->assertCount(count($statuts), array_unique($statuts));
    }

    public function test_from_turn_metadata_reste_intact(): void
    {
        // Compatibilite : l'ancienne entree continue de deriver l'axe 2 depuis
        // `grounded`, exactement comme avant TASK-1566.
        $etat = AiTurnState::fromTurnMetadata(['status' => AiTurnState::TURN_ANSWERED, 'grounded' => true]);

        $this->assertSame(AiTurnState::VERIFICATION_SUPPORTED, $etat->verificationStatus);
    }

    // ────────────────────────────── 5. AiTurnReason — des alias, pas des copies

    public function test_le_registre_pointe_les_constantes_d_origine(): void
    {
        // Si l'une de ces classes renomme sa valeur, le registre DOIT suivre
        // tout seul. Une chaine recopiee divergerait en silence.
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, AiTurnReason::ECONOMIC_ORGANIZATION_BUDGET_REACHED);
        $this->assertSame(AiRefusedException::CODE_NOT_CONFIGURED, AiTurnReason::REFUSED_NOT_CONFIGURED);
        $this->assertSame(SourceDenied::REASON_NO_LOOP_IN_CONTEXT, AiTurnReason::SOURCE_NO_LOOP_IN_CONTEXT);
        $this->assertSame(DossierRerankOutcome::REASON_GATE_CLOSED, AiTurnReason::RERANK_GATE_CLOSED);
        $this->assertSame(AiTurnState::DEGRADED_PARTIAL_FAILURE, AiTurnReason::DEGRADED_PARTIAL_FAILURE);
    }

    public function test_le_registre_est_ferme_depuis_v0c(): void
    {
        // V0-A gardait ici qu'aucun code P0.4 n'etait invente avant son etage.
        // V0-C (TASK-1571) a FERME le registre : les codes annonces ont chacun
        // leur famille — emise, ou `reserved` jusqu'a leur lot — et `isKnown()`
        // est devenu l'autorite. La garde change donc de sens : un code ABSENT
        // du registre est un defaut, plus un « pas encore ».
        foreach (['NO_GROUNDED_EVIDENCE', 'FAKE_PROVIDER_FALLBACK', 'FEATURE_DISABLED', 'DOCUMENT_PATH_DIRECT_EXECUTION', 'NO_SOURCES_FOUND', 'EMPTY_MODEL_ANSWER', 'PROVIDER_CALL_FAILED'] as $code) {
            $this->assertTrue(AiTurnReason::isKnown($code), "`{$code}` doit etre dans le registre V1");
        }

        // Et un seul code de P0.4 n'y est PAS, par decision : la famille rerank
        // dit deja pourquoi le rerank n'a pas ete tente.
        $this->assertFalse(AiTurnReason::isKnown('RERANK_NOT_CONFIGURED'));
    }

    public function test_la_collision_economique_reelle_est_exposee_et_non_masquee(): void
    {
        // FACT @dc2109b3 : le GARDE et l'EXCEPTION nomment le meme refus avec
        // deux chaines differentes. Ce test ECHOUERA le jour ou quelqu'un les
        // unifiera — et c'est voulu : cette unification est une decision de
        // V0-C, qui devra alors mettre ce test a jour en connaissance de cause.
        $this->assertNotSame(
            AiTurnReason::ECONOMIC_ORGANIZATION_BUDGET_REACHED,
            AiTurnReason::REFUSED_ORGANIZATION_BUDGET_REACHED,
        );
        $this->assertNotSame(
            AiTurnReason::ECONOMIC_USER_CREDIT_EXHAUSTED,
            AiTurnReason::REFUSED_USER_CREDIT_EXHAUSTED,
        );
    }

    public function test_un_code_ne_se_lit_jamais_hors_de_sa_famille(): void
    {
        // `provider_unavailable` designe DEUX choses selon l'etage qui le
        // porte. La famille doit donc rester accessible.
        $familles = AiTurnReason::byFamily();

        $this->assertContains(AiTurnReason::RERANK_PROVIDER_UNAVAILABLE, $familles['rerank']);
        $this->assertContains(AiTurnReason::DEGRADED_PROVIDER_UNAVAILABLE, $familles['degraded']);
        $this->assertSame(AiTurnReason::RERANK_PROVIDER_UNAVAILABLE, AiTurnReason::DEGRADED_PROVIDER_UNAVAILABLE);
    }

    public function test_is_known_ne_declare_pas_valide_ce_qu_il_ignore(): void
    {
        $this->assertTrue(AiTurnReason::isKnown(AiTurnReason::RERANK_GATE_CLOSED));
        $this->assertFalse(AiTurnReason::isKnown('code_qui_n_existe_pas'));
        $this->assertFalse(AiTurnReason::isKnown(null));
    }

    // ────────────────────────────── 6. le schema est versionne

    public function test_le_schema_v0a_est_la_version_1(): void
    {
        $this->assertSame(1, AiTurnTrace::SCHEMA_VERSION);
        $this->assertSame('turn', AiTurnTrace::TURN_METADATA_KEY);

        // La cle ne doit JAMAIS etre `sources_denied` au premier niveau : c'est
        // elle qui allume un bandeau visible par le membre.
        $this->assertNotSame('sources_denied', AiTurnTrace::TURN_METADATA_KEY);
    }
}
