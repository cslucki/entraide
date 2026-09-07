<?php

namespace App\Services\GuestShell;

use App\Ai\CapabilityRegistry;
use App\Models\Organization;
use App\Models\OrganizationAiConstitution;
use App\Models\PlatformAiConstitution;
use App\Models\UsageReference;
use App\Services\UsageReference\UsageReferenceResolver;
use App\Support\GuestShell\GuestPublicContext;

/**
 * TASK-1435 — SW-5 : le contexte PUBLIC d'une Organization pour le Shell
 * Welcome. Public != Global (doctrine) : `main` et `launchpals` ne racontent
 * pas la meme chose, et ni l'une ni l'autre ne revele son systeme nerveux.
 *
 * Whitelist EXPLICITE (cadre Cyril §8), une source par bloc :
 *  - `organization.public_identity` : nom, tagline, accroche, presentation
 *    publiques (ce que la landing montre deja a n'importe qui) ;
 *  - `platform.constitution` : la Constitution IA de la plateforme (Mycelium
 *    public, `/mycelium`) ;
 *  - `usage_reference` (TASK-1439, V3 §9, MASTER Q67) : la UsageReference
 *    PUBLIEE de la surface demandee (« a quoi sert cet endroit ? »), texte
 *    cure plateforme, dans la locale de l'Organization ou celle de la
 *    plateforme — placee apres l'identite, avant les Constitutions ;
 *  - `organization.constitution_public` : la Constitution IA de l'Organization
 *    SEULEMENT si elle a decide de la publier (`ai_constitution_public`, meme
 *    regle que `/org/{slug}/constitution`).
 *
 * Interdit par construction (aucune requete, aucune relation) : Boucles et
 * leurs messages, Dossiers/RAG, People, memoire membre, CRM, doctrine interne,
 * autres visiteurs, credentials/economie/admin. Une Organization inactive ou
 * non publique n'a AUCUN contexte (null = fail-closed).
 */
final class GuestPublicContextBuilder
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly UsageReferenceResolver $references,
    ) {}

    /** @param  string  $surfaceKey  la surface dont la UsageReference est demandee — jamais une autre. */
    public function build(Organization $organization, string $surfaceKey = UsageReference::SURFACE_SHELL_WELCOME): ?GuestPublicContext
    {
        if (! $organization->is_active || ! $organization->is_public) {
            return null;
        }

        $definition = $this->capabilities->get(CapabilityRegistry::GUEST_SHELL_WELCOME);
        $budget = $definition->contextCharBudget;
        $blocks = [];
        $sources = [];
        $locale = (string) ($organization->locale ?: config('app.locale', 'fr'));

        $identity = $this->identity($organization);
        if ($identity !== '') {
            $blocks[] = ['source' => CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY, 'label' => __('guest_shell.context.identity', ['name' => $organization->name]), 'text' => $identity];
            $sources[] = CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY;
        }

        // « A quoi sert cette surface ? » — la version publiee de LA surface demandee, ou rien (fail-closed).
        $reference = $this->references->resolve($surfaceKey, $locale);
        if ($reference !== null && trim((string) $reference->content) !== '') {
            $blocks[] = ['source' => CapabilityRegistry::SOURCE_USAGE_REFERENCE, 'label' => __('guest_shell.context.usage_reference', ['title' => $reference->title]), 'text' => trim((string) $reference->content)];
            $sources[] = CapabilityRegistry::SOURCE_USAGE_REFERENCE;
        }

        $platform = trim(PlatformAiConstitution::activeTextOrSeed());
        if ($platform !== '') {
            $blocks[] = ['source' => CapabilityRegistry::SOURCE_PLATFORM_CONSTITUTION, 'label' => __('guest_shell.context.platform_constitution'), 'text' => $platform];
            $sources[] = CapabilityRegistry::SOURCE_PLATFORM_CONSTITUTION;
        }

        // Exactement la regle de /org/{slug}/constitution : opt-in explicite ET version active.
        if ($organization->ai_constitution_public) {
            $constitution = OrganizationAiConstitution::activeFor((string) $organization->id);
            if ($constitution !== null && trim((string) $constitution->body) !== '') {
                $blocks[] = ['source' => CapabilityRegistry::SOURCE_ORGANIZATION_CONSTITUTION_PUBLIC, 'label' => __('guest_shell.context.organization_constitution', ['name' => $organization->name]), 'text' => trim((string) $constitution->body)];
                $sources[] = CapabilityRegistry::SOURCE_ORGANIZATION_CONSTITUTION_PUBLIC;
            }
        }

        foreach ($sources as $source) {
            // Chaque source utilisee DOIT etre declaree par la capability : une source hors whitelist est une faute de code, pas une option.
            if (! $definition->allowsSource($source)) {
                throw new \LogicException("Source [{$source}] is not allowed for capability [".CapabilityRegistry::GUEST_SHELL_WELCOME.'].');
            }
        }

        $kept = $this->fit($blocks, $budget);

        return new GuestPublicContext(
            organizationId: (string) $organization->id,
            organizationName: (string) $organization->name,
            locale: $locale,
            blocks: $kept,
            sources: array_values(array_map(fn (array $block) => $block['source'], $kept)),
            charBudget: $budget,
        );
    }

    /** Ce que la landing publique montre deja a n'importe qui — rien de plus. */
    private function identity(Organization $organization): string
    {
        $lines = array_filter([
            __('guest_shell.context.name').' : '.trim((string) $organization->name),
            trim((string) $organization->platform_tagline) !== '' ? __('guest_shell.context.tagline').' : '.trim((string) $organization->platform_tagline) : null,
            trim((string) $organization->hero_title) !== '' ? __('guest_shell.context.headline').' : '.trim((string) $organization->hero_title) : null,
            trim((string) $organization->hero_description) !== '' ? __('guest_shell.context.pitch').' : '.trim((string) $organization->hero_description) : null,
            trim((string) $organization->description) !== '' ? __('guest_shell.context.description').' : '.trim((string) $organization->description) : null,
        ]);

        return implode("\n", $lines);
    }

    /**
     * Le budget de la capability est une borne DETERMINISTE (MASTER Q58) : les
     * blocs sont pris dans l'ordre de priorite (identite d'abord) et on
     * s'arrete au PREMIER bloc entier qui ne rentre plus — jamais de coupe au
     * milieu d'un texte, jamais une constitution au detriment de l'identite.
     *
     * @param  array<int, array{source: string, label: string, text: string}>  $blocks
     * @return array<int, array{source: string, label: string, text: string}>
     */
    private function fit(array $blocks, int $budget): array
    {
        $used = 0;
        $kept = [];

        foreach ($blocks as $block) {
            $length = mb_strlen($block['text']);
            if ($used + $length > $budget) {
                break;
            }
            $kept[] = $block;
            $used += $length;
        }

        return $kept;
    }
}
