<?php

namespace App\Ai\MultiAssistant;

/**
 * La composition des instructions d'UN assistant. (TASK-1618)
 *
 * Une fonction pure, sortie de l'orchestrateur pour une raison precise : le
 * RANG de la persona est un invariant de securite, et un invariant de securite
 * doit pouvoir se mesurer sans monter un tour complet. Ici, il se lit en trois
 * lignes de test ; laisse dans une methode privee, il ne se lisait qu'en
 * inspectant un prompt qu'aucune doublure du SDK n'expose.
 *
 * L'ordre est le contrat :
 *
 *     [socle]     gardes en code > Constitution plateforme > Constitution de
 *                 l'Organization > doctrine > instructions de capability
 *     [persona]   le texte ecrit par la Boucle, DELIMITE, en dernier
 *
 * Le dernier rang n'est pas une politesse de mise en page. Un texte redige par
 * un animateur arrive avec l'autorite de sa position : place avant le socle, il
 * se lirait comme le cadre, et le socle comme un detail qui le precise. Place
 * apres, et annonce pour ce qu'il est, il ne peut plus etre qu'un ton.
 */
final class AssistantInstructions
{
    public const OUVERTURE = '<<<PERSONA';

    public const FERMETURE = 'PERSONA';

    public static function compose(string $socle, string $entete, string $persona): string
    {
        $persona = trim($persona);

        // Une posture vide n'ouvre pas un bloc vide : un delimiteur sans
        // contenu invite le modele a se demander ce qui devait s'y trouver.
        if ($persona === '') {
            return $socle;
        }

        return $socle."\n\n".$entete."\n".self::OUVERTURE."\n".$persona."\n".self::FERMETURE;
    }
}
