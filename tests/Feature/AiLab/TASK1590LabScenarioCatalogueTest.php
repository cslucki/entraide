<?php

namespace Tests\Feature\AiLab;

use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiTurnComparison;
use App\Support\Ai\AiTurnProjection;
use App\Support\Ai\AiTurnReason;
use App\Support\AiLab\LabScenario;
use App\Support\ScenarioPacks\Packs\AiLabPack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1590 / CDC-NIGHT L-B_CORE — « les attentes sont declarees ».
 *
 * A le catalogue CORE (9) est valide, listable, clefs uniques, aucun
 * `scenario_id` ; B vocabulaire = celui de la TRACE gelee (execution_path,
 * 8 etapes, statuts, reason_code, source_type, derives d'historique) ; C le
 * validateur REFUSE (chaque regle du schema a son cas rouge) ; D la commande
 * `ai:lab:list` rougit sur un catalogue invalide.
 */
#[Group('ai')]
class TASK1590LabScenarioCatalogueTest extends TestCase
{
    public const CORE = ['LAB.POSITIVE_SIMPLE_1', 'LAB.TABLE_ORG_ROLE', 'LAB.MULTITURN_REFERENT_1', 'LAB.MULTITURN_REFERENT_1B', 'LAB.MODE_CHANGE_1', 'LAB.NO_ANSWER_1', 'LAB.NEAR_MISS_1', 'LAB.TENANT_NEGATIVE_1', 'LAB.LOOP_ACL_1'];

    // ────────────────────────────── A. catalogue

    public function test_a1_les_neuf_scenarios_core_sont_valides_listables_et_uniques(): void
    {
        $scenarios = LabScenario::all();

        $this->assertCount(9, $scenarios, 'exactement les 9 cles CORE');
        $this->assertEqualsCanonicalizing(self::CORE, array_keys($scenarios));
        foreach ($scenarios as $key => $scenario) {
            $this->assertSame($key, $scenario->key);
            $this->assertSame($key.'.json', basename($scenario->path), 'le fichier porte la cle');
            $this->assertSame(AiLabPack::ORGANIZATION_SLUG, $scenario->organization());
            $this->assertArrayHasKey($scenario->loop(), AiLabPack::LOOPS);
            $this->assertTrue(isset(AiLabPack::PERSONAS[$scenario->user()]) || $scenario->user() === 'lab.outsider');
            $this->assertSame([], LabScenario::validate($scenario->data));
        }
        // K4 : aucun `scenario_id`, nulle part.
        foreach (File::glob(LabScenario::directory().'/*.json') as $file) {
            $this->assertStringNotContainsString('scenario_id', (string) file_get_contents($file), basename($file));
        }
        // Les cles du CDC-NIGHT : deux tours sur les multi-turn, reply explicite sur _1, aucune sur _1B.
        $this->assertSame('previous_ai', $scenarios['LAB.MULTITURN_REFERENT_1']->turns()[1]['reply_to']);
        $this->assertNull($scenarios['LAB.MULTITURN_REFERENT_1B']->turns()[1]['reply_to']);
        $this->assertSame('observe', $scenarios['LAB.MULTITURN_REFERENT_1B']->answerClass());
        $this->assertTrue($scenarios['LAB.MULTITURN_REFERENT_1']->publishes(), 'un tour en reply exige la publication du tour 1 (Lab seulement)');
        $this->assertFalse($scenarios['LAB.MULTITURN_REFERENT_1B']->publishes());
        $this->assertSame(['dossiers', 'ia'], array_column($scenarios['LAB.MODE_CHANGE_1']->turns(), 'mode'));
        $this->assertFalse($scenarios['LAB.TENANT_NEGATIVE_1']->expectsTurn());
        $this->assertSame('denied', $scenarios['LAB.TENANT_NEGATIVE_1']->preconditions()['gold_access']['expected'], 'un refus attendu est une precondition, pas un defaut');
        $this->assertSame('lab.member.c', $scenarios['LAB.LOOP_ACL_1']->user());
        $this->assertSame('L2', $scenarios['LAB.LOOP_ACL_1']->loop(), 'membre de L1 seul, cible L2');
        $this->assertNotContains('L2', AiLabPack::loopKeysFor('lab.member.c'));
        // derived_chunks_absent partout (CDC-NIGHT §8.2).
        foreach ($scenarios as $s) {
            $this->assertSame('required', $s->preconditions()['derived_chunks_absent']);
        }
    }

    public function test_a2_la_commande_liste_le_catalogue_et_rend_zero(): void
    {
        $code = Artisan::call('ai:lab:list', ['--json' => true]);
        $sortie = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);
        $this->assertSame(9, $sortie['count']);
        $this->assertTrue($sortie['valid']);
        $this->assertEqualsCanonicalizing(self::CORE, array_column($sortie['scenarios'], 'lab_scenario_key'));
        $this->assertSame([], array_merge(...array_column($sortie['scenarios'], 'errors')));
        $this->artisan('ai:lab:list')->expectsOutputToContain('LAB.TENANT_NEGATIVE_1')->assertExitCode(0);
    }

    // ────────────────────────────── B. vocabulaire de la trace

    public function test_b1_les_attentes_parlent_la_langue_de_la_trace_gelee(): void
    {
        foreach (LabScenario::all() as $key => $s) {
            $x = $s->expected();
            if ($s->expectsTurn()) {
                $this->assertContains($x['execution_path'], AiExecutionPath::all(), "{$key} execution_path");
                foreach ($x['steps'] as $step => $status) {
                    $this->assertContains($step, AiTurnComparison::STEPS, "{$key} etape {$step} : une des 8 emises (source_filtering n'existe pas)");
                    $this->assertContains($status, LabScenario::STEP_STATUSES, "{$key} statut {$status}");
                }
            }
            foreach (($x['reason_code'] ?? []) as $code) {
                $this->assertTrue(AiTurnReason::isKnown($code), "{$key} reason_code {$code}");
            }
            foreach (($x['sources']['must_not_use_source_type'] ?? []) as $type) {
                $this->assertContains($type, [AiTurnProjection::SOURCE_TYPE_FILE, AiTurnProjection::SOURCE_TYPE_ARTICLE, AiTurnProjection::SOURCE_TYPE_DERIVED_KNOWLEDGE], "{$key} source_type {$type}");
            }
            foreach (($x['history'] ?? []) as $derives) {
                foreach ($derives as $derive => $value) {
                    $this->assertContains($derive, LabScenario::HISTORY_DERIVED, "{$key} derive {$derive}");
                    $this->assertContains($value, ['YES', 'NO', 'UNAVAILABLE']);
                }
            }
        }
        // Le vocabulaire des statuts d'etape est bien celui des writers (FACT grep app/) + conditional.
        $emis = [];
        foreach (File::allFiles(base_path('app')) as $f) {
            if (preg_match_all("/AiTurnTrace::step\\(\\s*[^,]+,\\s*[^,]+,\\s*'[a-z_]+',\\s*'([a-z_]+)'/s", (string) file_get_contents($f->getPathname()), $m)) {
                $emis = [...$emis, ...$m[1]];
            }
        }
        $emis = array_values(array_unique($emis));
        $this->assertNotEmpty($emis);
        foreach ($emis as $status) {
            $this->assertContains($status, LabScenario::STEP_STATUSES, "statut emis par un writer absent du vocabulaire : {$status}");
        }
        $this->assertSame(['conditional'], array_values(array_diff(LabScenario::STEP_STATUSES, $emis, ['bypassed'])), 'seul `conditional` est un ajout du Lab (bypassed est emis sur plusieurs lignes)');
        $this->assertSame(['derived_knowledge'], array_values(array_diff([AiTurnProjection::SOURCE_TYPE_DERIVED_KNOWLEDGE], [])), 'la constante existante est reutilisee');
    }

    // ────────────────────────────── C. le validateur refuse

    public function test_c1_chaque_regle_du_schema_a_son_cas_rouge(): void
    {
        $base = LabScenario::find('LAB.POSITIVE_SIMPLE_1')->data;
        $this->assertSame([], LabScenario::validate($base));

        $cas = [
            'scenario_id reserve' => fn (array $d) => $d + ['scenario_id' => 'x'],
            'cle hors motif' => fn (array $d) => array_replace($d, ['lab_scenario_key' => 'lab.positive']),
            'organization inconnue' => fn (array $d) => array_replace($d, ['organization' => 'main-2']),
            'loop inconnue' => fn (array $d) => array_replace($d, ['loop' => 'L9']),
            'user inconnu' => fn (array $d) => array_replace($d, ['user' => 'lab.member.z']),
            'aucun tour' => fn (array $d) => array_replace($d, ['turns' => []]),
            'order faux' => fn (array $d) => array_replace_recursive($d, ['turns' => [['order' => 2]]]),
            'mode inconnu' => fn (array $d) => array_replace_recursive($d, ['turns' => [['mode' => 'shell']]]),
            'reply_to sur le tour 1' => fn (array $d) => array_replace_recursive($d, ['turns' => [['reply_to' => 'previous_ai']]]),
            'execution inconnue' => fn (array $d) => array_replace($d, ['execution' => 'replay']),
            'precondition inconnue' => fn (array $d) => array_replace_recursive($d, ['preconditions' => ['derived_chunks_absent' => 'maybe']]),
            'gold_access hors vocab' => fn (array $d) => array_replace_recursive($d, ['preconditions' => ['gold_access' => ['expected' => 'yes']]]),
            'expected absent' => fn (array $d) => array_diff_key($d, ['expected' => 1]),
            'classe inconnue' => fn (array $d) => array_replace_recursive($d, ['expected' => ['answer' => ['class' => 'golden']]]),
            'factual sans assertion' => fn (array $d) => array_replace_recursive($d, ['expected' => ['answer' => ['assertion' => ['type' => 'none', 'values' => []]]]]),
            'observe avec assertion' => fn (array $d) => array_replace_recursive($d, ['expected' => ['answer' => ['class' => 'observe']]]),
            'execution_path inconnu' => fn (array $d) => array_replace_recursive($d, ['expected' => ['execution_path' => 'loop_chat.lab']]),
            'etape hors des 8' => fn (array $d) => array_replace_recursive($d, ['expected' => ['steps' => ['source_filtering' => 'executed']]]),
            'statut d etape inconnu' => fn (array $d) => array_replace_recursive($d, ['expected' => ['steps' => ['retrieval' => 'done']]]),
            'reason_code hors registre' => fn (array $d) => array_replace_recursive($d, ['expected' => ['reason_code' => ['retrieval' => 'RERANK_NOT_CONFIGURED']]]),
            'derive d historique inconnu' => fn (array $d) => array_replace_recursive($d, ['expected' => ['history' => ['turn_2' => ['REFERENT_RESOLVED' => 'YES']]]]),
            'source_type parallele' => fn (array $d) => array_replace_recursive($d, ['expected' => ['sources' => ['must_not_use_source_type' => ['derived']]]]),
            'access.stage inconnu' => fn (array $d) => array_replace_recursive($d, ['expected' => ['access' => ['stage' => 'controller']]]),
        ];

        foreach ($cas as $nom => $mutation) {
            $errors = LabScenario::validate($mutation($base));
            $this->assertNotSame([], $errors, "le validateur devait refuser : {$nom}");
        }

        // Un fichier invalide ne se CHARGE pas (jamais un scenario partiel).
        $tmp = sys_get_temp_dir().'/lab-1590-'.Str::uuid();
        mkdir($tmp);
        file_put_contents($tmp.'/LAB.BROKEN_1.json', json_encode(array_replace($base, ['lab_scenario_key' => 'LAB.BROKEN_1', 'execution' => 'replay'])));
        try {
            LabScenario::fromFile($tmp.'/LAB.BROKEN_1.json');
            $this->fail('InvalidArgumentException attendue');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('execution', $e->getMessage());
        }
        // Un nom de fichier qui n'est pas la cle est refuse ; une cle dupliquee aussi.
        file_put_contents($tmp.'/LAB.BROKEN_1.json', json_encode($base));
        try {
            LabScenario::fromFile($tmp.'/LAB.BROKEN_1.json');
            $this->fail('nom ≠ cle attendu');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nom du fichier', $e->getMessage());
        }
        File::deleteDirectory($tmp);
    }

    // ────────────────────────────── D. la commande rougit

    public function test_d1_la_commande_rougit_sur_un_catalogue_invalide_et_nomme_l_erreur(): void
    {
        $tmp = sys_get_temp_dir().'/lab-1590-'.Str::uuid();
        mkdir($tmp);
        $base = LabScenario::find('LAB.POSITIVE_SIMPLE_1')->data;
        file_put_contents($tmp.'/LAB.POSITIVE_SIMPLE_1.json', json_encode($base));
        file_put_contents($tmp.'/LAB.BAD_1.json', json_encode(array_replace($base, ['lab_scenario_key' => 'LAB.BAD_1', 'scenario_id' => 'oops'])));

        $this->artisan('ai:lab:list', ['--dir' => $tmp])->expectsOutputToContain('scenario_id')->assertExitCode(1);
        $code = Artisan::call('ai:lab:list', ['--dir' => $tmp, '--json' => true]);
        $sortie = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $code);
        $this->assertFalse($sortie['valid']);
        $this->assertSame(['LAB.BAD_1' => false, 'LAB.POSITIVE_SIMPLE_1' => true], array_column($sortie['scenarios'], 'valid', 'lab_scenario_key'));
        // Un repertoire vide n'est pas un catalogue.
        File::cleanDirectory($tmp);
        $this->artisan('ai:lab:list', ['--dir' => $tmp])->assertExitCode(1);
        File::deleteDirectory($tmp);
    }
}
