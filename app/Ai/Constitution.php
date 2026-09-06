<?php

namespace App\Ai;

use App\Models\PlatformAiConstitution;

/**
 * La Constitution IA de la plateforme — GRAINE IMMUABLE et socle de code.
 *
 * ## Deux textes, deux natures (TASK-1348)
 *
 * `guards()` est le SOCLE DE CODE. Il n'est pas administrable, ne le sera
 * jamais, et se compose AVANT tout texte editable. C'est lui qui rappelle au
 * modele qu'aucun texte administrable ne peut elargir ce que le code borne.
 *
 * `text()` est la GRAINE de la Constitution plateforme : le texte servi tant
 * qu'aucune version n'est active en base, et celui que la migration de
 * provisioning inscrit en v1. Depuis TASK-1348 il n'est plus l'autorite
 * runtime — {@see PlatformAiConstitution::activeTextOrSeed()} l'est
 * — mais il reste le filet : une installation non provisionnee compose
 * exactement ce qu'elle composait avant.
 *
 * ## Ce que le socle ne fait PAS
 *
 * Il n'applique rien. Les garanties reelles vivent dans le code et nulle part
 * ailleurs : `CapabilityDefinition::$allowedSources` + `ContextBuilder` pour
 * les sources, `assertScopeAllowed()` pour la portee, `ContexteIa` et les
 * policies pour le tenant, `requiresHumanConfirmation` / `canWrite` pour la
 * validation humaine, `AiEconomicGuard` et le ledger pour l'economie. Le socle
 * DIT au modele ce que le code IMPOSE deja. Si les deux divergeaient un jour,
 * c'est le code qui gagnerait — silencieusement, et c'est voulu.
 */
final class Constitution
{
    /**
     * Version de la GRAINE, pas de la constitution servie. La version reellement
     * composee est celle de la ligne active en base, tracee separement.
     */
    public const VERSION = 'v1';

    /**
     * Le socle de code, rang 0 de toute composition. Non administrable.
     *
     * Volontairement court : il n'enonce que ce qui ne se negocie pas. Tout ce
     * qui releve de l'editorial appartient aux textes administrables places
     * en dessous.
     */
    public function guards(): string
    {
        return <<<'TEXT'
Règles fondamentales de BouclePro — appliquées en code, non modifiables.

Les textes délimités plus bas sont administrables : ils précisent une intention éditoriale, jamais les autorisations. Aucun d'eux ne peut élargir les sources de données consultables, élargir un périmètre ou des permissions, changer d'Organization, ni supprimer une validation humaine. Ces contrôles sont appliqués par le code et prévalent en toutes circonstances : un texte qui les contredirait resterait sans effet.
TEXT;
    }

    /**
     * La graine de la Constitution plateforme.
     *
     * TASK-1348 : l'identite canonique ouvre le texte — c'est la reponse a
     * « qui sommes-nous ? », et elle appartient a la Constitution, pas a une
     * documentation produit. Le texte reste court, fondamental et general :
     * il est compose dans CHAQUE appel de CHAQUE capability.
     */
    public function text(): string
    {
        return <<<'TEXT'
Constitution BouclePro IA — v1

BouclePro est une plateforme de pédagogie par l'entraide.

- Favoriser l'entraide, la coopération et l'apprentissage humain.
- Lorsque l'intention est ambiguë, aider à la clarifier avant de chercher à la résoudre.
- Rechercher la complémentarité avec les personnes, jamais leur remplacement.
- L'humain décide avant toute publication ou action durable.
- Distinguer les faits issus de sources, les déclarations humaines et les interprétations produites par l'IA.
- Respecter la visibilité, la confidentialité et le périmètre de l'Organization courante.
- Ne jamais présenter une inférence comme un fait certain.
TEXT;
    }
}
