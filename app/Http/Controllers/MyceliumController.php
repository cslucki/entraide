<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationAiConstitution;
use App\Models\PlatformAiConstitution;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * TASK-1349 — la gouvernance IA, rendue publique.
 *
 * ## Mycelium n'est pas une couche de plus
 *
 * « Mycelium BouclePro » est le NOM PUBLIC de la Constitution plateforme de
 * TASK-1348. Aucune table, aucun modele, aucune autorite nouvelle : cet ecran
 * lit `PlatformAiConstitution` et rien d'autre. Si le texte servi au modele
 * change, la page publique change avec lui — c'est tout l'interet.
 *
 * ## Ce qui est publie, et pourquoi si peu
 *
 * Le nom, la version, la date d'activation, le texte actif, et l'explication de
 * l'heritage. Rien de plus. Sont volontairement ABSENTS : la doctrine (une
 * preference metier, pas un principe), les prompts de capability, l'auteur des
 * versions, l'historique, les interactions, le bac a sable, la configuration
 * IA. Publier des principes de gouvernance n'est pas publier des donnees
 * d'exploitation, et la difference se lit dans ce que cette classe ne lit pas.
 *
 * ## Le prive reste prive, par defaut
 *
 * La Constitution d'une organisation n'est publique que si quelqu'un l'a
 * EXPLICITEMENT decide (`ai_constitution_public`, defaut `false`). Une
 * organisation qui n'a rien choisi n'apparait ni dans l'arbre, ni a son URL.
 */
class MyceliumController extends Controller
{
    /** Le hub public : la racine, l'heritage, et les organisations qui ont choisi de publier. */
    public function index(): View
    {
        $platform = PlatformAiConstitution::active();

        return view('mycelium.index', [
            'platform' => $platform,
            // Le texte REELLEMENT compose, jamais une copie d'ecran : la page
            // publique montre ce que le systeme utilise, pas ce qu'il a un jour
            // utilise.
            'platformText' => PlatformAiConstitution::activeTextOrSeed(),
            'platformVersion' => $platform?->version,
            'platformActivatedAt' => $platform?->activated_at,
            'organizations' => $this->publicOrganizations(),
        ]);
    }

    /** La Constitution publiee d'UNE organisation. */
    public function organization(Organization $organization): View
    {
        // Deux conditions, et le meme resultat quand l'une manque : 404.
        //
        // Un 403 dirait « il y a quelque chose ici, mais ce n'est pas pour
        // vous » — ce serait deja divulguer qu'une organisation a une
        // Constitution privee. Un 404 ne dit rien du tout, ce qui est la seule
        // reponse honnete pour une ressource qui, publiquement, n'existe pas.
        if (! $organization->ai_constitution_public || ! $organization->is_active) {
            throw new NotFoundHttpException;
        }

        $constitution = OrganizationAiConstitution::activeFor((string) $organization->id);

        // Opt-in coche mais aucune version active : il n'y a rien a publier.
        // Afficher une page vide serait promettre un contenu inexistant.
        if ($constitution === null) {
            throw new NotFoundHttpException;
        }

        // Volontairement SANS le texte de la racine : la page d'une
        // organisation RENVOIE au Mycelium, elle ne le recopie pas. Deux copies
        // d'un meme texte finiraient par diverger, et le lecteur ne saurait
        // plus laquelle fait foi.
        return view('mycelium.organization', [
            'organization' => $organization,
            'constitution' => $constitution,
        ]);
    }

    /**
     * Les organisations reellement publiables, et elles seules.
     *
     * Trois conditions cumulatives : l'organisation est active, elle a coche
     * l'opt-in, et elle a une Constitution ACTIVE. La troisieme evite de lister
     * un noeud qui menerait a un 404 — un arbre dont les branches cassent n'est
     * pas une visualisation, c'est un piege.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function publicOrganizations(): Collection
    {
        return Organization::query()
            ->where('is_active', true)
            ->where('ai_constitution_public', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(function (Organization $organization): ?array {
                $constitution = OrganizationAiConstitution::activeFor((string) $organization->id);

                return $constitution === null ? null : [
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'version' => $constitution->version,
                    'activated_at' => $constitution->activated_at,
                    // TASK-1349 : le corps voyage avec le noeud pour que le hub
                    // se lise SANS changer de page. Exactement ce que la page
                    // dediee montre deja — ni auteur, ni historique, ni
                    // doctrine — donc aucune exposition nouvelle.
                    'body' => $constitution->body,
                ];
            })
            ->filter()
            ->values();
    }
}
