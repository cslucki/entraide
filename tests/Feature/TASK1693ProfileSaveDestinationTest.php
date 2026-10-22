<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1693, bug A — « Enregistrer » sur son profil ramene a son profil.
 *
 * ## Le defaut, et pourquoi il etait deroutant
 *
 * `PUT /profile` ne porte QUE `auth` : aucune garde `verified` ne s'y applique.
 * Pourtant un compte non verifie qui cliquait « Enregistrer » atterrissait sur
 * `/verify-email`. La redirection ne venait pas de la route mais d'une INTENTION
 * ETRANGERE laissee en session :
 *
 *  1. la personne visite `/org/{slug}/dossiers` ;
 *  2. `EnsureEmailIsVerified` la detourne via `Redirect::guest()`, et `guest()`
 *     ECRIT `url.intended` avec l'URL courante ;
 *  3. `ProfileController::update()` faisait `redirect()->intended(...)`, qui
 *     CONSOMME cette clef et renvoie aux dossiers ;
 *  4. la garde `verified` s'y declenche de nouveau -> `/verify-email`.
 *
 * Le bug n'etait donc pas « le profil exige un email verifie » — il ne l'exige
 * pas — mais « le profil obeit a la derniere page refusee ailleurs ».
 */
class TASK1693ProfileSaveDestinationTest extends TestCase
{
    use RefreshDatabase;

    private function membre(array $overrides = []): User
    {
        $organization = Organization::factory()->create([
            'slug' => 'org-1693',
            'is_active' => true,
            'is_public' => true,
        ]);

        return User::factory()->complete()->create($overrides + [
            'organization_id' => $organization->id,
        ]);
    }

