<?php

namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\ClaimPatch;
use PHPUnit\Framework\TestCase;

/**
 * TASK-1540 — ce qu'un modele a le droit de demander a la memoire.
 *
 * Deux regles portent tout le reste, et aucune des deux n'est une question de
 * gout :
 *
 *  - **l'identite vient du serveur.** Sans cela, un modele pourrait faire
 *    disparaitre n'importe quel enonce en devinant un identifiant ;
 *  - **la preuve doit exister.** Sans cela, une memoire devient une rumeur.
 */
class ClaimPatchTest extends TestCase
{
    private const CLAIMS = ['claim-budget', 'claim-echeance'];

    private const MESSAGES = ['msg-1', 'msg-2', 'msg-3'];

    private function valider(array $operations): ClaimPatch
    {
        return ClaimPatch::valider($operations, self::CLAIMS, self::MESSAGES);
    }

    // ───────────────────────────────────────────── identite

    public function test_un_add_ne_porte_jamais_d_identite(): void
    {
        $patch = $this->valider([
            ['op' => 'ADD', 'text' => 'Le budget travaux vote est de 486 000 euros.', 'evidence' => ['msg-1']],
        ]);

        $this->assertCount(1, $patch->acceptees);
        $this->assertNull($patch->acceptees[0]['claim_id'],
            'le serveur forge l identite : elle n existe pas encore au moment du ADD');
    }

    public function test_un_add_qui_s_attribue_une_identite_est_rejete(): void
    {
        $patch = $this->valider([
            ['op' => 'ADD', 'claim_id' => 'claim-invente', 'text' => 'Un enonce parfaitement plausible.', 'evidence' => ['msg-1']],
        ]);

        $this->assertSame([], $patch->acceptees);
        $this->assertSame('identite_forgee_par_le_modele', $patch->rejetees[0]['raison']);
    }

    public function test_une_operation_sur_une_identite_inconnue_est_rejetee(): void
    {
        // LE cas dangereux : un identifiant plausible mais jamais presente.
        // L'accepter laisserait un modele retracter un claim d une autre Boucle.
        foreach (['UPDATE', 'RETRACT', 'KEEP'] as $op) {
            $patch = $this->valider([
                ['op' => $op, 'claim_id' => 'claim-d-une-autre-boucle', 'text' => 'Texte suffisamment long pour passer.', 'evidence' => ['msg-1']],
            ]);

            $this->assertSame([], $patch->acceptees, "{$op} sur une identite inconnue doit etre refuse");
            $this->assertSame('identite_inconnue', $patch->rejetees[0]['raison']);
        }
    }

    public function test_une_operation_sans_identite_est_rejetee(): void
    {
        $patch = $this->valider([
            ['op' => 'UPDATE', 'text' => 'Le budget travaux passe a 531 000 euros.', 'evidence' => ['msg-2']],
        ]);

        $this->assertSame('identite_absente', $patch->rejetees[0]['raison']);
    }

    public function test_un_meme_claim_ne_peut_pas_etre_traite_deux_fois(): void
    {
        // Sinon l'ordre d'application deciderait du resultat, en silence.
        $patch = $this->valider([
            ['op' => 'UPDATE', 'claim_id' => 'claim-budget', 'text' => 'Le budget passe a 531 000 euros.', 'evidence' => ['msg-2']],
            ['op' => 'RETRACT', 'claim_id' => 'claim-budget', 'reason' => 'plus valable', 'evidence' => ['msg-3']],
        ]);

        $this->assertCount(1, $patch->acceptees);
        $this->assertSame('identite_traitee_deux_fois', $patch->rejetees[0]['raison']);
    }

    // ───────────────────────────────────────────── preuve

    public function test_une_preuve_inventee_fait_rejeter_l_operation(): void
    {
        $patch = $this->valider([
            ['op' => 'ADD', 'text' => 'Le maire a promis une rallonge de 200 000 euros.', 'evidence' => ['msg-42']],
        ]);

        $this->assertSame([], $patch->acceptees,
            'un enonce dont la preuve ne renvoie a aucun message fourni ne devient pas actif');
        $this->assertSame('preuve_hors_perimetre', $patch->rejetees[0]['raison']);
    }

