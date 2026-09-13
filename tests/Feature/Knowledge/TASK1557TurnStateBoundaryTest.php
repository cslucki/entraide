<?php

namespace Tests\Feature\Knowledge;

use App\Services\Ai\AiShellResponder;
use App\Support\Ai\AiTurnState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1557 — W3F-min : la frontière minimale des états et sa projection.
 *
 * Ces tests n'ont **aucune fixture, aucune base, aucun provider**, et c'est le
 * point : `AiTurnState` est un value-object pur. Une frontière qui aurait besoin
 * d'un tenant pour être vérifiée ne serait pas une frontière, ce serait un
 * moteur.
 *
 * Ce qu'ils gardent, dans l'ordre de ce qui ferait le plus de dégâts :
 *
 *  1. **G/H.** Une source interdite ne doit être inférable par aucun des six
 *     vecteurs. La garde est ici STRUCTURELLE — `rule()` ne consulte jamais une
 *     raison pré-boundary — et c'est cette propriété-là qui est mesurée, pas la
 *     prudence d'une phrase ;
 *  2. **une panne n'est jamais un `insufficient`.** Une abstention fabriquée est
 *     indiscernable d'une vraie, et fausse dans la direction qui inspire le plus
 *     confiance ;
 *  3. **les trois axes restent trois.** Un test par situation que la fusion
 *     rendrait inexprimable ;
 *  4. **aucune raison technique brute ne sort** — la projection ne rend que des
 *     clés i18n, jamais un code.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1557TurnStateBoundaryTest extends TestCase
{
    // ────────────────────────────── 1. les sept projections R1..R7

    public static function projections(): array
    {
        return [
            'R1 — clarification en attente' => [
                ['status' => AiShellResponder::STATUS_ANSWERED, 'clarification_questions' => ['Quel budget ?']],
                AiTurnState::R1_CLARIFICATION,
            ],
            'R2 — indisponibilité totale' => [
                ['status' => AiShellResponder::STATUS_UNAVAILABLE,
                    'degraded_reason' => AiTurnState::DEGRADED_PROVIDER_UNAVAILABLE],
                AiTurnState::R2_UNAVAILABLE,
            ],
            'R3 — contradiction (synthétique)' => [
                ['status' => AiShellResponder::STATUS_NON_INTERACTION,
                    'verification_status' => AiTurnState::VERIFICATION_CONTRADICTED],
                AiTurnState::R3_CONTRADICTED,
            ],
            'R4 — preuves insuffisantes (réel : grounded=false)' => [
                ['status' => AiShellResponder::STATUS_NON_INTERACTION, 'grounded' => false],
                AiTurnState::R4_INSUFFICIENT,
            ],
            'R5 — périmé/incertain (synthétique)' => [
                ['status' => AiShellResponder::STATUS_NON_INTERACTION,
                    'verification_status' => AiTurnState::VERIFICATION_STALE_UNCERTAIN],
                AiTurnState::R5_STALE,
            ],
            'R6 — supporté mais panne partielle' => [
                ['status' => AiShellResponder::STATUS_NON_INTERACTION, 'grounded' => true,
                    'degraded_reason' => AiTurnState::DEGRADED_PARTIAL_FAILURE],
                AiTurnState::R6_PARTIAL,
            ],
            'R7 — supporté, rien à signaler' => [
                ['status' => AiShellResponder::STATUS_NON_INTERACTION, 'grounded' => true],
                AiTurnState::R7_SUPPORTED,
            ],
        ];
    }

    #[DataProvider('projections')]
    public function test_les_sept_projections_sont_toutes_atteignables(array $metadata, string $attendu): void
    {
        $this->assertSame($attendu, AiTurnState::fromTurnMetadata($metadata)->rule());
    }

    // ─────────────────────── 2. G/H — le cœur du mandat

    /**
     * Test n°3 du mandat, et le plus important de ce fichier.
     *
     * Le MÊME tour insuffisant, joué dans deux états — source interdite absente,
     * puis présente et refusée — doit rendre une surface user-facing
     * **rigoureusement identique**. Pas « similaire » : identique.
     */
    public function test_une_source_interdite_ne_change_rien_a_la_surface_user_facing(): void
    {
        $tour = ['status' => AiShellResponder::STATUS_NON_INTERACTION, 'grounded' => false];

        $sansSourceInterdite = AiTurnState::fromTurnMetadata($tour, ['sources_denied' => []]);
        $avecSourceInterdite = AiTurnState::fromTurnMetadata($tour, [
            'sources_denied' => ['dossier.insights' => 'semantic_search_disabled'],
        ]);

        // vecteur « wording » — la même clé, donc la même phrase
        $this->assertSame($sansSourceInterdite->userFacingKey(), $avecSourceInterdite->userFacingKey());
        // vecteur « affordance » — la même règle, donc la même surface offerte
        $this->assertSame($sansSourceInterdite->rule(), $avecSourceInterdite->rule());
        $this->assertSame(AiTurnState::R4_INSUFFICIENT, $avecSourceInterdite->rule());
    }

    /**
     * Vecteur « compteur », mesuré pour lui-même.
     *
     * C'est celui qu'un Eval manque le plus souvent : un nombre trahit une
     * existence que le texte tait soigneusement. Ici il ne peut pas fuir, parce
     * qu'aucun compteur n'entre dans la projection — et ce test le prouve en
     * faisant varier le NOMBRE de sources refusées.
     */
    public function test_le_nombre_de_sources_refusees_ne_transparait_nulle_part(): void
    {
        // Les DEUX états de vérification sont mesurés, et le second est le plus
        // dangereux : sur un tour SUPPORTÉ, une fuite ferait apparaître ou
        // disparaître une phrase entière — le vecteur « wording » et le vecteur
        // « affordance » d'un coup. Une première version de ce test ne couvrait
        // que le cas insuffisant, où la branche R4 masque le défaut.
        foreach ([false, true] as $grounded) {
            $tour = ['status' => AiShellResponder::STATUS_NON_INTERACTION, 'grounded' => $grounded];
            $rendus = [];

            foreach ([0, 1, 3, 17] as $combien) {
                $refus = [];

                for ($i = 0; $i < $combien; $i++) {
                    $refus['source.'.$i] = 'semantic_search_disabled';
                }

                $etat = AiTurnState::fromTurnMetadata($tour, ['sources_denied' => $refus]);
                $rendus[] = [$etat->rule(), $etat->userFacingKey()];
            }

            $this->assertCount(1, array_unique(array_map('serialize', $rendus)),
                'le nombre de sources refusees ne doit produire AUCUNE variation user-facing'
                .' (grounded='.var_export($grounded, true).')');
        }
    }

    /**
     * L'autre moitié de G/H, et elle va dans le sens inverse : côté TRACE, la
     * cause réelle doit rester DISTINGUABLE. Une trace amnésique ne protège
     * personne et rend le produit indiagnosticable.
     */
    public function test_la_trace_admin_conserve_la_cause_que_l_ecran_tait(): void
    {
        $etat = AiTurnState::fromTurnMetadata(
            ['status' => AiShellResponder::STATUS_NON_INTERACTION, 'grounded' => false],
            ['sources_denied' => ['dossier.insights' => 'semantic_search_disabled']],
        );

        $this->assertSame(AiTurnState::DEGRADED_SOURCE_DENIED, $etat->toTrace()['degraded_reason'],
            'la trace doit savoir ce que la projection tait');
        $this->assertNull($etat->userFacingKey() === null ? null : null);
        $this->assertSame('ai.turn_state_insufficient', $etat->userFacingKey());
    }

    /** Une raison pré-boundary ne franchit jamais la frontière, même sur un tour supporté. */
    public function test_une_source_refusee_sur_un_tour_supporte_ne_produit_rien_de_visible(): void
    {
        $etat = AiTurnState::fromTurnMetadata(
            ['status' => AiShellResponder::STATUS_NON_INTERACTION, 'grounded' => true],
            ['sources_denied' => ['dossier.insights' => 'semantic_search_disabled']],
        );

        $this->assertSame(AiTurnState::R7_SUPPORTED, $etat->rule(),
            'R6 est reserve aux PANNES ; une porte fermee ne se signale pas');
        $this->assertNull($etat->userFacingKey());
    }

    public function test_les_raisons_pre_boundary_sont_declarees_non_projetables(): void
    {
        foreach (AiTurnState::PRE_BOUNDARY_REASONS as $raison) {
            $this->assertFalse(AiTurnState::isProjectable($raison), $raison.' ne doit jamais franchir la frontiere');
        }

        foreach ([AiTurnState::DEGRADED_PROVIDER_UNAVAILABLE,
            AiTurnState::DEGRADED_REQUEST_PREPARATION_UNAVAILABLE,
            AiTurnState::DEGRADED_PARTIAL_FAILURE] as $raison) {
            $this->assertTrue(AiTurnState::isProjectable($raison), $raison.' est une panne : elle se dit');
        }
    }

    // ───────── 3. une panne n'est jamais une abstention

    /**
     * Le défaut que W3F-min existe pour rendre impossible.
     *
     * Une panne lue comme `insufficient` serait une abstention FABRIQUÉE,
     * indiscernable d'une vraie — et fausse dans la direction qui inspire le
     * plus confiance.
     */
    public function test_une_panne_bloquante_ne_devient_jamais_un_insufficient(): void
    {
        $etat = AiTurnState::fromTurnMetadata([
            'status' => AiShellResponder::STATUS_UNAVAILABLE,
            'degraded_reason' => AiTurnState::DEGRADED_PROVIDER_UNAVAILABLE,
        ]);

        $this->assertSame(AiTurnState::R2_UNAVAILABLE, $etat->rule());
        $this->assertNotSame(AiTurnState::R4_INSUFFICIENT, $etat->rule());
        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $etat->verificationStatus,
            'rien n a ete affirme : il n y a rien a verifier');
    }

    /** Test n°5 : panne NON bloquante — la réponse tient, la note est technique. */
    public function test_une_panne_partielle_laisse_la_reponse_et_ajoute_une_note(): void
    {
        $etat = AiTurnState::fromTurnMetadata([
            'status' => AiShellResponder::STATUS_NON_INTERACTION,
            'grounded' => true,
            'degraded_reason' => AiTurnState::DEGRADED_PARTIAL_FAILURE,
        ]);

        $this->assertSame(AiTurnState::R6_PARTIAL, $etat->rule());
        $this->assertSame('ai.turn_state_partial', $etat->userFacingKey());
    }

    // ───────── 4. les trois axes restent TROIS

    /**
     * Les trois situations qu'une enum globale rendrait inexprimables. C'est la
     * justification entière de la séparation des axes.
     */
    public function test_les_trois_situations_qu_une_enum_globale_ecraserait(): void
    {
        // (a) repondu ET insuffisant
        $a = AiTurnState::fromTurnMetadata(['status' => AiShellResponder::STATUS_ANSWERED, 'grounded' => false]);
        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $a->turnStatus);
        $this->assertSame(AiTurnState::VERIFICATION_INSUFFICIENT, $a->verificationStatus);

        // (b) repondu ET degrade
        $b = AiTurnState::fromTurnMetadata(['status' => AiShellResponder::STATUS_ANSWERED, 'grounded' => true,
            'degraded_reason' => AiTurnState::DEGRADED_PARTIAL_FAILURE]);
        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $b->turnStatus);
        $this->assertSame(AiTurnState::DEGRADED_PARTIAL_FAILURE, $b->degradedReason);

        // (c) indisponible SANS verification — rien n a ete affirme
        $c = AiTurnState::fromTurnMetadata(['status' => AiShellResponder::STATUS_UNAVAILABLE]);
        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $c->verificationStatus);
    }

    /**
     * Un tour qui ne pose aucune affirmation documentaire — People, Self,
     * référence — vaut `not_applicable`, jamais `supported`.
     *
     * Mesure qui fonde ce choix : 49 tours sur 114 en base ne portent pas
     * `grounded`. Leur donner la valeur la plus rassurante par défaut aurait
     * fabriqué 49 « supported » sans la moindre preuve.
     */
    public function test_un_tour_sans_affirmation_documentaire_n_est_jamais_supporte(): void
    {
        foreach ([AiShellResponder::PRODUCER_PEOPLE_MATCHING, AiShellResponder::PRODUCER_SELF_MATCHING,
            AiShellResponder::PRODUCER_REFERENCE_RESOLUTION] as $producteur) {
            $etat = AiTurnState::fromTurnMetadata([
                'status' => AiShellResponder::STATUS_NON_INTERACTION,
                'producer' => $producteur,
            ]);

            $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $etat->verificationStatus, $producteur);
            $this->assertSame(AiTurnState::R7_SUPPORTED, $etat->rule());
            $this->assertNull($etat->userFacingKey(), 'un tour ordinaire ne porte AUCUNE phrase meta');
        }
    }

    // ───────── 5. composite, et hygiène de projection

    /** Test n°8 : plusieurs axes chargés en même temps — l'ordre des branches est le contrat. */
    public function test_un_etat_composite_suit_l_ordre_declare_des_branches(): void
    {
        // indisponible + clarification + insuffisant : R2 prime sur tout.
        $etat = AiTurnState::fromTurnMetadata([
            'status' => AiShellResponder::STATUS_UNAVAILABLE,
            'clarification_questions' => ['Quel budget ?'],
            'grounded' => false,
            'degraded_reason' => AiTurnState::DEGRADED_PROVIDER_UNAVAILABLE,
        ]);

        $this->assertSame(AiTurnState::R2_UNAVAILABLE, $etat->rule(),
            'rien n a pu etre produit : aucun autre axe n a de sens');

        // clarification + insuffisant, sans indisponibilite : R1 prime sur R4.
        $questionnant = AiTurnState::fromTurnMetadata([
            'status' => AiShellResponder::STATUS_ANSWERED,
            'clarification_questions' => ['Quel budget ?'],
            'grounded' => false,
        ]);

        $this->assertSame(AiTurnState::R1_CLARIFICATION, $questionnant->rule(),
            'une question posee precede tout verdict : il n y a rien a etayer encore');
    }

    /**
     * Test n°10 : aucune raison technique brute ne sort.
     *
     * La projection ne rend que des clés `ai.turn_state_*`. Un code — quel qu'il
     * soit — qui atteindrait la surface serait une fuite de vocabulaire interne.
     */
    public function test_aucune_raison_technique_brute_n_atteint_la_surface(): void
    {
        $codes = [
            AiTurnState::DEGRADED_PROVIDER_UNAVAILABLE,
            AiTurnState::DEGRADED_REQUEST_PREPARATION_UNAVAILABLE,
            AiTurnState::DEGRADED_PARTIAL_FAILURE,
            AiTurnState::DEGRADED_SOURCE_DENIED,
            AiTurnState::DEGRADED_TENANT_SCOPE,
            AiTurnState::DEGRADED_LOOP_SCOPE,
            'semantic_search_disabled',
            'dossier_not_authorized',
        ];

        foreach ($codes as $code) {
            $etat = AiTurnState::fromTurnMetadata([
                'status' => AiShellResponder::STATUS_NON_INTERACTION,
                'grounded' => true,
                'degraded_reason' => $code,
            ]);

            $cle = $etat->userFacingKey();

            if ($cle === null) {
                continue;
            }

            $this->assertStringStartsWith('ai.turn_state_', $cle);
            $this->assertStringNotContainsString($code, $cle, "le code technique « {$code} » ne doit pas fuir");
            $this->assertStringNotContainsString($code, (string) __($cle),
                "la phrase rendue ne doit pas contenir « {$code} »");
        }
    }

    /** Les cinq phrases existent en FR et en EN, et aucune ne porte de code. */
    public function test_les_phrases_projetees_existent_dans_les_deux_langues_et_ne_portent_aucun_code(): void
    {
        foreach (['unavailable', 'contradicted', 'insufficient', 'stale', 'partial'] as $etat) {
            foreach (['fr', 'en'] as $langue) {
                $phrase = (string) __('ai.turn_state_'.$etat, [], $langue);

                $this->assertNotSame('ai.turn_state_'.$etat, $phrase, "cle manquante en {$langue}");
                $this->assertDoesNotMatchRegularExpression('/[a-z]+_[a-z]+_[a-z]+/', $phrase,
                    'une phrase produit ne contient pas d identifiant snake_case');
            }
        }
    }

    // ───────── 6. pureté : aucun appel provider, aucune requête

    /**
     * Test n°11 : `AiTurnState` ne peut PAS déclencher un appel provider ni une
     * requête — elle ne connaît que des tableaux.
     *
     * La preuve est structurelle et se lit dans la signature : aucune dépendance
     * injectée, aucune façade, deux entrées `array`. Un test qui se contenterait
     * de compter les appels laisserait la porte ouverte au premier refactor.
     */
    public function test_la_frontiere_est_pure_et_ne_peut_rien_appeler(): void
    {
        $reflet = new \ReflectionClass(AiTurnState::class);

        $this->assertSame([], $reflet->getConstructor()->getParameters()[0]->getAttributes(),
            'aucune injection sur le value-object');

        $source = file_get_contents((string) $reflet->getFileName());

        foreach (['DB::', 'Http::', '->query(', 'app(', 'resolve(', 'Model::'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $source,
                "une frontiere pure ne contient jamais « {$interdit} »");
        }

        $lecture = $reflet->getMethod('fromTurnMetadata');
        $this->assertTrue($lecture->isStatic());
        $this->assertSame('array', (string) $lecture->getParameters()[0]->getType());
    }

    /**
     * Test n°12 : les chemins hors scope ne bougent pas.
     *
     * Un tour ordinaire — celui de l'immense majorité — traverse la frontière
     * sans que rien ne change : `R7`, aucune phrase, aucune raison.
     */
    public function test_un_tour_ordinaire_traverse_la_frontiere_sans_rien_changer(): void
    {
        $etat = AiTurnState::fromTurnMetadata(['status' => AiShellResponder::STATUS_ANSWERED]);

        $this->assertSame(AiTurnState::R7_SUPPORTED, $etat->rule());
        $this->assertNull($etat->userFacingKey());
        $this->assertNull($etat->degradedReason);
        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $etat->verificationStatus);
    }

    /** La trace porte les trois axes sous leur nom canonique V3.1, plus la règle. */
    public function test_la_trace_porte_les_trois_axes_sous_leur_nom_canonique(): void
    {
        $trace = AiTurnState::fromTurnMetadata([
            'status' => AiShellResponder::STATUS_NON_INTERACTION, 'grounded' => false,
        ])->toTrace();

        $this->assertSame(['shell_turn_status', 'verification_status', 'degraded_reason', 'rule'], array_keys($trace));
    }
}
