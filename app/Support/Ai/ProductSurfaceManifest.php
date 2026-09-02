<?php

namespace App\Support\Ai;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1370 — CE QUI EXISTE, POUR CE MEMBRE, DANS CETTE ORGANISATION.
 *
 * ## Le defaut que ce manifest ferme
 *
 * Un membre a demande « How do I change my notification settings ? ». Le Shell a
 * repondu qu'il fallait « typiquement » aller dans son profil ou les reglages du
 * compte. **BouclePro n'a aucun reglage de notifications** : la seule route qui
 * porte ce mot est le renvoi d'e-mail de verification.
 *
 * Le prompt actif interdisait pourtant deja d'inventer — « pas d'information sur
 * BouclePro qui ne t'a pas ete fournie ». Le modele a desobei a une instruction
 * qu'il avait. La reponse n'est donc pas de repeter la regle : c'est de lui
 * RETIRER L'AUTORITE de decider ce qui existe. Ce manifest est cette autorite.
 *
 * ## Registre CURE, jamais un inventaire de routes
 *
 * Les entrees sont ecrites a la main. Deverser `Route::getRoutes()` produirait un
 * catalogue de chemins techniques — `password.request`, `bug-reports.index` — que
 * personne n'appelle ainsi, et exposerait au modele des surfaces qui ne sont pas
 * des reponses a « que puis-je faire ». Un registre se decide ; il ne se deduit
 * pas.
 *
 * ## Trois filtres, et l'absence est la reponse
 *
 * 1. **La route existe-t-elle vraiment ?** `Route::has()`. Une entree qui
 *    survivrait a la suppression de sa page ferait exactement ce qu'on repare.
 * 2. **La fonctionnalite est-elle ouverte a CETTE organisation ?** Les memes
 *    drapeaux que les surfaces elles-memes, jamais une seconde verite.
 * 3. **Ce lecteur-la y a-t-il acces ?** Une page d'administration n'est pas
 *    « indisponible » pour un membre : elle est ABSENTE. Dire « tu n'y as pas
 *    droit » revient encore a affirmer son existence.
 *
 * ## Ce qui sort d'ici
 *
 * Une cle stable et un libelle traduit. **Jamais d'URL, de chemin, de nom de
 * route ni d'identifiant** : le modele ecrit de la prose, il ne fabrique pas de
 * navigation. Emmener quelqu'un quelque part reste le domaine d'`AiFabContext`,
 * qui rend des adresses construites cote serveur.
 *
 * ## Pourquoi la classe n'est PAS `final`
 *
 * Meme raison qu'`AiCapabilityCatalogue` : elle ne protege aucun invariant, et
 * le comportement qui compte le plus — **un manifest VIDE** — doit pouvoir etre
 * eprouve. Or aucune organisation reelle ne le produit : l'annuaire, la
 * messagerie et les dossiers ne dependent d'aucun drapeau. Sans substitution,
 * le cas « rien a affirmer » resterait une intention non testee, c'est-a-dire
 * exactement le genre de garde dont on croit qu'elle marche.
 *
 * Ce n'est pas non plus un mode d'emploi : le manifest dit CE QUI EXISTE, pas
 * COMMENT s'en servir. Un referentiel d'usage est une decision d'architecture
 * distincte, hors de cette TASK.
 */
class ProductSurfaceManifest
{
    /**
     * Le registre CURE. L'ordre est celui de la reponse : ce qu'on fait
     * d'abord, puis ou l'on va, puis ce qui gouverne.
     *
     * @return list<array{key: string, route: string, label: string, flag: ?callable(Organization): bool, viewer: ?callable(Organization, User): bool}>
     */
    private function definitions(): array
    {
        return [
            [
                'key' => 'loops',
                'route' => 'organization.loops.index',
                'label' => 'ai.surface_loops',
                'flag' => fn (Organization $o): bool => (bool) $o->loops_enabled,
                'viewer' => null,
            ],
            [
                'key' => 'create_loop',
                'route' => 'organization.loops.create',
                'label' => 'ai.surface_create_loop',
                'flag' => fn (Organization $o): bool => $o->membersCanCreateLoops(),
                'viewer' => null,
            ],
            [
                'key' => 'members_directory',
                'route' => 'organization.members.index',
                'label' => 'ai.surface_members_directory',
                'flag' => null,
                'viewer' => null,
            ],
            [
                'key' => 'exchanges',
                'route' => 'organization.exchanges.index',
                'label' => 'ai.surface_exchanges',
                'flag' => null,
                'viewer' => null,
            ],
            [
                'key' => 'messages',
                'route' => 'organization.messages.index',
                'label' => 'ai.surface_messages',
                'flag' => null,
                'viewer' => null,
            ],
            [
                'key' => 'dossiers',
                'route' => 'organization.dossiers.index',
                'label' => 'ai.surface_dossiers',
                'flag' => null,
                'viewer' => null,
            ],
            [
                'key' => 'agenda',
                'route' => 'organization.events.agenda',
                'label' => 'ai.surface_agenda',
                'flag' => null,
                'viewer' => null,
            ],
            [
                'key' => 'ai_profile',
                'route' => 'organization.agent-ia.index',
                'label' => 'ai.surface_ai_profile',
                'flag' => fn (Organization $o): bool => (bool) $o->ai_profiles_enabled,
                'viewer' => null,
            ],
            [
                'key' => 'subscriptions',
                'route' => 'organization.subscriptions',
                'label' => 'ai.surface_subscriptions',
                'flag' => fn (Organization $o): bool => (bool) $o->subscriptions_enabled,
                'viewer' => null,
            ],
            [
                // La seule surface d'administration du registre, et elle est la
                // pour une raison : c'est elle qui prouve que le filtre lecteur
                // RETIRE vraiment une entree au lieu de la marquer indisponible.
                'key' => 'organization_admin',
                'route' => 'organization.admin.dashboard',
                'label' => 'ai.surface_organization_admin',
                'flag' => null,
                'viewer' => fn (Organization $o, User $u): bool => (bool) $u->is_admin
                    || (string) $o->admin_id === (string) $u->getKey(),
            ],
        ];
    }

    /**
     * Les surfaces qui existent REELLEMENT pour ce lecteur, dans cette
     * organisation.
     *
     * @return list<array{key: string, label: string}>
     */
    public function forViewer(Organization $organization, User $user): array
    {
        $surfaces = [];

        foreach ($this->definitions() as $definition) {
            if (! Route::has($definition['route'])) {
                continue;
            }

            if ($definition['flag'] !== null && ! ($definition['flag'])($organization)) {
                continue;
            }

            if ($definition['viewer'] !== null && ! ($definition['viewer'])($organization, $user)) {
                continue;
            }

            $surfaces[] = [
                'key' => $definition['key'],
                'label' => __($definition['label']),
            ];
        }

        return $surfaces;
    }
}
