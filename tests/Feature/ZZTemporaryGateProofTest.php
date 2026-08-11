<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TEMPORAIRE — verification contradictoire du gate SQLite (TASK-1126).
 *
 * Echec nominatif inoffensif, pousse sur la branche de tache uniquement, pour
 * constater que le gate le **nomme** et rougit. Retire dans le commit suivant.
 *
 * `ci-known-red` le fait exclure du gate PostgreSQL : celui-ci doit rester
 * vert pendant que SQLite rougit — ce qui montre au passage que le job SQLite
 * couvre les tests que PostgreSQL exclut.
 */
#[Group('ci-known-red')]
class ZZTemporaryGateProofTest extends TestCase
{
    public function test_deliberate_failure_to_prove_the_gate_detects_it(): void
    {
        $this->fail('echec deliberé TASK-1126 — le gate doit le nommer');
    }
}
