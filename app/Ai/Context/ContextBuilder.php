<?php

namespace App\Ai\Context;

use App\Ai\CapabilityDefinition;
use App\Ai\ContexteIa;

/**
 * Context Builder minimal (TASK-1209 / IA P3).
 *
 * Repond a UNE question : « de quelles informations autorisees cette capability
 * a-t-elle besoin maintenant, pour cet utilisateur, dans cette Organization ? »
 *
 * Il selectionne, il compose, il borne. Il n'interroge aucun LLM pour choisir
 * son contexte, et n'approxime aucun compte de tokens par un appel externe : la
 * selection est deterministe et locale, donc reproductible en test.
 *
 * ## L'autorite, c'est la capability
 *
 * `CapabilityDefinition::$allowedSources` decide. Une source que la capability
 * ne declare pas n'est jamais interrogee — pas filtree apres coup, pas
 * interrogee du tout. C'est ce qui empeche une capability de gagner
 * silencieusement l'acces a des donnees parce qu'une source a ete ajoutee au
 * builder.
 *
 * ## Ce qu'il n'est pas
 *
 * Ni pipeline, ni systeme de plugins, ni bus d'evenements, ni repository
 * metier. Un tableau de sources indexe par nom, parcouru dans l'ordre declare
 * par la capability.
 */
final class ContextBuilder
{
    /** @var array<string, ContextSource> */
    private array $sources;

    public function __construct(
        LoopMessagesSource $loopMessages,
        UserLoopsSource $userLoops,
        OrganizationCategoriesSource $organizationCategories,
        DossierRetrievalSource $dossierRetrieval,
        DossierManifestSource $dossierManifest,
        BlogPostSource $blogPost,
        MemberProfileSource $memberProfile,
        ProductSurfacesSource $productSurfaces,
    ) {
        $this->sources = [
            $loopMessages->name() => $loopMessages,
            $userLoops->name() => $userLoops,
            $organizationCategories->name() => $organizationCategories,
            $dossierRetrieval->name() => $dossierRetrieval,
            $dossierManifest->name() => $dossierManifest,
            $blogPost->name() => $blogPost,
            $memberProfile->name() => $memberProfile,
            $productSurfaces->name() => $productSurfaces,
        ];
    }

    public function build(ContexteIa $contexte, CapabilityDefinition $capability): ContexteBorne
    {
        $budget = $capability->contextCharBudget;

        $fragments = [];
        $provenance = [];
        $used = [];
        $denied = [];
        $remaining = $budget;

        foreach ($capability->allowedSources as $name) {
            $source = $this->sources[$name] ?? null;

            // Une capability peut declarer une source que cette version du
            // builder ne sait pas encore produire : c'est un refus explicite,
            // pas un silence.
            if ($source === null) {
                $denied[$name] = 'source_not_implemented';

                continue;
            }

            if ($remaining <= 0) {
                $denied[$name] = 'char_budget_exhausted';

                continue;
            }

            try {
                $fragment = $source->collect($contexte, $remaining);
            } catch (SourceDenied $denial) {
                $denied[$name] = $denial->reason;

                continue;
            }

            if ($fragment->isEmpty()) {
                continue;
            }

            $fragments[] = $fragment->text;
            $provenance = [...$provenance, ...$fragment->provenance];
            $used[] = $name;
            $remaining -= mb_strlen($fragment->text);
        }

        return new ContexteBorne(
            text: implode("\n\n", $fragments),
            provenance: $provenance,
            charBudget: $budget,
            sourcesUsed: $used,
            sourcesDenied: $denied,
        );
    }

    /**
     * Sources que ce builder sait produire. Sert aux tests et au diagnostic,
     * jamais a contourner `allowedSources`.
     *
     * @return list<string>
     */
    public function availableSources(): array
    {
        return array_keys($this->sources);
    }
}