    /** @return array<string, mixed> le payload minimal accepte par le formulaire */
    private function payload(User $user): array
    {
        // `phone` est REQUIS par `ProfileController::update()`. Un payload
        // incomplet ferait echouer la validation, et le `redirect()->back()`
        // de Laravel renverrait alors vers la page precedente : le test
        // mesurerait la validation, pas la destination d'un enregistrement
        // REUSSI. Chaque test asserte donc aussi l'absence d'erreur.
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '+33600000000',
        ];
    }

    /**
     * TASK-1693 bug Y — la destination est desormais le PROFIL PUBLIC.
     *
     * Le bug A avait pose `profile.edit` : deja mieux que de suivre une
     * intention etrangere, mais cela rendait la personne au formulaire qu'elle
     * venait de quitter sans lui montrer le RESULTAT. On modifie son profil
     * public pour le voir change (arbitrage Cyril, 22/10).
     *
     * Ce que ces tests continuent de garantir est INCHANGE : la destination
     * reste DETERMINISTE et n'emprunte jamais `redirect()->intended()`. Seule
     * la cible bouge — c'est un changement de contrat produit, pas un
     * affaiblissement de la garde.
     */
    private function destinationAttendue(User $user): string
    {
        return route('organization.profile.show', [
            'organization' => $user->organization->slug,
            'user' => $user->id,
        ]);
    }

    /**
     * LE defaut, reproduit par son vrai declencheur.
     *
     * On ne POSE PAS `url.intended` a la main : on laisse la garde de
     * verification l'ecrire, comme en production. Un test qui poserait la clef
     * lui-meme prouverait que `intended()` fonctionne, pas que ce parcours
     * existe.
     */
    public function test_saving_the_profile_ignores_an_intention_left_by_the_email_verification_guard(): void
    {
        $user = $this->membre(['email_verified_at' => null]);
        $cible = route('organization.dossiers.index', ['organization' => $user->organization->slug]);

        // 1. Une page gardee refuse, et son refus ecrit `url.intended`.
        //
        // TASK-1693 bug E — le declencheur d'origine etait la garde de
        // VERIFICATION, retiree depuis sur decision produit. On passe donc par
        // l'autre middleware qui appelle `Redirect::guest()` : `auth`, face a un
        // visiteur anonyme. Le mecanisme mesure est rigoureusement le meme —
        // `guest()` ecrit `url.intended` —, et il est toujours vivant.
        $this->get($cible)->assertRedirect(route('organization.login', ['organization' => $user->organization->slug]));

        $this->assertSame(
            $cible,
            session('url.intended'),
            'premisse : la garde laisse bien une intention en session',
        );

        $this->actingAs($user);

        // 2. Enregistrer son profil ramene a SON PROFIL PUBLIC, pas a cette intention.
        $this->put(route('profile.update'), $this->payload($user))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->destinationAttendue($user))
            ->assertSessionHas('status', 'profile-updated');
    }

    /**
     * Le cas nominal, sans aucune intention en session : meme destination.
     *
     * Sans ce second test, le premier resterait vert si la destination devenait
     * dependante d'autre chose — il ne mesurerait que l'absence d'un effet.
     */
    public function test_saving_the_profile_always_returns_to_the_profile(): void
    {
        $user = $this->membre(['email_verified_at' => now()]);

        $this->assertNull(session('url.intended'), 'premisse : aucune intention en session');

        $this->actingAs($user)
            ->put(route('profile.update'), $this->payload($user))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->destinationAttendue($user))
            ->assertSessionHas('status', 'profile-updated');
    }

    /**
     * La garde `verified` n'est PAS ajoutee au profil au passage.
     *
     * Le correctif ne doit pas se transformer en durcissement : un compte non
     * verifie doit toujours pouvoir lire et modifier son propre profil, sans
     * quoi il ne pourrait meme pas corriger une adresse email mal saisie.
     */
    public function test_an_unverified_account_can_still_read_and_save_its_own_profile(): void
    {
        $user = $this->membre(['email_verified_at' => null]);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $this->put(route('profile.update'), $this->payload($user))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->destinationAttendue($user));

        $effective = app('router')->gatherRouteMiddleware(
            app('router')->getRoutes()->getByName('profile.update')
        );

        foreach ($effective as $middleware) {
            $this->assertStringNotContainsString(
                'EnsureEmailIsVerified',
                (string) $middleware,
                'le profil ne doit jamais exiger un email verifie',
            );
        }
    }

    /**
     * TASK-1693 bug Y — la destination ABOUTIT, et montre le changement.
     *
     * Asserter qu'une redirection pointe quelque part ne prouve pas que cette
     * page s'ouvre — c'est la lecon du bug R, ou un lien pose menait a un 404.
     * `ProfileController::show()` exige `currentOrganization()` et rend 404
     * sinon : il faut donc SUIVRE la redirection, pas seulement la lire.
     */
    public function test_the_destination_actually_opens_and_shows_the_change(): void
    {
        $user = $this->membre(['email_verified_at' => now()]);

        $reponse = $this->actingAs($user)
            ->put(route('profile.update'), $this->payload($user) + [
                'bio' => 'Ma nouvelle presentation publique.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->destinationAttendue($user));

        $html = $this->followRedirects($reponse)->assertOk()->getContent();

        $this->assertStringContainsString(
            e('Ma nouvelle presentation publique.'),
            $html,
            'on modifie son profil public pour le voir change : la page doit porter la modification',
        );
    }

    /**
     * Ce n'est PAS le formulaire d'edition.
     *
     * Sans cette mesure, une destination qui retomberait sur `profile.edit`
     * — par un repli, une redirection canonique mal posee — resterait verte
     * pour les tests ci-dessus, qui comparent des URL.
     */
    public function test_the_destination_is_not_the_edit_form(): void
    {
        $user = $this->membre(['email_verified_at' => now()]);

        $cible = $this->actingAs($user)
            ->put(route('profile.update'), $this->payload($user))
            ->headers->get('Location');

        $this->assertStringNotContainsString('/profile/edit', (string) $cible);
        $this->assertStringContainsString('/profile/'.$user->id, (string) $cible);
    }

    /**
     * L'Organization est nommee EXPLICITEMENT dans la destination.
     *
     * `show()` rend 404 sans `currentOrganization()`. S'en remettre au seul
     * repli canonique de TASK-1601 ferait dependre l'aboutissement d'un second
     * saut — et un saut de plus est un endroit de plus ou se perdre.
     */
    public function test_the_destination_carries_the_organization_prefix(): void
    {
        $user = $this->membre(['email_verified_at' => now()]);

        $cible = $this->actingAs($user)
            ->put(route('profile.update'), $this->payload($user))
            ->headers->get('Location');

        $this->assertStringContainsString('/org/'.$user->organization->slug.'/', (string) $cible);
    }
}
