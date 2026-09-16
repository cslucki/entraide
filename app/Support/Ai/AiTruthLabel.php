<?php

namespace App\Support\Ai;

/**
 * TASK-1575 / CDC-01 V0-H — les quatre labels de verite d'un champ
 * d'inspection (vocabulaire du CDC-02 V3.1, jamais code avant ce lot :
 * correction C1).
 *
 * Un label dit d'OU vient une valeur, pas si elle est bonne :
 *
 *   MEASURED     observee par le pipeline au moment du tour (compteurs,
 *                statuts d'etape, provider qui a repondu) ;
 *   DERIVED      calculee par le LECTEUR a partir de valeurs mesurees
 *                (`state.rule`, `turn_id_source`) — jamais a partir d'une
 *                valeur UNAVAILABLE : un derive sans mesure est UNAVAILABLE ;
 *   DECLARED     issue d'une declaration, pas d'une observation (registre de
 *                capability, nom de chemin fourni par l'appelant, config) ;
 *   UNAVAILABLE  la trace ne porte pas ce champ. Jamais infere.
 *
 * Le label vit dans l'inspection (le lecteur), pas dans `turn` (la trace
 * persistee) : `turn.schema` reste 1.
 */
final class AiTruthLabel
{
    public const MEASURED = 'MEASURED';

    public const DERIVED = 'DERIVED';

    public const DECLARED = 'DECLARED';

    public const UNAVAILABLE = 'UNAVAILABLE';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::MEASURED, self::DERIVED, self::DECLARED, self::UNAVAILABLE];
    }
}
