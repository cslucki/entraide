<?php

namespace App\Services\Knowledge;

/**
 * TASK-1540 — ce qu'un modele a le droit de demander a la memoire.
 *
 * Le modele ne modifie pas la memoire : il PROPOSE des operations, que le
 * serveur accepte ou rejette une a une. Cette classe est le point ou cette
 * distinction devient executable.
 *
 * ## Les deux regles qui ne se negocient pas
 *
 * **L'identite vient du serveur.** `UPDATE`, `RETRACT` et `KEEP` ne peuvent
 * porter que sur un identifiant qui a ete PRESENTE au modele dans ce tour.
 * Un identifiant inconnu — invente, halluciné, recopie d'un autre tour — rend
 * l'operation invalide. `ADD` ne porte aucun identifiant : le serveur le forge.
 *
 * Sans cette regle, un modele pourrait faire disparaitre n'importe quel enonce
 * de n'importe quelle Boucle en devinant un identifiant.
 *
 * **La preuve doit exister.** Les messages cites en evidence doivent
 * appartenir a ceux qui ont ete fournis dans ce tour. Un claim dont la preuve
 * est inventee ne devient pas actif — c'est la difference entre une memoire et
 * une rumeur.
 *
 * ## Ce que cette classe ne fait pas
 *
 * Elle ne juge pas du CONTENU. Savoir si un enonce est vrai, utile ou bien
 * ecrit n'est pas une question de validation structurelle, et pretendre le
 * contraire donnerait une fausse assurance. Elle verifie ce qui est
 * verifiable : une identite connue, une preuve existante, un texte non vide.
 */
final class ClaimPatch
{
    public const OP_ADD = 'ADD';

    public const OP_UPDATE = 'UPDATE';

    public const OP_RETRACT = 'RETRACT';

    public const OP_KEEP = 'KEEP';

    private const OPS = [self::OP_ADD, self::OP_UPDATE, self::OP_RETRACT, self::OP_KEEP];

    /**
     * Un enonce plus court ne se tient pas seul.
     *
     * PUBLIC depuis la remediation TASK-1549 — visibilite elargie, regle
     * INCHANGEE. Une surface qui propose d'ecrire un enonce doit pouvoir
     * refuser AVANT d'appeler le moteur : sinon le message humain est deja
     * publie quand `valider()` rejette, et la personne lit un conflit invente
     * a la place de la contrainte reelle. La borne reste definie ICI, une
     * seule fois — la recopier dans le composant l'aurait laissee deriver.
     */
    public const MIN_TEXTE = 15;

    /** Borne dure : un tour ne reecrit pas une memoire entiere. */
    private const MAX_OPERATIONS = 40;

    /**
     * @param  list<array<string, mixed>>  $acceptees
     * @param  list<array{operation: array<string, mixed>, raison: string}>  $rejetees
     */
    private function __construct(
        public readonly array $acceptees,
        public readonly array $rejetees,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $operations  ce que le modele a rendu
     * @param  list<string>  $claimsConnus  les identites PRESENTEES dans ce tour
     * @param  list<string>  $messagesFournis  les identifiants de messages fournis
     */
    public static function valider(array $operations, array $claimsConnus, array $messagesFournis): self
    {
        $acceptees = [];
        $rejetees = [];
        $vus = [];

        foreach (array_slice($operations, 0, self::MAX_OPERATIONS) as $operation) {
            if (! is_array($operation)) {
                $rejetees[] = ['operation' => [], 'raison' => 'operation_illisible'];

                continue;
            }

            $raison = self::raisonDeRejet($operation, $claimsConnus, $messagesFournis, $vus);

            if ($raison !== null) {
                $rejetees[] = ['operation' => $operation, 'raison' => $raison];

                continue;
            }

            $cle = (string) ($operation['claim_id'] ?? '');

            if ($cle !== '') {
                $vus[$cle] = true;
            }

            $acceptees[] = self::normaliser($operation);
        }

        foreach (array_slice($operations, self::MAX_OPERATIONS) as $trop) {
            $rejetees[] = ['operation' => is_array($trop) ? $trop : [], 'raison' => 'au_dela_de_la_borne'];
        }

        return new self($acceptees, $rejetees);
    }

    /**
     * @param  list<string>  $claimsConnus
     * @param  list<string>  $messagesFournis
     * @param  array<string, bool>  $vus
     */
    private static function raisonDeRejet(array $operation, array $claimsConnus, array $messagesFournis, array $vus): ?string
    {
        $op = strtoupper(trim((string) ($operation['op'] ?? '')));

        if (! in_array($op, self::OPS, true)) {
            return 'operation_inconnue';
        }

        $claimId = trim((string) ($operation['claim_id'] ?? ''));

        if ($op === self::OP_ADD) {
            if ($claimId !== '') {
                // Un ADD qui s'attribue une identite tenterait de forger la
                // seule chose que le serveur se reserve.
                return 'identite_forgee_par_le_modele';
            }
        } else {
            if ($claimId === '') {
                return 'identite_absente';
            }

            if (! in_array($claimId, $claimsConnus, true)) {
                // L'identite n'a pas ete presentee dans ce tour : le modele
                // l'a inventee, ou vise un claim qu'il n'a pas le droit de voir.
                return 'identite_inconnue';
            }

            if (isset($vus[$claimId])) {
                return 'identite_traitee_deux_fois';
            }
        }

        if ($op === self::OP_KEEP) {
            return null;
        }

        if ($op === self::OP_ADD || $op === self::OP_UPDATE) {
            if (mb_strlen(trim((string) ($operation['text'] ?? ''))) < self::MIN_TEXTE) {
                return 'enonce_trop_court';
            }
        }

        $evidence = $operation['evidence'] ?? [];

        if (! is_array($evidence) || $evidence === []) {
            return 'preuve_absente';
        }

        foreach ($evidence as $id) {
            if (! is_string($id) || ! in_array($id, $messagesFournis, true)) {
                // Une preuve qui ne renvoie a aucun message fourni est une
                // preuve inventee. Le claim ne devient pas actif.
                return 'preuve_hors_perimetre';
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normaliser(array $operation): array
    {
        $evidence = array_values(array_unique(array_filter(
            (array) ($operation['evidence'] ?? []),
            'is_string',
        )));

        return [
            'op' => strtoupper(trim((string) $operation['op'])),
            'claim_id' => trim((string) ($operation['claim_id'] ?? '')) ?: null,
            'text' => trim((string) ($operation['text'] ?? '')) ?: null,
            'reason' => trim((string) ($operation['reason'] ?? '')) ?: null,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function operationsDe(string $op): array
    {
        return array_values(array_filter($this->acceptees, static fn (array $o): bool => $o['op'] === $op));
    }

    public function aTravaille(): bool
    {
        foreach ($this->acceptees as $o) {
            if ($o['op'] !== self::OP_KEEP) {
                return true;
            }
        }

        return false;
    }
}