    public function test_une_preuve_partiellement_inventee_fait_rejeter_l_operation(): void
    {
        // Le piege : une preuve valide accompagnee d'une inventee. Accepter
        // l'operation reviendrait a valider l'ensemble sur la foi d'une moitie.
        $patch = $this->valider([
            ['op' => 'ADD', 'text' => 'Le budget travaux vote est de 486 000 euros.', 'evidence' => ['msg-1', 'msg-99']],
        ]);

        $this->assertSame([], $patch->acceptees);
        $this->assertSame('preuve_hors_perimetre', $patch->rejetees[0]['raison']);
    }

    public function test_une_operation_sans_preuve_est_rejetee(): void
    {
        foreach ([['op' => 'ADD', 'text' => 'Un enonce sans la moindre preuve.'],
            ['op' => 'UPDATE', 'claim_id' => 'claim-budget', 'text' => 'Un enonce sans la moindre preuve.'],
            ['op' => 'RETRACT', 'claim_id' => 'claim-echeance', 'reason' => 'plus valable']] as $operation) {
            $patch = $this->valider([$operation]);

            $this->assertSame([], $patch->acceptees);
            $this->assertSame('preuve_absente', $patch->rejetees[0]['raison']);
        }
    }

    public function test_keep_n_exige_aucune_preuve(): void
    {
        // KEEP ne change rien : il confirme. Exiger une preuve reviendrait a
        // demander de re-justifier ce qui est deja en memoire a chaque tour.
        $patch = $this->valider([['op' => 'KEEP', 'claim_id' => 'claim-budget']]);

        $this->assertCount(1, $patch->acceptees);
        $this->assertSame([], $patch->rejetees);
        $this->assertFalse($patch->aTravaille(), 'un patch qui ne fait que confirmer ne change rien');
    }

    // ───────────────────────────────────────────── forme

    public function test_un_enonce_trop_court_est_rejete(): void
    {
        $patch = $this->valider([
            ['op' => 'ADD', 'text' => 'ok', 'evidence' => ['msg-1']],
        ]);

        $this->assertSame('enonce_trop_court', $patch->rejetees[0]['raison']);
    }

    public function test_une_operation_inconnue_est_rejetee(): void
    {
        $patch = $this->valider([
            ['op' => 'DELETE', 'claim_id' => 'claim-budget', 'evidence' => ['msg-1']],
            ['op' => '', 'evidence' => ['msg-1']],
            'ceci n est pas une operation',
        ]);

        $this->assertSame([], $patch->acceptees);
        $this->assertCount(3, $patch->rejetees);
        $this->assertSame('operation_illisible', $patch->rejetees[2]['raison']);
    }

    public function test_le_nombre_d_operations_est_borne(): void
    {
        // Un tour n'a pas a reecrire une memoire entiere : une sortie
        // aberrante doit se faire ecreter, pas s'appliquer.
        $operations = [];

        for ($i = 0; $i < 60; $i++) {
            $operations[] = ['op' => 'ADD', 'text' => "Enonce numero {$i} suffisamment long.", 'evidence' => ['msg-1']];
        }

        $patch = $this->valider($operations);

        $this->assertLessThanOrEqual(40, count($patch->acceptees));
        $this->assertSame('au_dela_de_la_borne', $patch->rejetees[0]['raison']);
    }

    public function test_un_patch_valide_se_lit_par_operation(): void
    {
        $patch = $this->valider([
            ['op' => 'ADD', 'text' => 'Le budget travaux vote est de 486 000 euros.', 'evidence' => ['msg-1']],
            ['op' => 'UPDATE', 'claim_id' => 'claim-budget', 'text' => 'Le budget passe a 531 000 euros.', 'evidence' => ['msg-2']],
            ['op' => 'RETRACT', 'claim_id' => 'claim-echeance', 'reason' => 'plus de date confirmee', 'evidence' => ['msg-3']],
        ]);

        $this->assertSame([], $patch->rejetees);
        $this->assertCount(1, $patch->operationsDe(ClaimPatch::OP_ADD));
        $this->assertCount(1, $patch->operationsDe(ClaimPatch::OP_UPDATE));
        $this->assertCount(1, $patch->operationsDe(ClaimPatch::OP_RETRACT));
        $this->assertTrue($patch->aTravaille());
    }
}
